<?php

namespace Tests\Feature;

use App\Models\ESignatureSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Covers ESignatureConfigController::store()'s Root CA self-signed check, added
 * after a real incident: an HR Manager uploaded their Root CA and Intermediate
 * certificate files into swapped slots, which saved with no warning and only
 * surfaced later as an opaque pyHanko InsufficientRevinfoError at actual signing
 * time. A genuine root CA is always self-signed (issuer === subject); an
 * intermediate never is - that's the exact, reliable signal used to catch a
 * swap at save time instead.
 */
class ESignatureConfigTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    /**
     * Builds a real two-certificate chain: a self-signed root and an
     * intermediate genuinely issued by it - the same shape as the real
     * "Philippine Root CA - G2" / "Philippine Government CA - G2" pair
     * involved in the actual incident.
     *
     * @return array{root: string, intermediate: string}
     */
    private function makeRootAndIntermediatePem(): array
    {
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rootCsr = openssl_csr_new(['commonName' => 'Test Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 3650);
        openssl_x509_export($rootCert, $rootPem);

        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $intCsr = openssl_csr_new(['commonName' => 'Test Intermediate CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCert, $rootKey, 3650);
        openssl_x509_export($intCert, $intPem);

        return ['root' => $rootPem, 'intermediate' => $intPem];
    }

    private function makeThrowawayPkcs12(string $password): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Test Signer'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);
        openssl_pkcs12_export($cert, $pkcs12, $key, $password);

        return $pkcs12;
    }

    /**
     * Converts a PEM certificate to raw DER bytes - the inverse of the production
     * fallback in ESignatureConfigController::parseCertificateBytes(), and a
     * realistic stand-in for a real PNPKI-exported CA certificate file, which is
     * commonly distributed as raw DER rather than PEM.
     */
    private function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\r|\n/', '', $pem);

        return base64_decode($body);
    }

    private function makeSignatureDataUrl(): string
    {
        $image = imagecreatetruecolor(50, 20);
        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($binary);
    }

    private function baseFormData(array $overrides = []): array
    {
        return array_merge([
            'signature' => $this->makeSignatureDataUrl(),
            'pnpki_certificate' => UploadedFile::fake()->createWithContent('cert.p12', 'placeholder-not-a-real-cert'),
            'pnpki_password' => 'irrelevant',
            'include_name' => '1',
            'include_date' => '1',
        ], $overrides);
    }

    public function test_store_rejects_a_root_ca_upload_that_is_not_self_signed(): void
    {
        Storage::fake('esignature');

        $user = $this->createEmployee();
        $chain = $this->makeRootAndIntermediatePem();

        // The exact swap that broke the real HR Manager's setting: the intermediate
        // (not self-signed) is submitted as the Root CA field.
        $response = $this->actingAs($user)->post(route('esignature-config.store'), $this->baseFormData([
            'chain_root_ca' => UploadedFile::fake()->createWithContent('root.pem', $chain['intermediate']),
            'chain_intermediates' => [UploadedFile::fake()->createWithContent('int.pem', $chain['root'])],
        ]));

        $response->assertSessionHasErrors('chain_root_ca');
        $this->assertDatabaseCount('esignature_settings', 0);
        $this->assertFalse(Storage::disk('esignature')->exists((string) $user->id.'/root_ca'));
    }

    public function test_store_accepts_a_genuinely_self_signed_root_ca(): void
    {
        Storage::fake('esignature');

        $user = $this->createEmployee();
        $chain = $this->makeRootAndIntermediatePem();
        $password = 'correct-password';
        $pkcs12 = $this->makeThrowawayPkcs12($password);

        $response = $this->actingAs($user)->post(route('esignature-config.store'), $this->baseFormData([
            'pnpki_certificate' => UploadedFile::fake()->createWithContent('cert.p12', $pkcs12),
            'pnpki_password' => $password,
            'chain_root_ca' => UploadedFile::fake()->createWithContent('root.pem', $chain['root']),
            'chain_intermediates' => [UploadedFile::fake()->createWithContent('int.pem', $chain['intermediate'])],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('esignature-config.index'));

        $setting = ESignatureSetting::where('user_id', $user->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame((string) $user->id.'/root_ca', $setting->root_ca_path);
        $this->assertTrue(Storage::disk('esignature')->exists($setting->root_ca_path));
    }

    /**
     * Regression test for a real bug: openssl_x509_parse() doesn't auto-detect raw
     * DER (unlike the `openssl x509` CLI tool used to originally diagnose the swap
     * incident), so the self-signed check rejected every genuine DER-encoded Root
     * CA upload - correct or swapped alike - until parseCertificateBytes() added a
     * PEM-envelope fallback.
     */
    public function test_store_accepts_a_genuinely_self_signed_root_ca_in_der_format(): void
    {
        Storage::fake('esignature');

        $user = $this->createEmployee();
        $chain = $this->makeRootAndIntermediatePem();
        $password = 'correct-password';
        $pkcs12 = $this->makeThrowawayPkcs12($password);

        $response = $this->actingAs($user)->post(route('esignature-config.store'), $this->baseFormData([
            'pnpki_certificate' => UploadedFile::fake()->createWithContent('cert.p12', $pkcs12),
            'pnpki_password' => $password,
            'chain_root_ca' => UploadedFile::fake()->createWithContent('root.cer', $this->pemToDer($chain['root'])),
            'chain_intermediates' => [UploadedFile::fake()->createWithContent('int.cer', $this->pemToDer($chain['intermediate']))],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('esignature-config.index'));
        $this->assertNotNull(ESignatureSetting::where('user_id', $user->id)->first());
    }

    public function test_store_rejects_a_swapped_root_ca_in_der_format(): void
    {
        Storage::fake('esignature');

        $user = $this->createEmployee();
        $chain = $this->makeRootAndIntermediatePem();

        $response = $this->actingAs($user)->post(route('esignature-config.store'), $this->baseFormData([
            'chain_root_ca' => UploadedFile::fake()->createWithContent('root.cer', $this->pemToDer($chain['intermediate'])),
            'chain_intermediates' => [UploadedFile::fake()->createWithContent('int.cer', $this->pemToDer($chain['root']))],
        ]));

        $response->assertSessionHasErrors('chain_root_ca');
        $this->assertDatabaseCount('esignature_settings', 0);
    }
}
