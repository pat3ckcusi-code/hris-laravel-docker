<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Encrypts/decrypts the one genuinely sensitive file in the E-Signature Config
 * feature - the employee's PNPKI certificate (.p12/.pfx), which contains a
 * private key. The trust chain (root CA / intermediate certs) is public by
 * definition and is stored plain, so this store is never used for those.
 *
 * Uses Crypt::encryptString()/decryptString() (APP_KEY-based) - the same
 * primitive App\Casts\EncryptedArray/EncryptedDecimal already use for payroll
 * monetary columns, just applied to file bytes instead of a DB column value.
 * Laravel has no built-in encrypted-disk driver, so this is hand-rolled.
 *
 * retrieveDecrypted() returns raw bytes rather than a path or temp file on
 * purpose - a future consumer (e.g. a signing job) may need either a bytes
 * buffer (openssl_pkcs12_read()) or a real file path (pyHanko, a subprocess
 * CLI); it's on that caller to build its own chmod-0600, finally-unlinked
 * temp file from these bytes if it needs a path, mirroring how
 * SignESignatureRequestPdfJob already handles its own transient cert material.
 */
class ESignatureCredentialStore
{
    /**
     * @return bool the underlying Storage::put() result - the `esignature`
     *              disk is configured with 'throw' => false, so a failed write returns
     *              false rather than throwing. Callers must check this explicitly.
     */
    public function storeEncrypted(string $diskPath, string $bytes): bool
    {
        return Storage::disk('esignature')->put($diskPath, Crypt::encryptString($bytes));
    }

    public function retrieveDecrypted(string $diskPath): string
    {
        return Crypt::decryptString(Storage::disk('esignature')->get($diskPath));
    }

    /**
     * Quick local check that a password actually unlocks a PKCS#12
     * certificate, so a mistyped password is caught immediately. The
     * password itself is never stored anywhere - it's used only for this
     * check and then discarded. Shared by every call site that needs this
     * check (a freshly-uploaded certificate at save time, or an
     * already-saved one decrypted via retrieveDecrypted() above) so there's
     * one implementation of the openssl call, not one per caller.
     */
    public function verifyPassword(string $certificateBytes, string $password): bool
    {
        return (bool) openssl_pkcs12_read($certificateBytes, $certs, $password);
    }
}
