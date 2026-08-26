<?php

namespace Tests\Feature;

use App\Jobs\SignESignatureRequestPdfJob;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\ESignatureSetting;
use App\Models\EsignatureSigning;
use App\Models\HRAuditTrail;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\DocumentRequestEsignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Covers the Front Desk -> HR Manager document-request e-signature handoff:
 * DocumentRequestEsignatureService's derived queries and forward/reject/reopen
 * transitions, FrontDeskController's forward/reopen actions and its new
 * Completed-transition gate, and HRManagerController::frontdeskAction()'s
 * sign/reject actions. Mirrors LeaveCertificationBatchSignTest's conventions.
 */
class DocumentRequestEsignatureTest extends TestCase
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

    private function createDocumentType(bool $requiresEsignature = true, array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'name' => 'Test Certificate '.uniqid(),
            'requires_esignature' => $requiresEsignature,
            'parts' => [
                'title' => 'Test Certificate',
                'salutation' => 'To Whom It May Concern:',
                'body' => ['text' => 'This certifies that {employee_name} is employed as {designation}.', 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'],
                'signatories' => [['name' => 'HR MANAGER', 'designation' => 'HR Manager']],
                'footer' => ['text' => '', 'font' => 'Calibri', 'size' => 10, 'color' => '#000000'],
            ],
        ], $overrides));
    }

    private function createDocumentRequest(User $employee, DocumentType $type, array $overrides = []): DocumentRequest
    {
        return DocumentRequest::create(array_merge([
            'EmpNo' => $employee->EmpNo,
            'document_type' => $type->name,
            'purpose' => 'Testing',
            'status' => 'Accepted',
            'requested_on' => now(),
        ], $overrides));
    }

    /**
     * Marks a document as already forwarded by Front Desk, for tests whose
     * subject is the HR Manager's sign/reject step rather than the forward
     * step itself.
     */
    private function forwardDocumentRequest(DocumentRequest $documentRequest, ?User $reviewer = null): DocumentRequest
    {
        $documentRequest->update([
            'signature_status' => 'forwarded',
            'signature_reviewed_by' => $reviewer?->id,
            'signature_reviewed_at' => now(),
        ]);

        return $documentRequest->fresh();
    }

    private function service(): DocumentRequestEsignatureService
    {
        return app(DocumentRequestEsignatureService::class);
    }

    // ── eligibleQuery() ──────────────────────────────────────────────────

    public function test_eligible_query_excludes_a_document_type_with_the_toggle_off(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(false);
        $this->createDocumentRequest($employee, $type);

        $this->assertCount(0, $this->service()->eligibleQuery()->get());
    }

    public function test_eligible_query_excludes_a_document_not_yet_accepted(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $this->createDocumentRequest($employee, $type, ['status' => 'Requested']);

        $this->assertCount(0, $this->service()->eligibleQuery()->get());
    }

    // ── forwardedForSigningQuery() ───────────────────────────────────────

    public function test_forwarded_for_signing_query_excludes_not_yet_forwarded_documents(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $this->createDocumentRequest($employee, $type);

        $this->assertCount(0, $this->service()->forwardedForSigningQuery()->get());
    }

    public function test_forwarded_for_signing_query_includes_a_forwarded_document(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $ids = $this->service()->forwardedForSigningQuery()->pluck('id');

        $this->assertTrue($ids->contains($documentRequest->id));
    }

    public function test_forwarded_for_signing_query_excludes_a_document_with_a_completed_signing(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        EsignatureSigning::create([
            'signable_type' => DocumentRequest::class,
            'signable_id' => $documentRequest->id,
            'requested_by' => $employee->id,
            'field_name' => null,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/already-signed/unsigned.pdf',
        ]);

        $this->assertCount(0, $this->service()->forwardedForSigningQuery()->get());
        $this->assertTrue($this->service()->isSigned($documentRequest));
    }

    public function test_forwarded_for_signing_query_excludes_a_document_with_a_signing_in_flight(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        EsignatureSigning::create([
            'signable_type' => DocumentRequest::class,
            'signable_id' => $documentRequest->id,
            'requested_by' => $employee->id,
            'field_name' => null,
            'status' => EsignatureSigning::STATUS_PROCESSING,
            'unsigned_path' => 'signings/in-flight/unsigned.pdf',
        ]);

        $this->assertCount(0, $this->service()->forwardedForSigningQuery()->get());
    }

    public function test_rejected_query_only_includes_rejected_documents(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $forwarded = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));
        $rejected = $this->createDocumentRequest($employee, $type);
        $rejected->update(['signature_status' => 'rejected']);

        $ids = $this->service()->rejectedQuery()->pluck('id');

        $this->assertTrue($ids->contains($rejected->id));
        $this->assertFalse($ids->contains($forwarded->id));
    }

    // ── forward() ────────────────────────────────────────────────────────

    public function test_front_desk_can_forward_an_accepted_document(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.forward-for-signature'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertOk()->assertJsonFragment(['success' => true]);

        $documentRequest->refresh();
        $this->assertSame('forwarded', $documentRequest->signature_status);
        $this->assertSame($fd->id, $documentRequest->signature_reviewed_by);

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $fd->id,
            'module' => 'frontdesk',
            'action' => 'forwarded_for_signature',
            'target_type' => DocumentRequest::class,
            'target_id' => $documentRequest->id,
        ]);
    }

    public function test_cannot_forward_a_document_already_forwarded(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.forward-for-signature'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertStatus(422);
    }

    public function test_cannot_forward_a_document_whose_type_does_not_require_esignature(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(false);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.forward-for-signature'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertStatus(422);
    }

    // ── reopen() ─────────────────────────────────────────────────────────

    public function test_front_desk_can_reopen_a_rejected_document(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);
        $documentRequest->update([
            'signature_status' => 'rejected',
            'signature_review_remarks' => 'Fix the wording',
        ]);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.reopen-signature'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertOk()->assertJsonFragment(['success' => true]);

        $documentRequest->refresh();
        $this->assertNull($documentRequest->signature_status);
        $this->assertNull($documentRequest->signature_review_remarks);

        $auditRow = HRAuditTrail::where('action', 'signature_reopened')->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('Fix the wording', $auditRow->details['previous_remarks']);
    }

    public function test_cannot_reopen_a_document_that_is_not_rejected(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.reopen-signature'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertStatus(422);
    }

    // ── Completion gate ──────────────────────────────────────────────────

    public function test_complete_request_is_blocked_for_an_unsigned_esignature_required_document(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.complete'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertStatus(422);
        $this->assertSame('Accepted', $documentRequest->fresh()->status);
    }

    public function test_update_status_to_completed_is_blocked_for_an_unsigned_esignature_required_document(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.update-status'),
            ['request_id' => $documentRequest->id, 'status' => 'Completed']
        );

        $response->assertStatus(422);
        $this->assertSame('Accepted', $documentRequest->fresh()->status);
    }

    public function test_complete_request_succeeds_once_signed(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        EsignatureSigning::create([
            'signable_type' => DocumentRequest::class,
            'signable_id' => $documentRequest->id,
            'requested_by' => $employee->id,
            'field_name' => null,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/already-signed/unsigned.pdf',
        ]);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.complete'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $this->assertSame('Completed', $documentRequest->fresh()->status);
    }

    public function test_complete_request_is_unaffected_for_a_document_type_without_esignature(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(false);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.complete'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $this->assertSame('Completed', $documentRequest->fresh()->status);
    }

    // ── HR Manager: frontdeskAction() reject ────────────────────────────

    public function test_hr_manager_can_reject_a_forwarded_document_with_a_reason(): void
    {
        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $response = $this->actingAs($hr)->postJson(
            route('hr-manager.frontdesk.action', $documentRequest->id),
            ['action' => 'reject', 'remarks' => 'Wrong salary figure.']
        );

        $response->assertOk()->assertJsonFragment(['success' => true]);

        $documentRequest->refresh();
        $this->assertSame('rejected', $documentRequest->signature_status);
        $this->assertSame($hr->id, $documentRequest->signature_reviewed_by);
        $this->assertSame('Wrong salary figure.', $documentRequest->signature_review_remarks);
    }

    public function test_reject_requires_remarks(): void
    {
        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $response = $this->actingAs($hr)->postJson(
            route('hr-manager.frontdesk.action', $documentRequest->id),
            ['action' => 'reject']
        );

        $response->assertStatus(422);
        $this->assertSame('forwarded', $documentRequest->fresh()->signature_status);
    }

    public function test_cannot_reject_a_document_not_yet_forwarded(): void
    {
        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $response = $this->actingAs($hr)->postJson(
            route('hr-manager.frontdesk.action', $documentRequest->id),
            ['action' => 'reject', 'remarks' => 'Too soon']
        );

        $response->assertStatus(422);
        $this->assertNull($documentRequest->fresh()->signature_status);
    }

    // ── HR Manager: frontdeskAction() sign ──────────────────────────────

    public function test_hr_manager_without_own_esignature_setting_cannot_sign(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $response = $this->actingAs($hr)->postJson(
            route('hr-manager.frontdesk.action', $documentRequest->id),
            ['action' => 'sign', 'pnpki_password' => 'irrelevant']
        );

        $response->assertStatus(422)->assertJsonFragment(['message' => 'You have not set up an e-signature yet.']);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_sign_rejects_wrong_password_without_creating_any_signing_rows(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('hr-manager.frontdesk.action', $documentRequest->id),
            ['action' => 'sign', 'pnpki_password' => 'wrong-password']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_sign_only_succeeds_for_a_document_actually_in_the_forwarded_queue(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $notYetForwarded = $this->createDocumentRequest($employee, $type);
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('hr-manager.frontdesk.action', $notYetForwarded->id),
            ['action' => 'sign', 'pnpki_password' => 'correct-password']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_happy_path_dispatches_a_base_signing_with_null_field_name(): void
    {
        Queue::fake();

        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));
        $this->createEsignatureSetting($hr, 'correct-password');

        $response = $this->actingAs($hr)->postJson(
            route('hr-manager.frontdesk.action', $documentRequest->id),
            ['action' => 'sign', 'pnpki_password' => 'correct-password']
        );

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $response->assertJsonStructure(['signing_id', 'status_url']);

        $this->assertDatabaseCount('esignature_signings', 1);
        $signing = EsignatureSigning::first();
        $this->assertSame(DocumentRequest::class, $signing->signable_type);
        $this->assertSame($documentRequest->id, $signing->signable_id);
        $this->assertSame($hr->id, $signing->requested_by);
        $this->assertNull($signing->field_name);
        $this->assertSame(EsignatureSigning::STATUS_PENDING, $signing->status);

        // Unlike the leave co-signing pattern (deferred to job-execution time to
        // avoid a race condition), a document request's base/sole signature has
        // no prior signature to race against, so the unsigned PDF and its
        // signature_field.json sidecar are both written synchronously here.
        $this->assertTrue(Storage::disk('esignature')->exists($signing->unsigned_path));
        $dir = dirname($signing->unsigned_path);
        $this->assertTrue(Storage::disk('esignature')->exists("{$dir}/signature_field.json"));

        Queue::assertPushed(SignESignatureRequestPdfJob::class, fn ($job) => $job->signing->is($signing));

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $hr->id,
            'module' => 'frontdesk',
            'action' => 'signature_dispatched',
            'target_type' => DocumentRequest::class,
            'target_id' => $documentRequest->id,
        ]);
    }

    // ── printRequest() access ────────────────────────────────────────────

    public function test_front_desk_can_preview_the_document(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $this->actingAs($fd)->get(route('front-desk.print-request', $documentRequest->id))->assertOk();
    }

    public function test_hr_manager_can_preview_the_document(): void
    {
        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $this->actingAs($hr)->get(route('front-desk.print-request', $documentRequest->id))->assertOk();
    }

    public function test_unrelated_role_cannot_view_the_document(): void
    {
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        $this->actingAs($employee)->get(route('front-desk.print-request', $documentRequest->id))->assertForbidden();
    }

    public function test_print_request_serves_the_signed_pdf_once_completed(): void
    {
        Storage::fake('esignature');

        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type);

        Storage::disk('esignature')->put('signings/tok/signed.pdf', '%PDF-1.4 signed-bytes');
        EsignatureSigning::create([
            'signable_type' => DocumentRequest::class,
            'signable_id' => $documentRequest->id,
            'requested_by' => $employee->id,
            'field_name' => null,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/tok/unsigned.pdf',
            'signed_path' => 'signings/tok/signed.pdf',
        ]);

        $response = $this->actingAs($fd)->get(route('front-desk.print-request', $documentRequest->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ── EsignatureSigningObserver ────────────────────────────────────────

    public function test_observer_sets_signed_by_and_signed_at_when_a_base_signing_completes(): void
    {
        $hr = $this->createHRManager();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->forwardDocumentRequest($this->createDocumentRequest($employee, $type));

        $signing = EsignatureSigning::create([
            'signable_type' => DocumentRequest::class,
            'signable_id' => $documentRequest->id,
            'requested_by' => $hr->id,
            'field_name' => null,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/observer-test/unsigned.pdf',
        ]);

        $signing->markCompleted('signings/observer-test/signed.pdf');

        $documentRequest->refresh();
        $this->assertSame($hr->id, $documentRequest->signed_by);
        $this->assertNotNull($documentRequest->signed_at);
    }

    public function test_observer_does_not_touch_an_unrelated_leave_requests_own_signing(): void
    {
        $employee = $this->createEmployee();
        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Observer scoping test',
            'status' => 'pending',
        ]);

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'field_name' => null,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/leave-observer-test/unsigned.pdf',
        ]);

        // Must not throw or attempt to write DocumentRequest-only columns onto a LeaveRequest.
        $signing->markCompleted('signings/leave-observer-test/signed.pdf');

        $this->assertTrue(true);
    }

    // ── DocumentSettingsController ───────────────────────────────────────

    public function test_document_settings_store_persists_requires_esignature(): void
    {
        $fd = $this->createFrontDesk();

        $this->actingAs($fd)->post(route('employee.document-settings.store'), [
            'name' => 'New Toggle Type',
            'requires_esignature' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('document_types', [
            'name' => 'New Toggle Type',
            'requires_esignature' => 1,
        ]);
    }

    public function test_document_settings_update_persists_requires_esignature(): void
    {
        $fd = $this->createFrontDesk();
        $type = $this->createDocumentType(false);

        $this->actingAs($fd)->put(route('employee.document-settings.update', $type->id), [
            'name' => $type->name,
            'requires_esignature' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('document_types', [
            'id' => $type->id,
            'requires_esignature' => 1,
        ]);
    }

    // ── Accept auto-forwards an e-signature-required document ──────────────

    public function test_accepting_an_esignature_required_document_auto_forwards_it(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type, ['status' => 'Requested']);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.accept'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertOk()->assertJsonFragment([
            'success' => true,
            'message' => 'Request accepted and forwarded to the HR Manager for signature.',
        ]);

        $documentRequest->refresh();
        $this->assertSame('Accepted', $documentRequest->status);
        $this->assertSame('forwarded', $documentRequest->signature_status);
        $this->assertSame($fd->id, $documentRequest->signature_reviewed_by);

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $fd->id,
            'module' => 'frontdesk',
            'action' => 'forwarded_for_signature',
            'target_type' => DocumentRequest::class,
            'target_id' => $documentRequest->id,
        ]);
    }

    public function test_accepting_a_non_esignature_document_does_not_forward_it(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(false);
        $documentRequest = $this->createDocumentRequest($employee, $type, ['status' => 'Requested']);

        $response = $this->actingAs($fd)->postJson(
            route('front-desk.accept'),
            ['request_id' => $documentRequest->id]
        );

        $response->assertOk()->assertJsonFragment([
            'success' => true,
            'message' => 'Request accepted and employee has been notified.',
        ]);

        $documentRequest->refresh();
        $this->assertSame('Accepted', $documentRequest->status);
        $this->assertNull($documentRequest->signature_status);
    }

    public function test_manual_forward_still_works_after_a_reopen(): void
    {
        $fd = $this->createFrontDesk();
        $employee = $this->createEmployee();
        $type = $this->createDocumentType(true);
        $documentRequest = $this->createDocumentRequest($employee, $type, ['status' => 'Requested']);

        $this->actingAs($fd)->postJson(route('front-desk.accept'), ['request_id' => $documentRequest->id])
            ->assertOk();

        $documentRequest->refresh();
        $documentRequest->update(['signature_status' => 'rejected', 'signature_review_remarks' => 'Fix it']);

        $this->actingAs($fd)->postJson(route('front-desk.reopen-signature'), ['request_id' => $documentRequest->id])
            ->assertOk();
        $this->assertNull($documentRequest->fresh()->signature_status);

        $this->actingAs($fd)->postJson(route('front-desk.forward-for-signature'), ['request_id' => $documentRequest->id])
            ->assertOk()->assertJsonFragment(['success' => true]);

        $this->assertSame('forwarded', $documentRequest->fresh()->signature_status);
    }
}
