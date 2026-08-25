<?php

namespace Tests\Feature;

use App\Jobs\SignESignatureRequestPdfJob;
use App\Models\ESignatureSetting;
use App\Models\EsignatureSigning;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Covers the new print/"Allow Printing" gate for leaves filed with e-signature
 * intent: neither should be possible until Leave Credit Certification has
 * completed for that leave (LeaveRequestService::needsCertificationBeforePrinting()),
 * regardless of which door canPrint() would otherwise let the caller through
 * (the printing_allowed flag, or the AO/HR-Manager/Department-Head "any approved
 * leave" unconditional branches). Mirrors LeaveApprovalEsignatureTest's
 * conventions (CreatesTestUsers, Storage::fake('esignature'), a throwaway
 * self-signed PKCS12 for ESignatureSetting).
 */
class LeaveCertificationPrintGateTest extends TestCase
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
            'reason' => 'Print gate test',
            'status' => 'pending',
            'printing_allowed' => false,
            'esignature_requested_at' => now(),
        ], $overrides));
    }

    private function markCertified(LeaveRequest $leave): void
    {
        EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $leave->user_id,
            'field_name' => 'CertifyingSignature',
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/certified/unsigned.pdf',
        ]);
    }

    // ── canPrint() ────────────────────────────────────────────────────

    public function test_can_print_blocks_administrative_officer_on_approved_uncertified_leave(): void
    {
        $ao = $this->createAdminOfficer();
        $employee = $this->createEmployee(['Dept_id' => $ao->Dept_id]);
        $leave = $this->createEsignatureLeave($employee, ['status' => 'approved']);

        $this->assertFalse(app(LeaveRequestService::class)->canPrint($leave, $ao));
    }

    public function test_can_print_allows_administrative_officer_once_certified(): void
    {
        $ao = $this->createAdminOfficer();
        $employee = $this->createEmployee(['Dept_id' => $ao->Dept_id]);
        $leave = $this->createEsignatureLeave($employee, ['status' => 'approved']);
        $this->markCertified($leave);

        $this->assertTrue(app(LeaveRequestService::class)->canPrint($leave, $ao));
    }

    public function test_can_print_blocks_department_head_on_approved_uncertified_leave(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createEsignatureLeave($employee, ['status' => 'approved']);

        $this->assertFalse(app(LeaveRequestService::class)->canPrint($leave, $dh));
    }

    public function test_can_print_allows_department_head_once_certified(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createEsignatureLeave($employee, ['status' => 'approved']);
        $this->markCertified($leave);

        $this->assertTrue(app(LeaveRequestService::class)->canPrint($leave, $dh));
    }

    public function test_can_print_is_unaffected_for_a_non_esignature_leave(): void
    {
        $ao = $this->createAdminOfficer();
        $employee = $this->createEmployee(['Dept_id' => $ao->Dept_id]);
        $leave = $this->createEsignatureLeave($employee, ['status' => 'approved', 'esignature_requested_at' => null]);

        $this->assertTrue(app(LeaveRequestService::class)->canPrint($leave, $ao));
    }

    // ── startEsignaturePrint() opt-out ───────────────────────────────────

    public function test_start_esignature_print_still_works_on_an_uncertified_leave(): void
    {
        Queue::fake();

        $employee = $this->createEmployee();
        // printing_allowed=true here in isolation: canPrint()'s owner-path already
        // requires this flag independent of the certification gate this test targets
        // (see EsignatureSigningTest::createEsignatureLeave()'s identical default) -
        // false would 403 for that unrelated, pre-existing reason instead.
        $leave = $this->createEsignatureLeave($employee, ['printing_allowed' => true]);
        $this->createEsignatureSetting($employee, 'correct-password');

        $response = $this->actingAs($employee)->postJson(
            route('employee.leave.esignature-print.start', $leave->id),
            ['pnpki_password' => 'correct-password']
        );

        $response->assertStatus(200)->assertJsonStructure(['signing_id', 'status_url']);
        Queue::assertPushed(SignESignatureRequestPdfJob::class);
    }

    // ── allowPrinting() ───────────────────────────────────────────────

    public function test_department_head_allow_printing_blocked_until_certified(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createEsignatureLeave($employee);

        $response = $this->actingAs($dh)->postJson(route('department-head.leave.allow-printing', $leave->id));

        $response->assertStatus(422)->assertJsonFragment([
            'error' => 'This leave must be certified in Leave Credit Certification before printing can be allowed.',
        ]);
        $this->assertFalse((bool) $leave->fresh()->printing_allowed);
    }

    public function test_administrative_officer_allow_printing_blocked_until_certified(): void
    {
        $ao = $this->createAdminOfficer();
        $employee = $this->createEmployee(['Dept_id' => $ao->Dept_id]);
        $leave = $this->createEsignatureLeave($employee);

        $response = $this->actingAs($ao)->postJson(route('admin-officer.leave.allow-printing', $leave->id));

        $response->assertStatus(422)->assertJsonFragment([
            'error' => 'This leave must be certified in Leave Credit Certification before printing can be allowed.',
        ]);
        $this->assertFalse((bool) $leave->fresh()->printing_allowed);
    }

    public function test_department_head_allow_printing_succeeds_once_certified(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createEsignatureLeave($employee);
        $this->markCertified($leave);

        $response = $this->actingAs($dh)->postJson(route('department-head.leave.allow-printing', $leave->id));

        $response->assertStatus(200)->assertJsonFragment(['success' => true]);
        $this->assertTrue((bool) $leave->fresh()->printing_allowed);
    }

    public function test_administrative_officer_allow_printing_succeeds_once_certified(): void
    {
        $ao = $this->createAdminOfficer();
        $employee = $this->createEmployee(['Dept_id' => $ao->Dept_id]);
        $leave = $this->createEsignatureLeave($employee);
        $this->markCertified($leave);

        $response = $this->actingAs($ao)->postJson(route('admin-officer.leave.allow-printing', $leave->id));

        $response->assertStatus(200)->assertJsonFragment(['success' => true]);
        $this->assertTrue((bool) $leave->fresh()->printing_allowed);
    }

    public function test_allow_printing_is_unaffected_for_a_non_esignature_leave(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $leave = $this->createEsignatureLeave($employee, ['esignature_requested_at' => null]);

        $response = $this->actingAs($dh)->postJson(route('department-head.leave.allow-printing', $leave->id));

        $response->assertStatus(200)->assertJsonFragment(['success' => true]);
        $this->assertTrue((bool) $leave->fresh()->printing_allowed);
    }
}
