<?php

namespace Tests\Feature;

use App\Jobs\SignESignatureRequestPdfJob;
use App\Models\Department;
use App\Models\ESignatureSetting;
use App\Models\EsignatureSigning;
use App\Models\HRAuditTrail;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Covers the two-step "Leave Credit Certification" flow (LeaveCertificationController,
 * LeaveRequestService::pendingCertificationQuery()/pendingCertificationLeaves()/
 * pendingReviewQuery()/forwardedForSigningQuery()/rejectedCertificationQuery()/
 * rejectCertification()/forwardCertifications()/reopenCertification()/
 * batchCertifyPendingLeaves()/certifyLeaveCredits()): the Leave Manager reviews the
 * pending queue (reject with a reason, or forward - no password), and only the HR
 * Manager signs what's been forwarded, always with their own saved certificate.
 * Mirrors LeaveApprovalEsignatureTest's conventions - SignESignatureRequestPdfJob is
 * queue-faked throughout, only exercising the real buildEsignaturePdfBytes() render in
 * one happy-path test.
 */
class LeaveCertificationBatchSignTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeThrowawayPkcs12(string $password): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Test Signer'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);
        openssl_pkcs12_export($cert, $pkcs12, $key, $password);

        return $pkcs12;
    }

    private function createEsignatureSetting($user, string $password): ESignatureSetting
    {
        Storage::fake('esignature');

        $dir = (string) $user->id;
        $certificateBytes = $this->makeThrowawayPkcs12($password);

        Storage::disk('esignature')->put("{$dir}/signature.png", 'fake-png-bytes');
        Storage::disk('esignature')->put("{$dir}/certificate.enc", Crypt::encryptString($certificateBytes));
        Storage::disk('esignature')->put("{$dir}/root_ca", 'fake-root-ca-bytes');
        Storage::disk('esignature')->put("{$dir}/intermediate_0", 'fake-intermediate-bytes');

        return ESignatureSetting::create([
            'user_id' => $user->id,
            'signature_path' => "{$dir}/signature.png",
            'certificate_path' => "{$dir}/certificate.enc",
            'root_ca_path' => "{$dir}/root_ca",
            'intermediate_paths' => ["{$dir}/intermediate_0"],
            'include_name' => true,
            'include_date' => true,
        ]);
    }

    private function createEsignatureLeave($employee, array $overrides = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Leave certification test',
            'status' => 'pending',
            'printing_allowed' => true,
            'esignature_requested_at' => now(),
        ], $overrides));
    }

    /**
     * Marks a leave as already forwarded by the Leave Manager, for tests whose
     * subject is the HR Manager's sign step rather than the forward step itself.
     */
    private function forwardLeave(LeaveRequest $leave, ?User $reviewer = null): LeaveRequest
    {
        $leave->update([
            'certification_review_status' => 'forwarded',
            'certification_reviewed_by' => $reviewer?->id,
            'certification_reviewed_at' => now(),
        ]);

        return $leave->fresh();
    }

    private function leaveRequestService(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    // ── Pending-query derivation (base eligibility, unaffected by review status) ──

    public function test_pending_query_excludes_leaves_not_filed_with_esignature_intent(): void
    {
        $employee = $this->createEmployee();
        $this->createEsignatureLeave($employee, ['esignature_requested_at' => null]);

        $pending = $this->leaveRequestService()->pendingCertificationLeaves();

        $this->assertCount(0, $pending);
    }

    public function test_pending_query_excludes_leaves_not_in_pending_status(): void
    {
        $employee = $this->createEmployee();
        foreach (['approved', 'cancelled', 'declined', 'disapproved'] as $status) {
            $this->createEsignatureLeave($employee, ['status' => $status]);
        }

        $pending = $this->leaveRequestService()->pendingCertificationLeaves();

        $this->assertCount(0, $pending);
    }

    public function test_pending_query_excludes_leaves_with_a_completed_certifying_signature(): void
    {
        $employee = $this->createEmployee();
        $leave = $this->createEsignatureLeave($employee);

        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'field_name' => 'CertifyingSignature',
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/already-certified/unsigned.pdf',
        ]);

        $pending = $this->leaveRequestService()->pendingCertificationLeaves();

        $this->assertCount(0, $pending);
    }

    public function test_pending_query_excludes_leaves_with_a_signing_currently_in_flight(): void
    {
        $employee = $this->createEmployee();
        $leave = $this->createEsignatureLeave($employee);

        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'field_name' => 'CertifyingSignature',
            'status' => EsignatureSigning::STATUS_PROCESSING,
            'unsigned_path' => 'signings/in-flight/unsigned.pdf',
        ]);

        $pending = $this->leaveRequestService()->pendingCertificationLeaves();

        $this->assertCount(0, $pending);
    }

    public function test_pending_query_includes_a_leave_whose_only_prior_attempt_failed(): void
    {
        $employee = $this->createEmployee();
        $leave = $this->createEsignatureLeave($employee);

        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'field_name' => 'CertifyingSignature',
            'status' => EsignatureSigning::STATUS_FAILED,
            'unsigned_path' => 'signings/failed-attempt/unsigned.pdf',
            'error_message' => 'TSA unreachable',
        ]);

        $pending = $this->leaveRequestService()->pendingCertificationLeaves();

        $this->assertCount(1, $pending);
        $this->assertTrue($pending->first()->is($leave));
    }

    public function test_pending_query_is_unaffected_by_an_unrelated_approver_signing(): void
    {
        $employee = $this->createEmployee();
        $leave = $this->createEsignatureLeave($employee);

        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'field_name' => 'ApproverSignature',
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/approver/unsigned.pdf',
        ]);

        $pending = $this->leaveRequestService()->pendingCertificationLeaves();

        $this->assertCount(1, $pending);
    }

    // ── Review-status scoping (pendingReviewQuery/forwardedForSigningQuery/rejectedCertificationQuery) ──

    public function test_pending_review_query_excludes_forwarded_and_rejected_leaves(): void
    {
        $employee = $this->createEmployee();
        $unreviewed = $this->createEsignatureLeave($employee);
        $forwarded = $this->forwardLeave($this->createEsignatureLeave($employee));
        $rejected = $this->createEsignatureLeave($employee);
        $rejected->update(['certification_review_status' => 'rejected']);

        $ids = $this->leaveRequestService()->pendingReviewQuery()->pluck('id');

        $this->assertTrue($ids->contains($unreviewed->id));
        $this->assertFalse($ids->contains($forwarded->id));
        $this->assertFalse($ids->contains($rejected->id));
    }

    public function test_forwarded_for_signing_query_only_includes_forwarded_leaves(): void
    {
        $employee = $this->createEmployee();
        $unreviewed = $this->createEsignatureLeave($employee);
        $forwarded = $this->forwardLeave($this->createEsignatureLeave($employee));

        $ids = $this->leaveRequestService()->forwardedForSigningQuery()->pluck('id');

        $this->assertTrue($ids->contains($forwarded->id));
        $this->assertFalse($ids->contains($unreviewed->id));
    }

    public function test_rejected_certification_query_only_includes_rejected_leaves(): void
    {
        $employee = $this->createEmployee();
        $unreviewed = $this->createEsignatureLeave($employee);
        $rejected = $this->createEsignatureLeave($employee);
        $rejected->update(['certification_review_status' => 'rejected']);

        $ids = $this->leaveRequestService()->rejectedCertificationQuery()->pluck('id');

        $this->assertTrue($ids->contains($rejected->id));
        $this->assertFalse($ids->contains($unreviewed->id));
    }

    // ── Access control ────────────────────────────────────────────────

    public function test_hr_manager_can_view_the_queue(): void
    {
        $hr = $this->createHRManager();

        $this->actingAs($hr)->get(route('leave-certification.index'))->assertOk();
    }

    public function test_leave_manager_can_view_the_queue(): void
    {
        $lm = $this->createLeaveManager();

        $this->actingAs($lm)->get(route('leave-certification.index'))->assertOk();
    }

    public function test_employee_cannot_view_the_queue(): void
    {
        $employee = $this->createEmployee();

        $this->actingAs($employee)->get(route('leave-certification.index'))->assertForbidden();
    }

    public function test_department_head_cannot_view_the_queue(): void
    {
        $dh = $this->createDepartmentHead();

        $this->actingAs($dh)->get(route('leave-certification.index'))->assertForbidden();
    }

    // ── Filters & pagination (index view) ───────────────────────────────

    public function test_index_filters_pending_by_department(): void
    {
        $hr = $this->createHRManager();

        $deptA = Department::forceCreate([
            'DeptCode' => 'DEPT-A', 'Dept_name' => 'Department A', 'EmpNo' => 'DEPTA-HEAD', 'Designation' => 'Head',
        ]);
        $deptB = Department::forceCreate([
            'DeptCode' => 'DEPT-B', 'Dept_name' => 'Department B', 'EmpNo' => 'DEPTB-HEAD', 'Designation' => 'Head',
        ]);

        $employeeA = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'first_name' => 'Alpha', 'last_name' => 'Aardvark']);
        $employeeB = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'first_name' => 'Bravo', 'last_name' => 'Badger']);
        $this->forwardLeave($this->createEsignatureLeave($employeeA));
        $this->forwardLeave($this->createEsignatureLeave($employeeB));

        $response = $this->actingAs($hr)->get(route('leave-certification.index', ['department' => $deptA->Dept_id]));

        $response->assertOk();
        $response->assertSeeText('Alpha Aardvark');
        $response->assertDontSeeText('Bravo Badger');
    }

    public function test_index_filters_pending_by_search(): void
    {
        $lm = $this->createLeaveManager();

        $employeeA = $this->createEmployee(['first_name' => 'Findable', 'last_name' => 'Person']);
        $employeeB = $this->createEmployee(['first_name' => 'Other', 'last_name' => 'Employee']);
        $this->createEsignatureLeave($employeeA);
        $this->createEsignatureLeave($employeeB);

        $response = $this->actingAs($lm)->get(route('leave-certification.index', ['search' => 'Findable']));

        $response->assertOk();
        $response->assertSeeText('Findable Person');
        $response->assertDontSeeText('Other Employee');
    }

    public function test_index_paginates_the_pending_review_list(): void
    {
        $lm = $this->createLeaveManager();

        foreach (range(1, 16) as $i) {
            $this->createEsignatureLeave($this->createEmployee());
        }

        $pageOne = $this->leaveRequestService()->paginatedPendingCertificationLeaves([], 15);
        $this->assertCount(15, $pageOne->items());
        $this->assertSame(16, $pageOne->total());
        $this->assertTrue($pageOne->hasPages());

        $response = $this->actingAs($lm)->get(route('leave-certification.index', ['pending_page' => 2]));
        $response->assertOk();
    }

    // ── Leave Manager review: reject ─────────────────────────────────────

    public function test_leave_manager_can_reject_a_pending_leave_with_a_reason(): void
    {
        $lm = $this->createLeaveManager();
        $leave = $this->createEsignatureLeave($this->createEmployee());

        $response = $this->actingAs($lm)->postJson(
            route('leave-certification.reject', $leave->id),
            ['remarks' => 'Balance figures look wrong, please recheck.']
        );

        $response->assertOk()->assertJsonFragment(['success' => true]);

        $leave->refresh();
        $this->assertSame('rejected', $leave->certification_review_status);
        $this->assertSame($lm->id, $leave->certification_reviewed_by);
        $this->assertSame('Balance figures look wrong, please recheck.', $leave->certification_review_remarks);
        $this->assertNotNull($leave->certification_reviewed_at);

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $lm->id,
            'module' => 'esignature',
            'action' => 'leave_certification_rejected',
            'target_type' => LeaveRequest::class,
            'target_id' => $leave->id,
        ]);
    }

    public function test_reject_requires_a_remarks_reason(): void
    {
        $lm = $this->createLeaveManager();
        $leave = $this->createEsignatureLeave($this->createEmployee());

        $response = $this->actingAs($lm)->postJson(route('leave-certification.reject', $leave->id), []);

        $response->assertStatus(422);
        $this->assertNull($leave->fresh()->certification_review_status);
    }

    public function test_hr_manager_cannot_reject(): void
    {
        $hr = $this->createHRManager();
        $leave = $this->createEsignatureLeave($this->createEmployee());

        $this->actingAs($hr)->postJson(
            route('leave-certification.reject', $leave->id),
            ['remarks' => 'Nope']
        )->assertForbidden();

        $this->assertNull($leave->fresh()->certification_review_status);
    }

    public function test_cannot_reject_a_leave_already_forwarded(): void
    {
        $lm = $this->createLeaveManager();
        $leave = $this->forwardLeave($this->createEsignatureLeave($this->createEmployee()), $lm);

        $response = $this->actingAs($lm)->postJson(
            route('leave-certification.reject', $leave->id),
            ['remarks' => 'Too late']
        );

        $response->assertStatus(422);
        $this->assertSame('forwarded', $leave->fresh()->certification_review_status);
    }

    // ── Leave Manager review: forward ────────────────────────────────────

    public function test_leave_manager_can_forward_selected_leaves(): void
    {
        $lm = $this->createLeaveManager();
        $leaveA = $this->createEsignatureLeave($this->createEmployee());
        $leaveB = $this->createEsignatureLeave($this->createEmployee());

        $response = $this->actingAs($lm)->postJson(
            route('leave-certification.forward'),
            ['leave_ids' => [$leaveA->id]]
        );

        $response->assertOk()->assertJsonFragment(['processed_count' => 1]);

        $leaveA->refresh();
        $this->assertSame('forwarded', $leaveA->certification_review_status);
        $this->assertSame($lm->id, $leaveA->certification_reviewed_by);
        $this->assertNull($leaveA->certification_review_remarks);

        // The unselected leave is untouched, still in the Leave Manager's own queue.
        $this->assertNull($leaveB->fresh()->certification_review_status);

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $lm->id,
            'module' => 'esignature',
            'action' => 'leave_certification_forwarded',
            'target_type' => 'leave_certification_batch',
        ]);
    }

    public function test_forward_with_null_leave_ids_forwards_everything_pending_review(): void
    {
        $lm = $this->createLeaveManager();
        $leaveA = $this->createEsignatureLeave($this->createEmployee());
        $leaveB = $this->createEsignatureLeave($this->createEmployee());

        $response = $this->actingAs($lm)->postJson(route('leave-certification.forward'), []);

        $response->assertOk()->assertJsonFragment(['processed_count' => 2]);
        $this->assertSame('forwarded', $leaveA->fresh()->certification_review_status);
        $this->assertSame('forwarded', $leaveB->fresh()->certification_review_status);
    }

    public function test_forward_silently_ignores_an_id_already_rejected(): void
    {
        $lm = $this->createLeaveManager();
        $rejected = $this->createEsignatureLeave($this->createEmployee());
        $rejected->update(['certification_review_status' => 'rejected']);

        $response = $this->actingAs($lm)->postJson(
            route('leave-certification.forward'),
            ['leave_ids' => [$rejected->id]]
        );

        $response->assertOk()->assertJsonFragment(['processed_count' => 0]);
        $this->assertSame('rejected', $rejected->fresh()->certification_review_status);
    }

    public function test_hr_manager_cannot_forward(): void
    {
        $hr = $this->createHRManager();
        $leave = $this->createEsignatureLeave($this->createEmployee());

        $this->actingAs($hr)->postJson(route('leave-certification.forward'), [])->assertForbidden();

        $this->assertNull($leave->fresh()->certification_review_status);
    }

    // ── Reopen (either role) ──────────────────────────────────────────────

    public function test_leave_manager_can_reopen_a_rejected_leave(): void
    {
        $lm = $this->createLeaveManager();
        $leave = $this->createEsignatureLeave($this->createEmployee());
        $leave->update([
            'certification_review_status' => 'rejected',
            'certification_reviewed_by' => $lm->id,
            'certification_reviewed_at' => now(),
            'certification_review_remarks' => 'Fix the numbers',
        ]);

        $response = $this->actingAs($lm)->postJson(route('leave-certification.reopen', $leave->id));

        $response->assertOk()->assertJsonFragment(['success' => true]);

        $leave->refresh();
        $this->assertNull($leave->certification_review_status);
        $this->assertNull($leave->certification_reviewed_by);
        $this->assertNull($leave->certification_reviewed_at);
        $this->assertNull($leave->certification_review_remarks);

        $this->assertTrue($this->leaveRequestService()->pendingReviewQuery()->whereKey($leave->id)->exists());

        $auditRow = HRAuditTrail::where('action', 'leave_certification_reopened')->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('Fix the numbers', $auditRow->details['previous_remarks']);
    }

    public function test_hr_manager_can_also_reopen_a_rejected_leave(): void
    {
        $hr = $this->createHRManager();
        $leave = $this->createEsignatureLeave($this->createEmployee());
        $leave->update(['certification_review_status' => 'rejected']);

        $this->actingAs($hr)->postJson(route('leave-certification.reopen', $leave->id))
            ->assertOk()->assertJsonFragment(['success' => true]);

        $this->assertNull($leave->fresh()->certification_review_status);
    }

    public function test_cannot_reopen_a_leave_that_is_not_rejected(): void
    {
        $lm = $this->createLeaveManager();
        $leave = $this->createEsignatureLeave($this->createEmployee());

        $response = $this->actingAs($lm)->postJson(route('leave-certification.reopen', $leave->id));

        $response->assertStatus(422);
    }

    // ── batchSign() access control ───────────────────────────────────────

    public function test_leave_manager_cannot_trigger_batch_sign(): void
    {
        Queue::fake();

        $lm = $this->createLeaveManager();
        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $this->forwardLeave($this->createEsignatureLeave($employee), $lm);
        $this->createEsignatureSetting($hr, 'hr-password');

        $this->actingAs($lm)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'hr-password']
        )->assertForbidden();

        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_hr_manager_without_own_esignature_setting_cannot_sign(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $this->forwardLeave($this->createEsignatureLeave($employee));

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'irrelevant']
        );

        $response->assertStatus(422)->assertJsonFragment(['message' => 'You have not set up an e-signature yet.']);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    // ── batchSign() ───────────────────────────────────────────────────

    public function test_batch_sign_rejects_wrong_password_without_creating_any_signing_rows(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $this->forwardLeave($this->createEsignatureLeave($employee));
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'wrong-password']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_batch_sign_returns_success_with_nothing_to_sign_when_queue_is_empty(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 0]);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_batch_sign_only_signs_forwarded_leaves_not_merely_pending_ones(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $notYetReviewed = $this->createEsignatureLeave($employee);
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password', 'leave_ids' => [$notYetReviewed->id]]
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 0]);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_happy_path_signs_a_fresh_pdf_for_each_forwarded_leave(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employeeA = $this->createEmployee();
        $employeeB = $this->createEmployee();
        $leaveA = $this->forwardLeave($this->createEsignatureLeave($employeeA));
        $leaveB = $this->forwardLeave($this->createEsignatureLeave($employeeB));
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 2]);
        $this->assertDatabaseCount('esignature_signings', 2);

        foreach ([$leaveA, $leaveB] as $leave) {
            $signing = EsignatureSigning::where('signable_id', $leave->id)->first();
            $this->assertNotNull($signing);
            $this->assertSame($hr->id, $signing->requested_by);
            $this->assertSame('CertifyingSignature', $signing->field_name);
            $this->assertSame(EsignatureSigning::STATUS_PENDING, $signing->status);
            $this->assertStringStartsWith('%PDF', Storage::disk('esignature')->get($signing->unsigned_path));
        }

        Queue::assertPushed(SignESignatureRequestPdfJob::class, 2);
    }

    public function test_leave_ids_restricts_signing_to_the_selected_leaves_only(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employeeA = $this->createEmployee();
        $employeeB = $this->createEmployee();
        $leaveA = $this->forwardLeave($this->createEsignatureLeave($employeeA));
        $leaveB = $this->forwardLeave($this->createEsignatureLeave($employeeB));
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password', 'leave_ids' => [$leaveA->id]]
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 1]);
        $this->assertDatabaseCount('esignature_signings', 1);
        $this->assertNotNull(EsignatureSigning::where('signable_id', $leaveA->id)->first());
        $this->assertNull(EsignatureSigning::where('signable_id', $leaveB->id)->first());

        // The unselected leave is still on the queue for a later run.
        $this->assertTrue($this->leaveRequestService()->forwardedForSigningQuery()->get()->contains(
            fn ($l) => $l->is($leaveB)
        ));
    }

    public function test_leave_ids_silently_ignores_an_id_outside_the_real_sign_queue(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $leave = $this->forwardLeave($this->createEsignatureLeave($employee));
        $this->createEsignatureSetting($hr, 'correct-password');

        // Not filed with e-signature intent - never eligible, regardless of selection.
        $ineligibleLeave = $this->createEsignatureLeave($this->createEmployee(), ['esignature_requested_at' => null]);

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password', 'leave_ids' => [$leave->id, $ineligibleLeave->id, 999999]]
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 1]);
        $this->assertDatabaseCount('esignature_signings', 1);
        $this->assertNotNull(EsignatureSigning::where('signable_id', $leave->id)->first());
    }

    public function test_empty_leave_ids_selection_signs_nothing(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $this->forwardLeave($this->createEsignatureLeave($employee));
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password', 'leave_ids' => []]
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 0]);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_happy_path_cosigns_on_top_of_an_existing_completed_signing(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $leave = $this->forwardLeave($this->createEsignatureLeave($employee));
        $this->createEsignatureSetting($hr, 'correct-password');

        Storage::disk('esignature')->put('signings/applicant-tok/signed.pdf', '%PDF-1.4 already-signed-by-applicant');
        $priorSigning = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/applicant-tok/unsigned.pdf',
            'signed_path' => 'signings/applicant-tok/signed.pdf',
        ]);

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 1]);

        $newSigning = EsignatureSigning::where('id', '!=', $priorSigning->id)->first();
        $this->assertNotNull($newSigning);
        $this->assertSame($hr->id, $newSigning->requested_by);
        $this->assertSame('CertifyingSignature', $newSigning->field_name);
        $this->assertSame(
            Storage::disk('esignature')->get($priorSigning->signed_path),
            Storage::disk('esignature')->get($newSigning->unsigned_path),
            'The certification pass must start from the already-signed PDF, not a fresh render.'
        );

        Queue::assertPushed(SignESignatureRequestPdfJob::class, fn ($job) => $job->signing->is($newSigning));
    }

    public function test_hr_manager_triggers_and_signs_with_their_own_password(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $leave = $this->forwardLeave($this->createEsignatureLeave($employee));
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('leave-certification.batch-sign'),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonFragment(['processed_count' => 1]);

        $signing = EsignatureSigning::where('signable_id', $leave->id)->first();
        $this->assertNotNull($signing);
        $this->assertSame($hr->id, $signing->requested_by);

        $auditRow = HRAuditTrail::where('actor_user_id', $hr->id)
            ->where('action', 'leave_certification_batch_triggered')
            ->first();
        $this->assertNotNull($auditRow);
        $this->assertArrayNotHasKey('signer_user_id', $auditRow->details);
    }
}
