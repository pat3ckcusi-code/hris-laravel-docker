<?php

namespace Tests\Feature;

use App\Jobs\SignESignatureRequestPdfJob;
use App\Models\ESignatureSetting;
use App\Models\EsignatureSigning;
use App\Models\LeaveRequest;
use App\Services\ESignatureCredentialStore;
use App\Services\LeaveRequestService;
use App\Support\Rfc3161TimestampClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Covers the PNPKI e-signature signing pipeline added on top of leave
 * printing: startEsignaturePrint()'s auth/validation gates, the polling
 * status endpoint's ownership check, and SignESignatureRequestPdfJob's glue
 * (resolving material from ESignatureSetting, updating EsignatureSigning,
 * audit logging) via faked Process/Http calls - not the real pyHanko/TSA
 * round trip, which needs a real PNPKI certificate and is verified manually
 * (see the plan's "not automatable" verification step).
 */
class EsignatureSigningTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const TSA_GRANTED_DER = "\x30\x05\x30\x03\x02\x01\x00";

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

    private function createEsignatureLeave($owner, array $overrides = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'user_id' => $owner->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'total_days' => 1,
            'paid_days' => 1,
            'lwop_days' => 0,
            'status' => 'pending',
            'date_filed' => now()->toDateString(),
            'printing_allowed' => true,
            'esignature_requested_at' => now(),
        ], $overrides));
    }

    // ── store() auto-dispatches signing right after filing ─────────────

    public function test_filing_with_correct_esignature_password_dispatches_signing_automatically(): void
    {
        Queue::fake();

        $user = $this->createEmployee(['is_solo_parent' => true]);
        $this->createLeaveBalance($user, ['SP' => 5.000]);
        $this->createEsignatureSetting($user, 'correct-password');

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_types' => ['Solo Parent Leave'],
            'leave_dates' => now()->addWeeks(2)->toDateString(),
            'reason' => 'Solo parent leave test',
            'submitted_via_esignature' => '1',
            'pnpki_password' => 'correct-password',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $leave = LeaveRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($leave->esignature_requested_at);

        $signing = EsignatureSigning::where('signable_id', $leave->id)->first();
        $this->assertNotNull($signing, 'Expected signing to be dispatched automatically at filing time.');
        $this->assertSame(EsignatureSigning::STATUS_PENDING, $signing->status);

        Queue::assertPushed(SignESignatureRequestPdfJob::class, function ($job) use ($signing) {
            return $job->signing->is($signing) && $job->password === 'correct-password' && $job->queue === 'exports';
        });
    }

    public function test_filing_with_wrong_esignature_password_still_files_but_does_not_sign(): void
    {
        Queue::fake();

        $user = $this->createEmployee(['is_solo_parent' => true]);
        $this->createLeaveBalance($user, ['SP' => 5.000]);
        $this->createEsignatureSetting($user, 'correct-password');

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_types' => ['Solo Parent Leave'],
            'leave_dates' => now()->addWeeks(2)->toDateString(),
            'reason' => 'Solo parent leave test',
            'submitted_via_esignature' => '1',
            'pnpki_password' => 'wrong-password',
        ]);

        // Filing must succeed regardless - an e-signature hiccup is never a reason to
        // block an otherwise-valid leave request.
        $response->assertSessionDoesntHaveErrors();
        $leave = LeaveRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($leave);
        $this->assertNotNull($leave->esignature_requested_at);

        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_filing_without_esignature_intent_never_touches_signing(): void
    {
        Queue::fake();

        $user = $this->createEmployee(['is_solo_parent' => true]);
        $this->createLeaveBalance($user, ['SP' => 5.000]);
        $this->createEsignatureSetting($user, 'correct-password');

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_types' => ['Solo Parent Leave'],
            'leave_dates' => now()->addWeeks(2)->toDateString(),
            'reason' => 'Solo parent leave test',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $leave = LeaveRequest::where('user_id', $user->id)->first();
        $this->assertNull($leave->esignature_requested_at);
        $this->assertDatabaseCount('esignature_signings', 0);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_pnpki_password_is_not_flashed_to_session_on_validation_failure(): void
    {
        $user = $this->createEmployee(['is_solo_parent' => false]); // triggers a validation failure below
        $this->createLeaveBalance($user, ['SP' => 5.000]);

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_types' => ['Solo Parent Leave'],
            'leave_dates' => now()->addWeeks(2)->toDateString(),
            'reason' => 'Solo parent leave test',
            'submitted_via_esignature' => '1',
            'pnpki_password' => 'super-secret-value',
        ]);

        $response->assertSessionHasErrors();
        $this->assertNull(session('_old_input.pnpki_password'), 'pnpki_password must never be flashed back into the session.');
    }

    // ── startEsignaturePrint() ──────────────────────────────────────────

    public function test_start_esignature_print_requires_leave_owner(): void
    {
        $owner = $this->createEmployee();
        $other = $this->createHRManager();
        $leave = $this->createEsignatureLeave($owner, ['status' => 'approved']);

        $response = $this->actingAs($other)
            ->postJson(route('employee.leave.esignature-print.start', $leave->id), ['pnpki_password' => 'irrelevant']);

        $response->assertStatus(403);
    }

    public function test_start_esignature_print_requires_esignature_requested_flag(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner, ['esignature_requested_at' => null]);

        $response = $this->actingAs($owner)
            ->postJson(route('employee.leave.esignature-print.start', $leave->id), ['pnpki_password' => 'irrelevant']);

        $response->assertStatus(422);
    }

    public function test_start_esignature_print_requires_esignature_setting(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $response = $this->actingAs($owner)
            ->postJson(route('employee.leave.esignature-print.start', $leave->id), ['pnpki_password' => 'irrelevant']);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'You have not set up an e-signature yet.']);
    }

    public function test_start_esignature_print_rejects_wrong_password(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $this->createEsignatureSetting($owner, 'correct-password');

        $response = $this->actingAs($owner)
            ->postJson(route('employee.leave.esignature-print.start', $leave->id), ['pnpki_password' => 'wrong-password']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('esignature_signings', 0);
    }

    public function test_start_esignature_print_dispatches_job_on_correct_password(): void
    {
        Queue::fake();

        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $this->createEsignatureSetting($owner, 'correct-password');

        $response = $this->actingAs($owner)
            ->postJson(route('employee.leave.esignature-print.start', $leave->id), ['pnpki_password' => 'correct-password']);

        $response->assertStatus(200)->assertJsonStructure(['signing_id', 'status_url']);

        $signing = EsignatureSigning::first();
        $this->assertNotNull($signing);
        $this->assertSame(LeaveRequest::class, $signing->signable_type);
        $this->assertSame($leave->id, $signing->signable_id);
        $this->assertSame(EsignatureSigning::STATUS_PENDING, $signing->status);
        $this->assertSame($owner->id, $signing->requested_by);

        Queue::assertPushed(SignESignatureRequestPdfJob::class, function ($job) use ($signing) {
            return $job->signing->is($signing) && $job->password === 'correct-password' && $job->queue === 'exports';
        });
    }

    public function test_start_esignature_print_reuses_in_flight_signing(): void
    {
        Queue::fake();

        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $this->createEsignatureSetting($owner, 'correct-password');

        $existing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PROCESSING,
            'unsigned_path' => 'signings/existing/unsigned.pdf',
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('employee.leave.esignature-print.start', $leave->id), ['pnpki_password' => 'correct-password']);

        $response->assertStatus(200)->assertJson(['signing_id' => $existing->id]);
        $this->assertDatabaseCount('esignature_signings', 1);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    public function test_start_esignature_print_refuses_when_leave_already_has_completed_signing(): void
    {
        Queue::fake();

        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $this->createEsignatureSetting($owner, 'correct-password');

        // Simulates the applicant's own signature plus a DH/AO co-signature already
        // having completed successfully - the exact state that must never be
        // overwritten by a fresh blank re-render.
        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/own/unsigned.pdf',
            'signed_path' => 'signings/own/signed.pdf',
        ]);
        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'field_name' => 'ApproverSignature',
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/approver/unsigned.pdf',
            'signed_path' => 'signings/approver/signed.pdf',
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('employee.leave.esignature-print.start', $leave->id), ['pnpki_password' => 'correct-password']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('esignature_signings', 2);
        Queue::assertNotPushed(SignESignatureRequestPdfJob::class);
    }

    // ── EsignatureSigningController::status() ──────────────────────────

    public function test_esignature_signing_status_requires_ownership(): void
    {
        $owner = $this->createEmployee();
        $other = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/abc/unsigned.pdf',
        ]);

        $response = $this->actingAs($other)->getJson(route('esignature-signings.status', $signing->id));

        $response->assertStatus(403);
    }

    public function test_esignature_signing_status_reports_completed_with_download_url(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/abc/unsigned.pdf',
            'signed_path' => 'signings/abc/signed.pdf',
        ]);

        $response = $this->actingAs($owner)->getJson(route('esignature-signings.status', $signing->id));

        $response->assertStatus(200)->assertJson([
            'status' => 'completed',
            'download_url' => route('employee.leave.print.single', $leave->id),
        ]);
    }

    // ── SignESignatureRequestPdfJob (glue only - real pyHanko/TSA faked) ─

    public function test_job_completes_signing_and_marks_row_completed(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $this->createEsignatureSetting($owner, 'correct-password');

        Storage::disk('esignature')->put('signings/tok/unsigned.pdf', '%PDF-1.4 fake unsigned bytes');

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/tok/unsigned.pdf',
        ]);

        Process::fake(fn () => Process::result(output: 'ok', exitCode: 0));
        Http::fake(['*' => Http::response(self::TSA_GRANTED_DER, 200)]);

        $job = new SignESignatureRequestPdfJob($signing, 'correct-password');
        $job->handle(app(Rfc3161TimestampClient::class), app(ESignatureCredentialStore::class), app(LeaveRequestService::class));

        $signing->refresh();
        $this->assertSame(EsignatureSigning::STATUS_COMPLETED, $signing->status);
        $this->assertSame('signings/tok/signed.pdf', $signing->signed_path);
        $this->assertNotNull($signing->completed_at);
        $this->assertFalse(Storage::disk('esignature')->exists('signings/tok/unsigned.pdf'));

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'esignature',
            'action' => 'esignature_signed',
            'target_type' => EsignatureSigning::class,
            'target_id' => $signing->id,
        ]);
    }

    public function test_job_marks_failed_on_pyhanko_failure(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $this->createEsignatureSetting($owner, 'correct-password');

        Storage::disk('esignature')->put('signings/tok2/unsigned.pdf', '%PDF-1.4 fake unsigned bytes');

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/tok2/unsigned.pdf',
        ]);

        Process::fake(fn () => Process::result(output: '', errorOutput: 'boom', exitCode: 1));
        Http::fake(['*' => Http::response(self::TSA_GRANTED_DER, 200)]);

        $job = new SignESignatureRequestPdfJob($signing, 'correct-password');

        $caught = null;
        try {
            $job->handle(app(Rfc3161TimestampClient::class), app(ESignatureCredentialStore::class), app(LeaveRequestService::class));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected signWithLtv() to throw when pyHanko fails.');

        $job->failed($caught);

        $signing->refresh();
        $this->assertSame(EsignatureSigning::STATUS_FAILED, $signing->status);
        $this->assertNotNull($signing->failed_at);
        $this->assertNotNull($signing->error_message);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'esignature',
            'action' => 'esignature_signing_failed',
            'target_type' => EsignatureSigning::class,
            'target_id' => $signing->id,
        ]);
    }

    // ── resolveCoSigningBasePdf() - closes the leave #2606 race condition ─

    public function test_job_throws_when_a_sibling_signing_is_still_in_flight_for_a_cosigning_pass(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $dh = $this->createDepartmentHead();
        $this->createEsignatureSetting($dh, 'correct-password');

        // Sibling still in flight - e.g. the employee's own auto-dispatched base signing
        // hasn't finished its pyHanko/TSA round trip yet.
        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/sibling/unsigned.pdf',
        ]);

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $dh->id,
            'field_name' => 'ApproverSignature',
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/cosign/unsigned.pdf',
        ]);

        $job = new SignESignatureRequestPdfJob($signing, 'correct-password');

        $caught = null;
        try {
            $job->handle(app(Rfc3161TimestampClient::class), app(ESignatureCredentialStore::class), app(LeaveRequestService::class));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected resolveCoSigningBasePdf() to throw while a sibling signing is still in flight.');
        $this->assertStringContainsString('still in progress', $caught->getMessage());
        $this->assertFalse(Storage::disk('esignature')->exists('signings/cosign/unsigned.pdf'));
    }

    public function test_job_builds_on_top_of_latest_completed_signing_for_a_cosigning_pass(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $dh = $this->createDepartmentHead();
        $this->createEsignatureSetting($dh, 'correct-password');

        Storage::disk('esignature')->put('signings/prior/signed.pdf', '%PDF-1.4 already-signed-by-applicant-marker');

        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/prior/unsigned.pdf',
            'signed_path' => 'signings/prior/signed.pdf',
        ]);

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $dh->id,
            'field_name' => 'ApproverSignature',
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/cosign/unsigned.pdf',
        ]);

        // Captured from inside the fake Process call, before the job's own post-success
        // cleanup deletes unsigned.pdf - proves resolveCoSigningBasePdf() copied the
        // prior's bytes rather than rendering fresh.
        $capturedUnsignedContent = null;
        Process::fake(function () use (&$capturedUnsignedContent, $signing) {
            $capturedUnsignedContent = Storage::disk('esignature')->get($signing->unsigned_path);

            return Process::result(output: 'ok', exitCode: 0);
        });
        Http::fake(['*' => Http::response(self::TSA_GRANTED_DER, 200)]);

        $job = new SignESignatureRequestPdfJob($signing, 'correct-password');
        $job->handle(app(Rfc3161TimestampClient::class), app(ESignatureCredentialStore::class), app(LeaveRequestService::class));

        $signing->refresh();
        $this->assertSame(EsignatureSigning::STATUS_COMPLETED, $signing->status);
        $this->assertSame('%PDF-1.4 already-signed-by-applicant-marker', $capturedUnsignedContent);
    }

    public function test_job_falls_back_to_fresh_render_for_a_cosigning_pass_with_no_prior_completed_signing(): void
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $dh = $this->createDepartmentHead();
        $this->createEsignatureSetting($dh, 'correct-password');

        // No prior signing at all for this leave - the applicant's own auto-sign either
        // never fired (no ESignatureSetting) or was never dispatched.
        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $dh->id,
            'field_name' => 'ApproverSignature',
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/cosign/unsigned.pdf',
        ]);

        Process::fake(fn () => Process::result(output: 'ok', exitCode: 0));
        Http::fake(['*' => Http::response(self::TSA_GRANTED_DER, 200)]);

        $job = new SignESignatureRequestPdfJob($signing, 'correct-password');
        $job->handle(app(Rfc3161TimestampClient::class), app(ESignatureCredentialStore::class), app(LeaveRequestService::class));

        $signing->refresh();
        $this->assertSame(EsignatureSigning::STATUS_COMPLETED, $signing->status);
        $this->assertSame('ApproverSignature', $signing->field_name, 'field_name stays the caller\'s fixed intent even when this ends up being the document\'s only signature.');
    }

    /**
     * clampFieldRectToSafeArea() must never let a candidate field rect (whether
     * DEFAULT_FIELD_RECT itself, hand-edited, or a future signature_field.json
     * sidecar - see resolveFieldRect()'s docblock) cross the printed-text
     * boundaries the stamp is designed to respect, and must degrade
     * gracefully (fall back to DEFAULT_FIELD_RECT) rather than produce a
     * negative/zero-height image row when a candidate is too short to use.
     */
    private function invokeClamp(SignESignatureRequestPdfJob $job, array $fieldRect): array
    {
        $method = new ReflectionMethod($job, 'clampFieldRectToSafeArea');
        $method->setAccessible(true);

        return $method->invoke($job, $fieldRect);
    }

    private function makeJobForClampTest(): SignESignatureRequestPdfJob
    {
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $this->createEsignatureSetting($owner, 'correct-password');

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/clamp-test/unsigned.pdf',
        ]);

        return new SignESignatureRequestPdfJob($signing, 'correct-password');
    }

    public function test_field_rect_clamp_leaves_default_rect_untouched(): void
    {
        $job = $this->makeJobForClampTest();
        $defaultRect = (new ReflectionClass(SignESignatureRequestPdfJob::class))->getConstant('DEFAULT_FIELD_RECT');

        $clamped = $this->invokeClamp($job, $defaultRect);

        $this->assertSame($defaultRect['y1'], $clamped['y1']);
        $this->assertSame($defaultRect['y2'], $clamped['y2']);
    }

    public function test_field_rect_clamp_pulls_out_of_bounds_rect_back_into_range(): void
    {
        $job = $this->makeJobForClampTest();
        $refClass = new ReflectionClass(SignESignatureRequestPdfJob::class);
        $captionTopY = $refClass->getConstant('PRINTED_CAPTION_TOP_Y');
        $lineAboveBottomY = $refClass->getConstant('PRINTED_LINE_ABOVE_BOTTOM_Y');

        // Deliberately out of bounds on both ends - below the caption's own
        // safe top and above "Requested"'s safe bottom.
        $clamped = $this->invokeClamp($job, ['page' => 1, 'x1' => 300, 'y1' => 200, 'x2' => 530, 'y2' => 500]);

        $this->assertSame($captionTopY, $clamped['y1']);
        $this->assertSame($lineAboveBottomY, $clamped['y2']);
    }

    public function test_field_rect_clamp_falls_back_to_default_when_too_short(): void
    {
        $job = $this->makeJobForClampTest();
        $defaultRect = (new ReflectionClass(SignESignatureRequestPdfJob::class))->getConstant('DEFAULT_FIELD_RECT');

        // A 2pt-tall candidate can't fit even one compact text line.
        $clamped = $this->invokeClamp($job, ['page' => 1, 'x1' => 300, 'y1' => 350, 'x2' => 530, 'y2' => 352]);

        $this->assertSame($defaultRect, $clamped);
    }
}
