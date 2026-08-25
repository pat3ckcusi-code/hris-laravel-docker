<?php

namespace App\Http\Controllers;

use App\Models\ESignatureSetting;
use App\Models\HRAuditTrail;
use App\Services\ESignatureCredentialStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ESignatureConfigController extends Controller
{
    private const MAX_SIGNATURE_BYTES = 1024 * 1024;

    public function index()
    {
        $esignatureSetting = auth()->user()->esignatureSetting;

        return view('esignature-config.index', compact('esignatureSetting'));
    }

    public function store(Request $request, ESignatureCredentialStore $credentialStore)
    {
        $data = $request->validate([
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,'],
            'pnpki_certificate' => ['required', 'file', 'max:10240'],
            'pnpki_password' => ['required', 'string'],
            'chain_root_ca' => ['required', 'file', 'max:10240'],
            'chain_intermediates' => ['required', 'array', 'min:1'],
            'chain_intermediates.*' => ['file', 'max:10240'],
        ]);

        $encoded = substr($data['signature'], strpos($data['signature'], ',') + 1);
        $binary = base64_decode($encoded, true);

        abort_if($binary === false || strlen($binary) < 100, 422, 'Invalid signature image.');
        abort_if(strlen($binary) > self::MAX_SIGNATURE_BYTES, 422, 'Signature image must be under 1MB.');

        $certificateBytes = $request->file('pnpki_certificate')->get();
        $rootCaBytes = $request->file('chain_root_ca')->get();
        $intermediateBytesList = array_map(
            fn ($file) => $file->get(),
            $request->file('chain_intermediates')
        );

        if (! $credentialStore->verifyPassword($certificateBytes, $data['pnpki_password'])) {
            return back()
                ->withErrors(['pnpki_password' => 'That password did not unlock the certificate you uploaded. Please check the password and try again.'])
                ->withInput($request->except(['pnpki_password', 'pnpki_certificate', 'chain_root_ca', 'chain_intermediates']));
        }

        $existingSetting = ESignatureSetting::where('user_id', auth()->id())->first();

        // Fixed, user-keyed paths (not a fresh directory per submission) so re-saving
        // naturally overwrites the signature/certificate/root CA in place. Only the
        // intermediates need explicit orphan cleanup below, since their count can shrink
        // between saves.
        $dir = (string) auth()->id();
        $signaturePath = "{$dir}/signature.png";
        $certificatePath = "{$dir}/certificate.enc";
        $rootCaPath = "{$dir}/root_ca";
        $intermediatePaths = [];

        // Write everything new before touching the DB row or deleting anything old. The
        // `esignature` disk is configured with 'throw' => false, so a failed write
        // returns false rather than throwing - if any write below fails, abort before
        // the employee's previously-saved (working) setting is disturbed at all.
        $writesOk = Storage::disk('esignature')->put($signaturePath, $binary);
        $writesOk = $writesOk && $credentialStore->storeEncrypted($certificatePath, $certificateBytes);
        $writesOk = $writesOk && Storage::disk('esignature')->put($rootCaPath, $rootCaBytes);

        foreach ($intermediateBytesList as $index => $intermediateBytes) {
            $intermediatePath = "{$dir}/intermediate_{$index}";
            $writesOk = $writesOk && Storage::disk('esignature')->put($intermediatePath, $intermediateBytes);
            $intermediatePaths[] = $intermediatePath;
        }

        abort_if(! $writesOk, 500, 'Failed to save your e-signature setting. Please try again.');

        $esignatureSetting = ESignatureSetting::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'signature_path' => $signaturePath,
                'certificate_path' => $certificatePath,
                'root_ca_path' => $rootCaPath,
                'intermediate_paths' => $intermediatePaths,
                'include_name' => $request->boolean('include_name'),
                'include_date' => $request->boolean('include_date'),
            ]
        );

        // Every file path here is fixed per-user, so a re-save with genuinely different
        // file *content* (a renewed certificate, a redrawn signature) can still resolve
        // to identical column values - updateOrCreate() then sees no dirty attributes and
        // silently skips the UPDATE query, leaving updated_at stale even though the files
        // on disk were just overwritten. touch() forces it to reflect the real save time
        // unconditionally, since "Saved {updated_at}" on the page must never lag behind
        // what's actually on disk.
        $esignatureSetting->touch();

        // Clean up intermediate files left over from a previous save that had more of
        // them than this one - the writes above only ever overwrite indices [0, count),
        // so anything at or beyond the new count is now orphaned.
        if ($existingSetting) {
            $oldCount = count($existingSetting->intermediate_paths);
            for ($index = count($intermediatePaths); $index < $oldCount; $index++) {
                Storage::disk('esignature')->delete("{$dir}/intermediate_{$index}");
            }
        }

        HRAuditTrail::create([
            'actor_user_id' => auth()->id(),
            'module' => 'esignature',
            'action' => $existingSetting ? 'esignature_setting_updated' : 'esignature_setting_created',
            'target_type' => ESignatureSetting::class,
            'target_id' => $esignatureSetting->id,
            'details' => [],
        ]);

        return redirect()->route('esignature-config.index')->with('status', 'Your e-signature setting has been saved.');
    }

    public function destroy()
    {
        $esignatureSetting = ESignatureSetting::where('user_id', auth()->id())->first();

        abort_if(! $esignatureSetting, 404);

        $settingId = $esignatureSetting->id;

        Storage::disk('esignature')->deleteDirectory((string) auth()->id());
        $esignatureSetting->delete();

        HRAuditTrail::create([
            'actor_user_id' => auth()->id(),
            'module' => 'esignature',
            'action' => 'esignature_setting_removed',
            'target_type' => ESignatureSetting::class,
            'target_id' => $settingId,
            'details' => [],
        ]);

        return redirect()->route('esignature-config.index')->with('status', 'Your e-signature setting has been removed.');
    }

    /**
     * AJAX check used by the "Save e-signature setting?" confirmation modal, so a
     * mistyped password is caught right there (Swal.showValidationMessage(), modal
     * stays open) instead of only surfacing after the real submit - a full page
     * reload can never repopulate the certificate/root-CA/intermediate file inputs
     * (a hard browser limitation, not something withInput() can work around), so
     * catching this earlier avoids forcing the user to re-pick every file. Read-only:
     * writes nothing to disk or the database. store() still re-checks the password
     * itself before actually saving - this is a UX pre-check, not a trust boundary.
     */
    public function verifyPassword(Request $request, ESignatureCredentialStore $credentialStore)
    {
        $data = $request->validate([
            'pnpki_certificate' => ['required', 'file', 'max:10240'],
            'pnpki_password' => ['required', 'string'],
        ]);

        $certificateBytes = $request->file('pnpki_certificate')->get();

        if (! $credentialStore->verifyPassword($certificateBytes, $data['pnpki_password'])) {
            return response()->json([
                'valid' => false,
                'message' => 'That password did not unlock the certificate you uploaded. Please check the password and try again.',
            ], 422);
        }

        return response()->json(['valid' => true]);
    }

    /**
     * AJAX check used by other pages (e.g. Leave Management's "Submit using
     * e-signature" button) to confirm the employee still knows the password to
     * their ALREADY-SAVED PNPKI certificate before proceeding. Unlike
     * verifyPassword() above (which checks a freshly-uploaded file at save
     * time), there's no upload here — the certificate bytes are decrypted from
     * the employee's own saved ESignatureSetting via ESignatureCredentialStore.
     * The password itself is never stored; it's used only for this one
     * openssl_pkcs12_read() check and then discarded. Read-only: writes nothing.
     */
    public function verifySavedPassword(Request $request, ESignatureCredentialStore $credentialStore)
    {
        $data = $request->validate([
            'pnpki_password' => ['required', 'string'],
        ]);

        $setting = auth()->user()->esignatureSetting;

        if (! $setting) {
            return response()->json([
                'valid' => false,
                'message' => 'You have not set up an e-signature yet.',
            ], 422);
        }

        try {
            $certificateBytes = $credentialStore->retrieveDecrypted($setting->certificate_path);
        } catch (\Throwable $e) {
            return response()->json([
                'valid' => false,
                'message' => 'Could not read your saved certificate. Please contact HR or re-save your e-signature setting.',
            ], 422);
        }

        if (! $credentialStore->verifyPassword($certificateBytes, $data['pnpki_password'])) {
            return response()->json([
                'valid' => false,
                'message' => 'That password did not unlock your saved certificate. Please check the password and try again.',
            ], 422);
        }

        return response()->json(['valid' => true]);
    }
}
