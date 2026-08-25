<?php

namespace Tests\Feature;

use App\Jobs\SignESignatureRequestPdfJob;
use App\Models\Department;
use App\Models\ESignatureSetting;
use App\Models\EsignatureSigning;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Covers "Approve using e-sign" (LeaveRequestService::approveLeaveWithEsignature())
 * on the Department Head's Pending Requests page - the approval half is delegated
 * to approveLeave() unchanged (already covered by DepartmentHeadTest), so these
 * tests focus on: the password/setting gates never touching approval state, the
 * dispatch-time behavior (field name is now always fixed - never conditional -
 * since which base PDF to build on is resolved later, at job-execution time, to
 * close a real race condition that once let a Department Head's approval race the
 * applicant's own still-in-flight signature and silently discard it), and that the
 * Administrative Officer can no longer sign the form at all. SignESignatureRequestPdfJob
 * itself is queue-faked throughout (its own signing logic, including the base-PDF
 * resolution this fix moved into it, is covered by EsignatureSigningTest) - only
 * one test (the fresh-render happy path) exercises the real buildEsignaturePdfBytes()
 * PDF render, matching the cost profile of EsignatureSigningTest's own single
 * expensive test.
 */
class LeaveApprovalEsignatureTest extends TestCase
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

    private function createPendingLeave($employee, array $overrides = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Approve-with-esign test',
            'status' => 'pending',
            'printing_allowed' => true,
        ], $overrides));
    }

    // ── Gates that must never touch approval state ──────────────────────

    public function test_requires_esignature_setting(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee);

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'irrelevant']
        );

        $response->assertStatus(422)->assertJsonFragment(['message' => 'You have not set up an e-signature yet.']);
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_rejects_wrong_password_without_approving(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee);
        $this->createEsignatureSetting($dh, 'correct-password');

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'wrong-password']
        );

        $response->assertStatus(422);
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_requires_printing_allowed_before_approving(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['printing_allowed' => false]);
        $this->createEsignatureSetting($dh, 'correct-password');

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(422)->assertJsonFragment(['message' => 'Printing must be allowed before approval.']);
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_does_not_dispatch_signing_when_already_approved(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['status' => 'approved']);
        $this->createEsignatureSetting($dh, 'correct-password');

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonFragment(['message' => 'Leave already approved.']);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    // ── Happy paths ───────────────────────────────────────────────────

    public function test_happy_path_signs_a_fresh_pdf_when_no_prior_signing_exists(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee);
        $this->createEsignatureSetting($dh, 'correct-password');

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonStructure(['signing_id', 'status_url']);
        $this->assertSame('approved', $leave->fresh()->status);

        $signing = EsignatureSigning::first();
        $this->assertNotNull($signing);
        $this->assertSame(LeaveRequest::class, $signing->signable_type);
        $this->assertSame($leave->id, $signing->signable_id);
        $this->assertSame($dh->id, $signing->requested_by);
        // field_name is now always the fixed 'ApproverSignature' at dispatch time - never
        // conditional on whether a prior signing exists - since which base PDF to build
        // on (a fresh render, in this no-prior case) is resolved later, at job-execution
        // time, by SignESignatureRequestPdfJob::resolveCoSigningBasePdf(). That's also why
        // unsigned.pdf isn't written yet here; the real fresh-render behavior itself is
        // covered by EsignatureSigningTest's job-level tests.
        $this->assertSame('ApproverSignature', $signing->field_name);
        $this->assertSame(EsignatureSigning::STATUS_PENDING, $signing->status);
        $this->assertFalse(Storage::disk('esignature')->exists($signing->unsigned_path));

        Queue::assertPushed(SignESignatureRequestPdfJob::class, function ($job) use ($signing) {
            return $job->signing->is($signing) && $job->password === 'correct-password' && $job->queue === 'exports';
        });
    }

    public function test_happy_path_cosigns_on_top_of_an_existing_completed_signing(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['esignature_requested_at' => now()]);
        $this->createEsignatureSetting($dh, 'correct-password');

        Storage::disk('esignature')->put('signings/applicant-tok/signed.pdf', '%PDF-1.4 already-signed-by-applicant');

        $priorSigning = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/applicant-tok/unsigned.pdf',
            'signed_path' => 'signings/applicant-tok/signed.pdf',
        ]);

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonStructure(['signing_id', 'status_url']);
        $this->assertSame('approved', $leave->fresh()->status);

        $newSigning = EsignatureSigning::where('id', '!=', $priorSigning->id)->first();
        $this->assertNotNull($newSigning);
        $this->assertSame($dh->id, $newSigning->requested_by);
        $this->assertSame('ApproverSignature', $newSigning->field_name);
        // Content resolution ("build on top of the applicant's already-signed PDF, not a
        // fresh render") is now deferred to SignESignatureRequestPdfJob::resolveCoSigningBasePdf()
        // at job-execution time, not written here at dispatch time - see that method's docblock
        // for why (closes a race condition). Queue::fake() means the job never actually runs
        // in this test, so unsigned.pdf must not exist yet; the deferred behavior itself is
        // covered by EsignatureSigningTest's job-level tests.
        $this->assertFalse(Storage::disk('esignature')->exists($newSigning->unsigned_path));
        $this->assertTrue(Storage::disk('esignature')->exists("signings/{$this->extractToken($newSigning)}/signature_field.json"));

        Queue::assertPushed(SignESignatureRequestPdfJob::class, fn ($job) => $job->signing->is($newSigning));
    }

    /**
     * Regression test for the real incident (leave #2606) that motivated this fix:
     * the Department Head approved-with-esign while the applicant's own auto-dispatched
     * signing was still PENDING (its job hadn't run yet under Queue::fake(), matching
     * production where it just hadn't finished its pyHanko/TSA round trip yet).
     * Before the fix, dispatchCoSigningPass() resolved "is there a prior completed
     * signing" synchronously here and found none yet, so it dispatched with
     * field_name=null - landing the co-signature in the base 'Signature' field slot
     * instead of 'ApproverSignature', and (before this specific fix's related change)
     * building from a blank render instead of the eventually-completed prior. Now the
     * field name is always fixed at dispatch time; base-PDF resolution is deferred
     * entirely to the job (see EsignatureSigningTest's job-level coverage).
     */
    public function test_approve_with_esign_dispatches_with_approver_field_name_even_when_applicants_own_signing_is_still_in_flight(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['esignature_requested_at' => now()]);
        $this->createEsignatureSetting($dh, 'correct-password');

        $inFlightSigning = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/applicant-inflight/unsigned.pdf',
        ]);

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonStructure(['signing_id', 'status_url']);
        $this->assertSame('approved', $leave->fresh()->status);

        $newSigning = EsignatureSigning::where('id', '!=', $inFlightSigning->id)->first();
        $this->assertNotNull($newSigning);
        $this->assertSame('ApproverSignature', $newSigning->field_name, 'The field name must never be conditional on whether a prior signing has completed yet.');

        Queue::assertPushed(SignESignatureRequestPdfJob::class, fn ($job) => $job->signing->is($newSigning));
    }

    // ── retryApproverCoSignature() / retryEsignCoSign() ─────────────────

    public function test_retry_esign_cosign_rejects_when_leave_is_not_approved(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['esignature_requested_at' => now()]);
        $this->createEsignatureSetting($dh, 'correct-password');

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.retry-esign-cosign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(422);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_retry_esign_cosign_rejects_when_already_cosigned(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['status' => 'approved', 'esignature_requested_at' => now()]);
        $this->createEsignatureSetting($dh, 'correct-password');

        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $dh->id,
            'field_name' => 'ApproverSignature',
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/already/unsigned.pdf',
            'signed_path' => 'signings/already/signed.pdf',
        ]);

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.retry-esign-cosign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(422)->assertJsonFragment(['message' => 'This leave already has a completed Department Head co-signature.']);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    /**
     * Models leave #2606's exact post-recovery state: the employee's own signature
     * completed, but no completed ApproverSignature exists yet (the AO's orphaned one
     * has been invalidated) - the Department Head uses this to add the missing
     * co-signature without re-running the approval itself.
     */
    public function test_retry_esign_cosign_succeeds_when_base_signature_exists_but_no_approver_cosign_yet(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['status' => 'approved', 'esignature_requested_at' => now()]);
        $this->createEsignatureSetting($dh, 'correct-password');

        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/base/unsigned.pdf',
            'signed_path' => 'signings/base/signed.pdf',
        ]);

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.retry-esign-cosign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonStructure(['signing_id', 'status_url']);

        Queue::assertPushed(SignESignatureRequestPdfJob::class, function ($job) use ($dh) {
            return $job->signing->requested_by === $dh->id && $job->signing->field_name === 'ApproverSignature';
        });
    }

    public function test_retry_esign_cosign_requires_department_head_scope(): void
    {
        Queue::fake();

        $dh = $this->createDepartmentHead();
        $this->createEsignatureSetting($dh, 'correct-password');

        $otherDept = Department::forceCreate([
            'DeptCode' => 'OTHER', 'Dept_name' => 'Other Department', 'EmpNo' => 'OTHER-EMPNO', 'Designation' => 'Test',
        ]);
        $employee = $this->createEmployee(['Dept_id' => $otherDept->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['status' => 'approved', 'esignature_requested_at' => now()]);

        $response = $this->actingAs($dh)->postJson(
            route('department-head.leave.retry-esign-cosign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(403);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_administrative_officer_can_no_longer_approve_with_esign(): void
    {
        Queue::fake();

        // Only the Department Head signs the leave form - the Administrative Officer's
        // role is print-authorization only (Allow Printing), never a signature on the
        // document (per this project's documented flow: "Employee files -> Department
        // Head approves -> Administrative Officer pre-approves for printing -> HR
        // Manager has override authority"). AdministrativeOfficerController::approveWithEsign()
        // and its route have been removed entirely - route() would throw for the
        // now-nonexistent name, so this hits the raw URL directly to confirm it never
        // reaches the controller (see the 405 note below).
        $ao = $this->createAdminOfficer();
        $employee = $this->createEmployee(['Dept_id' => $ao->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['esignature_requested_at' => now()]);
        $this->createEsignatureSetting($ao, 'correct-password');

        $response = $this->actingAs($ao)->postJson(
            '/admin-officer/leave/'.$leave->id.'/approve-esign',
            ['pnpki_password' => 'correct-password']
        );

        // The app registers a catch-all GET|HEAD Route::fallback(), which matches this
        // now-nonexistent path too - so a POST to it is a genuine URI match with the
        // wrong method (405), not a plain 404. Either way it never reaches a controller.
        $response->assertStatus(405);
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    private function extractToken(EsignatureSigning $signing): string
    {
        return basename(dirname($signing->unsigned_path));
    }
}
