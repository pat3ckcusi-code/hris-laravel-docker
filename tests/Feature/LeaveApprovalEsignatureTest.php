<?php

namespace Tests\Feature;

use App\Jobs\SignESignatureRequestPdfJob;
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
 * on the Department Head / Administrative Officer Pending Requests page - the
 * approval half is delegated to approveLeave() unchanged (already covered by
 * DepartmentHeadTest/AdministrativeOfficerTest), so these tests focus on: the
 * password/setting gates never touching approval state, the two base-PDF
 * branches (fresh render vs. co-signing on top of an existing completed
 * signing), and department-scoping for both roles. SignESignatureRequestPdfJob
 * itself is queue-faked throughout (its own signing logic is covered by
 * EsignatureSigningTest) - only one test (the fresh-render happy path) exercises
 * the real buildEsignaturePdfBytes() PDF render, matching the cost profile of
 * EsignatureSigningTest's own single expensive test.
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
        $this->assertNull($signing->field_name);
        $this->assertSame(EsignatureSigning::STATUS_PENDING, $signing->status);
        $this->assertStringStartsWith('%PDF', Storage::disk('esignature')->get($signing->unsigned_path));

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
        $this->assertSame(
            Storage::disk('esignature')->get($priorSigning->signed_path),
            Storage::disk('esignature')->get($newSigning->unsigned_path),
            'The co-signing pass must start from the applicant\'s already-signed PDF, not a fresh render.'
        );
        $this->assertTrue(Storage::disk('esignature')->exists("signings/{$this->extractToken($newSigning)}/signature_field.json"));

        Queue::assertPushed(SignESignatureRequestPdfJob::class, fn ($job) => $job->signing->is($newSigning));
    }

    public function test_administrative_officer_can_approve_with_esign_for_their_own_department(): void
    {
        Queue::fake();

        $ao = $this->createAdminOfficer();
        $employee = $this->createEmployee(['Dept_id' => $ao->Dept_id]);
        $leave = $this->createPendingLeave($employee, ['esignature_requested_at' => now()]);
        $this->createEsignatureSetting($ao, 'correct-password');

        // Co-signing branch again here, deliberately - keeps this authorization-focused
        // test fast by skipping the expensive real PDF render, which is already covered
        // by the Department Head happy-path test above.
        Storage::disk('esignature')->put('signings/applicant-tok2/signed.pdf', '%PDF-1.4 already-signed-by-applicant');
        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $employee->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/applicant-tok2/unsigned.pdf',
            'signed_path' => 'signings/applicant-tok2/signed.pdf',
        ]);

        $response = $this->actingAs($ao)->postJson(
            route('admin-officer.leave.approve-esign', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonStructure(['signing_id', 'status_url']);
        $this->assertSame('approved', $leave->fresh()->status);

        Queue::assertPushed(SignESignatureRequestPdfJob::class, function ($job) use ($ao) {
            return $job->signing->requested_by === $ao->id && $job->signing->field_name === 'ApproverSignature';
        });
    }

    private function extractToken(EsignatureSigning $signing): string
    {
        return basename(dirname($signing->unsigned_path));
    }
}
