<?php

namespace App\Jobs;

use App\Models\EsignatureSigning;
use App\Models\HRAuditTrail;
use App\Services\ESignatureCredentialStore;
use App\Services\LeaveRequestService;
use App\Support\Rfc3161TimestampClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Signs any Signable document (currently only LeaveRequest, via
 * EsignatureSigning's polymorphic signable) with the requesting employee's
 * saved PNPKI e-signature (ESignatureSetting). This is the original "sign
 * one document right now" prototype's job, reworked to resolve certificate/
 * chain/signature material from that persistent setting instead of taking
 * them directly as constructor properties (the original design, before
 * E-Signature Config was rebuilt around a persistent setting) - see the
 * dropped esignature_requests migration for that history.
 *
 * The password is the one piece of material that's never persisted anywhere
 * in this app, so it's the only signing material that still rides in the
 * job's own (ShouldBeEncrypted) payload; everything else is re-read fresh
 * from disk in handle() each time this job actually runs, so a signing
 * attempt correctly fails if the employee's setting changes between kickoff
 * and pickup rather than silently signing with stale material.
 *
 * signWithLtv() and everything it calls (pyHanko config/command building,
 * the Rfc3161TimestampClient pre-flight, the post-sign validation re-check)
 * is unchanged from the original prototype - that part was already correct
 * and fully tested; only how its inputs are resolved changed.
 */
class SignESignatureRequestPdfJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * Used whenever resolveFieldRect() finds no signature_field.json sidecar
     * (always, today - see that method's docblock). pyHanko refuses to
     * auto-place a *visible* signature (our --style-name pnpki has a
     * background image + stamp text) without either an existing named
     * AcroForm field in the source PDF or explicit coordinates - confirmed
     * empirically, it does not silently fall back to some default of its own.
     *
     * Targets the real official CS Form 6 PDF (storage/app/templates/
     * LEAVE.pdf, supplied directly by the office, page 1 is 595.32x841.92pt /
     * A4) that LeaveRequestService::buildEsignaturePdfBytes() now stamps via
     * FPDI - not a re-rendering, so no custom page size is involved here
     * (unlike an earlier version of this constant, calibrated against a
     * Dompdf re-render of the Excel template that no longer exists). The
     * applicant's own printed caption, "(Signature of Applicant)", sits at
     * (358.51, 348.77) on this exact PDF - confirmed directly by reading the
     * content stream's text-position operators. Checked for a printed
     * signature line above it (both vector m/l line operators and text runs
     * in that region) and found none - the gap between that caption and the
     * next content above it, "Requested" at (310.87, 372.17) from the
     * unrelated 6.D Commutation section, is genuinely blank; the applicant
     * signs directly in that space, no printed rule line, a common
     * convention on official forms. This rect sits inside that confirmed-
     * blank gap with a small margin on every side.
     *
     * Widened/heightened 2026-08-19 for a bigger, less cramped stacked stamp
     * (image on top, text below - see buildStampLayoutYaml()). Horizontally
     * there was real room to spare (nothing else occupies this y-band past
     * x=358), so x1/x2 widened accordingly.
     *
     * Heightened further 2026-08-19 (round 2, after real-world screenshot
     * feedback - the stamp needed 400% zoom to read, and the stamp *text*
     * visibly collided with the caption's own glyphs) to use the full
     * confirmed-safe vertical range: y1=333 sits ~2pt clear of "7. DETAILS OF
     * ACTION ON APPLICATION"'s own cap-height above its 330.62 baseline;
     * y2=370 is unchanged, ~2pt clear of "Requested"'s baseline (372.17)
     * above. This range deliberately spans *through* the caption's own
     * y=348.77 line now - per the user's explicit choice, the signature
     * IMAGE is allowed to cross the caption like a real ink signature would;
     * only the stamp TEXT must stay clear of it (see buildStampLayoutYaml()).
     *
     * Narrowed 2026-08-19 (round 3, per explicit user request) to half its
     * prior width - x1/x2 tightened from 300/530 (230pt wide) to 358/473
     * (115pt), keeping the same horizontal center (~415) the wider box
     * already used. This happens to land x1 almost exactly on the caption's
     * own text start (358.51) - a reasonable anchor, not a coincidence worth
     * re-deriving. The text row's own x-align also switched from left to mid
     * (see buildStampLayoutYaml()) so the now-narrower, centered stamp text
     * sits directly under the centered image instead of hugging the left
     * edge of a box it no longer shares proportions with.
     *
     * Moved up 30pt 2026-08-20 (round 6, explicit user request) - y1/y2 both
     * +30 (333/370 -> 363/400). Re-checked the content stream before doing
     * this: "Requested" (the original basis for the old y2=372 ceiling) sits
     * at x=310.87, which doesn't actually reach into this box's x-range
     * (358-473) at all, and there's no vertical divider line between the two
     * columns either - so that old ceiling was more conservative than this
     * specific column actually required. Other labels in the same left
     * column do sit in the new y-range this box now passes through - "Not
     * Requested" (~385.85), "6.D COMMUTATION" (~399.89), "Terminal Leave"
     * (~417.17) - rough character-width estimates put their right edges
     * close to but not clearly past x=358, unlike "Requested". Not fully
     * confirmed without rendering (no PDF-to-image tool in this sandbox) -
     * worth a visual check on a real signed PDF.
     *
     * Backed off 10pt 2026-08-20 (round 7, "too much [...] downward of 10"):
     * y1/y2 both -10 (363/400 -> 353/390), landing the box back closer to
     * the caption's own line - its clip box top is 356.09 (see this file's
     * earlier notes on that region), so y1=353 now sits just a few points
     * below that, overlapping the top sliver of the caption's own clip
     * region rather than sitting entirely clear above it like round 6 did.
     * Comfortably inside both clamp bounds (330/402), no re-clamping happens.
     */
    private const DEFAULT_FIELD_RECT = ['page' => 1, 'x1' => 358, 'y1' => 353, 'x2' => 473, 'y2' => 390];

    /**
     * Named boundaries backing clampFieldRectToSafeArea() below - given names
     * so a future edit to DEFAULT_FIELD_RECT (or a future signature_field.json
     * sidecar, per resolveFieldRect()'s docblock) can be defended against
     * instead of only documented. Raised 2026-08-20 (round 6) from 372 to 402
     * alongside DEFAULT_FIELD_RECT's own move above - see that constant's
     * docblock for why the old 372 ceiling ("Requested"'s clearance) doesn't
     * actually bind in this box's column, and what still might.
     */
    private const PRINTED_LINE_ABOVE_BOTTOM_Y = 402;

    private const PRINTED_CAPTION_TOP_Y = 330;

    /** Minimum usable box height - below this, even a single compact text line won't fit. */
    private const MIN_SAFE_HEIGHT = 12;

    /**
     * Below this box height, buildStampLayoutYaml() switches from its stacked
     * (image-over-text) split to a side-by-side (image-beside-text) split -
     * see that method's own docblock for why. Comfortably below every
     * applicant-area box height used across this file's history (~15-37pt).
     */
    private const MIN_STACKED_HEIGHT = 20;

    private string $certificate = '';

    private string $chainRootCa = '';

    /** @var array<int, string> */
    private array $chainIntermediates = [];

    private bool $includeName = true;

    private bool $includeDate = true;

    private string $signaturePath = '';

    public function __construct(
        public EsignatureSigning $signing,
        public string $password,
    ) {}

    public function handle(Rfc3161TimestampClient $tsaClient, ESignatureCredentialStore $credentialStore, LeaveRequestService $leaveRequestService): void
    {
        $signable = $this->signing->signable;
        abort_if(! $signable, 404, 'Signable record no longer exists.');

        // Whose certificate signs this attempt is always the requester, not the
        // document's own fixed owner (Signable::esignatureOwner()) - for the original
        // self-service flow those are the same person (dispatchEsignatureSigning() sets
        // requested_by to the leave's own applicant), but a co-signing pass (e.g. a
        // Department Head countersigning at approval) requests a signature with their
        // own certificate, not the applicant's.
        $signer = $this->signing->requestedBy;
        abort_if(! $signer, 404, 'Signing requester no longer exists.');

        $setting = $signer->esignatureSetting;
        if (! $setting) {
            throw new RuntimeException('PNPKI signing requires the signer to have an e-signature setting configured.');
        }

        $this->signing->markProcessing();

        if ($this->signing->field_name !== null) {
            $this->resolveCoSigningBasePdf($leaveRequestService);
        }

        $this->certificate = $credentialStore->retrieveDecrypted($setting->certificate_path);
        $this->chainRootCa = Storage::disk('esignature')->get($setting->root_ca_path);
        $this->chainIntermediates = collect($setting->intermediate_paths)
            ->map(fn ($path) => Storage::disk('esignature')->get($path))
            ->all();
        $this->includeName = (bool) $setting->include_name;
        $this->includeDate = (bool) $setting->include_date;
        $this->signaturePath = Storage::disk('esignature')->path($setting->signature_path);

        $this->assertPnpkiConfigured();

        $unsignedPath = Storage::disk('esignature')->path($this->signing->unsigned_path);
        abort_if(! is_file($unsignedPath), 404, 'Unsigned PDF not found.');

        $dir = dirname($this->signing->unsigned_path);
        Storage::disk('esignature')->makeDirectory($dir);
        $signedRelativePath = "{$dir}/signed.pdf";
        $signedAbsolutePath = Storage::disk('esignature')->path($signedRelativePath);

        $this->signWithLtv($unsignedPath, $signedAbsolutePath, $tsaClient);

        // The signed PDF is a strict superset of the unsigned one's content -
        // no reason to keep the intermediate artifact around once signing succeeds.
        Storage::disk('esignature')->delete($this->signing->unsigned_path);

        $this->signing->markCompleted($signedRelativePath);

        HRAuditTrail::create([
            'actor_user_id' => null,
            'module' => 'esignature',
            'action' => 'esignature_signed',
            'target_type' => EsignatureSigning::class,
            'target_id' => $this->signing->id,
            'details' => [
                'signable_type' => $this->signing->signable_type,
                'signable_id' => $this->signing->signable_id,
            ],
        ]);

        Log::info('PNPKI: LTV signing succeeded.', ['esignature_signing_id' => $this->signing->id]);
    }

    /**
     * A co-signing pass (field_name set - Department Head approval, HR
     * certification) must build on top of whichever signing is genuinely the
     * latest COMPLETED one at the moment this job actually RUNS, not at the
     * moment it was dispatched - LeaveRequestService::dispatchCoSigningPass()
     * deliberately no longer resolves this eagerly, since doing so raced the
     * applicant's own auto-dispatched base signing (or an earlier co-signing
     * pass) whenever it hadn't finished its pyHanko/TSA round trip yet: the eager
     * check would find "no completed signing exists" and silently render a
     * fresh, blank-based PDF, discarding every already-completed signature -
     * a real incident that happened in production (leave #2606). If a sibling
     * signing for the same document is still pending/processing, this throws so
     * the job's own retry/backoff ($tries/$backoff above) gives it real
     * wall-clock time (~100s across 3 attempts) to resolve before this attempt
     * is marked genuinely failed, recoverable via a manual retry action.
     */
    private function resolveCoSigningBasePdf(LeaveRequestService $leaveRequestService): void
    {
        $siblingInFlight = EsignatureSigning::where('signable_type', $this->signing->signable_type)
            ->where('signable_id', $this->signing->signable_id)
            ->where('id', '!=', $this->signing->id)
            ->whereIn('status', [EsignatureSigning::STATUS_PENDING, EsignatureSigning::STATUS_PROCESSING])
            ->exists();

        if ($siblingInFlight) {
            throw new RuntimeException('A prior signing on this document is still in progress. Please try co-signing again shortly.');
        }

        $priorSigning = EsignatureSigning::where('signable_type', $this->signing->signable_type)
            ->where('signable_id', $this->signing->signable_id)
            ->where('id', '!=', $this->signing->id)
            ->where('status', EsignatureSigning::STATUS_COMPLETED)
            ->latest()
            ->first();

        $basePdfBytes = ($priorSigning && $priorSigning->signed_path && Storage::disk('esignature')->exists($priorSigning->signed_path))
            ? Storage::disk('esignature')->get($priorSigning->signed_path)
            : $leaveRequestService->buildEsignaturePdfBytes($this->signing->signable);

        Storage::disk('esignature')->put($this->signing->unsigned_path, $basePdfBytes);
    }

    /**
     * Sign the PDF at PAdES-B-LT level (signature + TSA timestamp + embedded
     * OCSP/CRL/chain revocation evidence in the Document Security Store).
     *
     * Classic TCPDF (used elsewhere in this app for visual PDF rendering) has
     * no support for DSS/VRI/OCSP/CRL embedding at all - verified by
     * inspecting its source, it only produces a legacy PKCS#7 signature. A
     * genuinely PAdES-B-LT-compliant signature also requires a CAdES-format
     * CMS body, which is a different structure than what TCPDF's
     * setSignature() produces, so this isn't something that can be bolted
     * onto the old signature after the fact - the whole cryptographic
     * signing step is delegated to pyHanko (Python, subprocess), which is
     * purpose-built for PAdES-B-LT/LTA.
     */
    private function signWithLtv(string $unsignedPath, string $signedPath, Rfc3161TimestampClient $tsaClient): void
    {
        $tsaUrl = config('services.pnpki.tsa_url');

        // PAdES-B-LT requires a B-T (timestamped) signature underneath it, so unlike
        // the previous implementation, a missing/unreachable/rejecting TSA is a hard
        // failure rather than a silent degrade - never produce an unvalidated signature.
        if (empty($tsaUrl)) {
            throw new RuntimeException('PNPKI: PNPKI_TSA_URL is required for PAdES-B-LT signing.');
        }

        $this->assertTsaAvailable($tsaUrl, $tsaClient);

        $passfilePath = tempnam(sys_get_temp_dir(), 'pnpki_pass_');
        $configPath = tempnam(sys_get_temp_dir(), 'pnpki_cfg_');
        $certPath = tempnam(sys_get_temp_dir(), 'pnpki_cert_');
        $rootCaPath = tempnam(sys_get_temp_dir(), 'pnpki_root_');
        $intermediatePaths = [];

        try {
            chmod($passfilePath, 0600);
            file_put_contents($passfilePath, $this->password);

            chmod($certPath, 0600);
            file_put_contents($certPath, $this->certificate);

            chmod($rootCaPath, 0600);
            file_put_contents($rootCaPath, $this->chainRootCa);

            foreach ($this->chainIntermediates as $intermediateBytes) {
                $intermediatePath = tempnam(sys_get_temp_dir(), 'pnpki_intcert_');
                chmod($intermediatePath, 0600);
                file_put_contents($intermediatePath, $intermediateBytes);
                $intermediatePaths[] = $intermediatePath;
            }

            $fieldRect = $this->resolveFieldRect();

            file_put_contents($configPath, $this->buildPyHankoConfigYaml(
                $rootCaPath,
                $intermediatePaths,
                $this->signaturePath,
                $this->buildStampText($this->includeName, $this->includeDate),
                $this->buildStampLayoutYaml($fieldRect)
            ));

            // --chain (on the `pkcs12` subcommand) embeds the intermediate(s) inside
            // the CMS SignedData itself - the actual "certificate chain bundling"
            // this signature carries. The root CA is deliberately not embedded here -
            // it's a trust anchor the verifier is expected to already know, not
            // something the document ships. Trust/other-certs/key-usage all live in
            // the --config file instead of separate --trust/--other-certs flags, so
            // there's one unambiguous place defining what pyHanko will accept.
            $command = [
                config('services.pnpki.pyhanko_bin'),
                '--verbose', // surfaces the full Python traceback in error_output on failure, not just "Generic processing error"
                '--config', $configPath,
                'sign', 'addsig',
                '--field', $this->buildFieldSpec($fieldRect),
                '--style-name', 'pnpki',
                // --use-pades-lta (not just --with-validation-info) is required to get a
                // genuine PAdES subfilter at all. Traced in pyHanko's own CLI source
                // (cli/commands/signing/__init__.py): without --use-pades/--use-pades-lta,
                // the subfilter silently defaults to the legacy adbe.pkcs7.detached format,
                // under which --with-validation-info embeds OCSP/CRL data *inside the CMS
                // blob* instead of the PDF's /DSS - so the signature validated fine (via
                // our own explicit --validation-context doing a live check) but carried no
                // actual LTV data for a normal viewer to find. --use-pades-lta additionally
                // appends a document timestamp after the DSS is written, so this now
                // produces PAdES-B-LTA (a strict superset of the B-LT this project targets).
                '--use-pades-lta',
                '--timestamp-url', $tsaUrl,
                '--validation-context', 'default',
                'pkcs12',
            ];

            foreach ($intermediatePaths as $intermediatePath) {
                $command[] = '--chain';
                $command[] = $intermediatePath;
            }

            $command[] = '--passfile';
            $command[] = $passfilePath;
            $command[] = $unsignedPath;
            $command[] = $signedPath;
            $command[] = $certPath;

            $result = Process::timeout(60)->run($command);

            if ($result->failed()) {
                // Safe to log in full: the password never appears on the command line or
                // in pyHanko's own output (it's read from the passfile, never echoed).
                Log::error('PNPKI: LTV embedding failed - pyHanko signing step did not succeed.', [
                    'esignature_signing_id' => $this->signing->id,
                    'exit_code' => $result->exitCode(),
                    'output' => $result->output(),
                    'error_output' => $result->errorOutput(),
                ]);

                throw new RuntimeException('PNPKI: LTV embedding failed during signing (see logs for exit code).');
            }

            $this->assertLtvSignatureValid($signedPath, $configPath);
        } finally {
            @unlink($passfilePath);
            @unlink($configPath);
            @unlink($certPath);
            @unlink($rootCaPath);
            foreach ($intermediatePaths as $intermediatePath) {
                @unlink($intermediatePath);
            }
        }
    }

    /**
     * validation-contexts.default in pyHanko's config format: trust anchor, chain
     * certs for path building, and the signer key usage policy.
     *
     * IMPORTANT (found by tracing pyHanko's own source, not assumed): the
     * signer-key-usage list here is required-ALL, not required-ANY. pyHanko has a
     * separate KeyUsageConstraints.match_all_key_usages=false "any one suffices"
     * mode, but the presign-validation code path used during signing
     * (PdfSigner -> _perform_presign_signer_validation -> CertificateValidator
     * .async_validate_usage) discards that flexibility and does plain set
     * subtraction against the raw key_usage set - every listed usage must be
     * present simultaneously, confirmed via a debug trace against the actual
     * installed library. So this must list only digital_signature, not both
     * digital_signature and non_repudiation together (listing both would require
     * the cert to have both, which defeats the purpose).
     *
     * This is a deliberate relaxation, decided with the user: DICT's PNPKI issues
     * certificates for distinct purposes (Gov Authentication CA vs Gov Signing CA),
     * and the certificate in use here is an Authentication-purpose certificate -
     * Key Usage "Digital Signature", not "Non Repudiation". Adobe Acrobat accepts
     * this certificate for signing (it only requires a general signing-capable key
     * usage bit); pyHanko's stricter default (non_repudiation only) does not. Both
     * tools are internally consistent, just calibrated to different strictness
     * levels for the same underlying X.509 semantics. digital_signature is a
     * baseline bit present on essentially any signing-capable certificate
     * (including a properly-issued Gov Signing CA one), so this does not need to
     * change again once a Signing-purpose certificate is in use - it will keep
     * working, just with a stronger cert underneath. Everything else (chain,
     * revocation, timestamp) is still fully validated - only this specific
     * key-usage bit requirement is relaxed.
     *
     * Also defines a `stamp-styles.pnpki` entry, selected on the CLI via
     * --style-name, that uses the applicant's hand-drawn signature PNG as the
     * visible signature appearance's background, plus a stamp-text template
     * built by buildStampText() reflecting the applicant's Name/Date
     * appearance choices from their saved ESignatureSetting (mirrors Adobe
     * Acrobat's "Customize the Signature Appearance" dialog, minus the
     * fields that have no equivalent here).
     */
    private function buildPyHankoConfigYaml(string $rootCa, array $intermediates, string $signatureImagePath, string $stampText, string $stampLayoutYaml): string
    {
        $yaml = "validation-contexts:\n";
        $yaml .= "    default:\n";
        $yaml .= '        trust: '.$this->yamlString($rootCa)."\n";

        if (! empty($intermediates)) {
            $yaml .= "        other-certs:\n";
            foreach ($intermediates as $path) {
                $yaml .= '            - '.$this->yamlString($path)."\n";
            }
        }

        $yaml .= "        signer-key-usage:\n";
        $yaml .= "            - digital_signature\n";

        $yaml .= "stamp-styles:\n";
        $yaml .= "    pnpki:\n";
        $yaml .= '        background: '.$this->yamlString($signatureImagePath)."\n";
        $yaml .= "        background-opacity: 1.0\n";
        $yaml .= '        stamp-text: '.$this->yamlString($stampText)."\n";
        // BaseStampStyle's default border_width is 3 (points), which draws a
        // rectangle around the whole widget - the box outline visible in Adobe
        // around the signature/text. 0 disables it entirely.
        $yaml .= "        border-width: 0\n";
        // Explicit font_size, confirmed via pyHanko's own pdf_utils/text.py to be
        // a real, supported key. Bumped 8 -> 14 (round 8, 2026-08-20, explicit
        // "make the font more bigger, to be readable") alongside giving the text
        // row more height in buildStampLayoutYaml() - bumping font_size alone
        // doesn't help if the row itself doesn't grow too, since pyHanko
        // auto-shrinks the whole text block to fit whatever room it's given.
        $yaml .= "        text-box-style:\n";
        $yaml .= "            font_size: 14\n";
        $yaml .= $stampLayoutYaml;

        return $yaml;
    }

    /**
     * Builds the pyHanko stamp-text template for the signer's saved Name/Date
     * appearance choices (ESignatureSetting.include_name/include_date).
     * %(signer)s is pyHanko's own interpolation param, resolving to the
     * certificate's subject name - the authoritative, cryptographically
     * verified identity, so this stays a real placeholder rather than an
     * app-side name. The timestamp is NOT %(ts)s (pyHanko's own placeholder,
     * see below) - it's computed here directly and baked in as literal text.
     * Never returns an empty string: pyHanko's TextStampStyle always renders
     * a text box, and a blank one looks like a rendering bug rather than a
     * deliberate choice.
     *
     * Why not %(ts)s: confirmed directly in pyHanko's own source
     * (pyhanko/stamp/text.py) that it's populated via
     * `datetime.now(tz=tzlocal.get_localzone())` - the *container's* OS
     * timezone (UTC here), not Philippine time - formatted with no AM/PM by
     * default. Changing the container's actual system timezone to fix this
     * would be a much bigger, riskier change (affects every process in the
     * container) for what's a cosmetic caption. Laravel's own
     * config('app.timezone') is already Asia/Manila, so a plain now() here
     * already gives correct Philippine local time - just needs the right
     * format. This is computed moments before the real pyHanko invocation,
     * so it's a close approximation of the actual signing moment, not a
     * substitute for it - the real, cryptographically-embedded TSA timestamp
     * remains independently verifiable regardless of what this caption says.
     */
    private function buildStampText(bool $includeName, bool $includeDate): string
    {
        $timestamp = now()->format('F j, Y g:i:s A');

        if ($includeName && $includeDate) {
            return "Digitally Signed by %(signer)s\n{$timestamp}";
        }

        if ($includeName) {
            return 'Digitally Signed by %(signer)s';
        }

        if ($includeDate) {
            return "Digitally Signed.\n{$timestamp}";
        }

        return 'Digitally Signed.';
    }

    /**
     * Reads a reserved signature area sidecar, {dir}/signature_field.json
     * (PDF points, bottom-left origin), if one was written alongside
     * unsigned.pdf. No current caller writes this sidecar (the unsigned PDF
     * is rendered by dompdf from a Blade view, not the TCPDF-based prototype
     * renderer that used to produce it) - both buildFieldSpec() and
     * buildStampLayoutYaml() substitute DEFAULT_FIELD_RECT when this returns
     * null, rather than a bare unpositioned field name: pyHanko rejects a
     * visible signature with no coordinates outright (confirmed empirically,
     * not a graceful fallback of its own). The signature produced is still a
     * fully valid PAdES-B-LTA signature either way; only the on-page
     * placement of the visible stamp differs. Precise, content-aware
     * placement is a deliberately deferred fast-follow.
     */
    private function resolveFieldRect(): ?array
    {
        $dir = dirname($this->signing->unsigned_path);
        $path = "{$dir}/signature_field.json";

        if (! Storage::disk('esignature')->exists($path)) {
            return null;
        }

        $field = json_decode(Storage::disk('esignature')->get($path), true);

        if (! is_array($field) || ! isset($field['page'], $field['x1'], $field['y1'], $field['x2'], $field['y2'])) {
            return null;
        }

        return $field;
    }

    /**
     * Defensive clamp so a future edit to DEFAULT_FIELD_RECT specifically
     * can't silently reintroduce the exact text/caption collision fixed in
     * buildStampLayoutYaml() below, or worse, produce a negative/zero-height
     * image row. This is a real, not hypothetical, risk in this codebase
     * specifically: the sibling coordinate file
     * (storage/app/templates/leave_mapping.php) has already had two genuine
     * hand-edit mistakes this same session (a duplicate-coordinate bug and a
     * broken array-nesting bug), both caught only because they were visually
     * obvious on the rendered form - a field-rect mistake here would be much
     * easier to miss, since it can silently degrade the *stamp* without
     * touching any of the actual leave data fields.
     *
     * Deliberately ONLY ever called on DEFAULT_FIELD_RECT (see both call
     * sites below) - never on a signature_field.json sidecar rect. A sidecar
     * is a deliberate, already-vetted placement for whatever area of the
     * form it targets (e.g. the Department Head co-signing rect near "7.B
     * RECOMMENDATION" in leave_mapping.php's own approver_signature_field,
     * a completely different part of the page than the applicant's own
     * caption-adjacent area these bounds were measured against) - clamping
     * it against the *applicant's* safe zone is a real bug, not a safety net:
     * confirmed empirically when the approver rect (y1=317) got silently
     * rejected and replaced with the applicant's own box, because 317 falls
     * outside PRINTED_CAPTION_TOP_Y (330), a boundary that has nothing to do
     * with the area the approver rect actually lives in.
     *
     * Clamps y1/y2 into [PRINTED_CAPTION_TOP_Y, PRINTED_LINE_ABOVE_BOTTOM_Y].
     * If the clamped height would be too short for even one compact text line
     * (MIN_SAFE_HEIGHT), falls back to DEFAULT_FIELD_RECT wholesale rather
     * than returning a degenerate rect - mirrors the max(0, ...) guards
     * already in buildStampLayoutYaml(), just applied one level up, at the
     * rect itself rather than only its internal image/text split.
     */
    private function clampFieldRectToSafeArea(array $fieldRect): array
    {
        $y1 = max($fieldRect['y1'], self::PRINTED_CAPTION_TOP_Y);
        $y2 = min($fieldRect['y2'], self::PRINTED_LINE_ABOVE_BOTTOM_Y);

        if ($y2 - $y1 < self::MIN_SAFE_HEIGHT) {
            Log::warning('PNPKI: field rect too short after safe-area clamp - falling back to DEFAULT_FIELD_RECT.', [
                'esignature_signing_id' => $this->signing->id,
                'candidate_rect' => $fieldRect,
                'clamped_y1' => $y1,
                'clamped_y2' => $y2,
            ]);

            return self::DEFAULT_FIELD_RECT;
        }

        return array_merge($fieldRect, ['y1' => $y1, 'y2' => $y2]);
    }

    /**
     * Places the real pyHanko signature field over the same spot the hand-drawn
     * signature image was rendered at, so the cryptographic signature becomes
     * an actual clickable widget instead of an invisible zero-rect field
     * disconnected from what the page visually shows as a signature.
     */
    private function buildFieldSpec(?array $fieldRect): string
    {
        // Clamp only applies to the applicant's own DEFAULT_FIELD_RECT - a
        // provided sidecar rect (e.g. the approver co-signing area) targets a
        // different part of the form and must not be measured against these
        // bounds. See clampFieldRectToSafeArea()'s own docblock.
        $fieldRect = $fieldRect ?? $this->clampFieldRectToSafeArea(self::DEFAULT_FIELD_RECT);

        // Field name defaults to the literal 'Signature' - the original single-signer
        // flow's exact prior behavior. A co-signing pass targeting a document that
        // already has a field named 'Signature' must use a distinct name (set on the
        // EsignatureSigning row when it's dispatched), since pyHanko creates a new
        // AcroForm field per addsig call.
        $fieldName = $this->signing->field_name ?: 'Signature';

        return sprintf(
            '%d/%d,%d,%d,%d/%s',
            $fieldRect['page'],
            $fieldRect['x1'],
            $fieldRect['y1'],
            $fieldRect['x2'],
            $fieldRect['y2'],
            $fieldName
        );
    }

    /**
     * Splits the signature widget into two non-overlapping ROWS - signature
     * graphic on top, stamp text below - rather than pyHanko's own default of
     * centering background_layout and inner_content_layout within the *same*
     * box (background is designed to be used as a low-opacity watermark
     * behind text, per its default background_opacity of 0.6), which would
     * visibly collide with the stamp text at the full opacity this app uses
     * for the hand-drawn image. Stacked (not side-by-side) per the user's own
     * choice 2026-08-19, once DEFAULT_FIELD_RECT was widened/heightened for a
     * bigger, less cramped stamp - a wide-but-short box suits an image-on-top
     * band better than squeezing both into narrow side-by-side columns.
     * $fieldRect is never actually null by the time this runs - buildFieldSpec()
     * already substituted DEFAULT_FIELD_RECT for the null case - but the same
     * substitution is repeated here defensively so this method still
     * produces a sane layout if ever called on its own. Also re-applies the
     * same null-only clamp for the same reason - this method can be (and is,
     * in tests) called independently of buildFieldSpec(), so the safety net
     * has to live in both places rather than being trusted to whichever
     * caller happens to run first. As with buildFieldSpec(), a provided
     * sidecar rect is never clamped - see clampFieldRectToSafeArea()'s own
     * docblock for why that would be a bug, not a safety net, for an area
     * like the approver co-signing rect.
     *
     * Text row is a FIXED height, not a ratio of the box - it only ever needs
     * about as much room as the stamp text has lines, regardless of how tall
     * DEFAULT_FIELD_RECT is, and giving the image everything else is what
     * lets it be as large as the box allows. (Historical note: rounds 2-5
     * deliberately let this box cross the "(Signature of Applicant)" caption
     * line, per the user's own choice at the time - round 6's 30pt upward
     * move puts the whole box above the caption's own clip region entirely,
     * so that's no longer actually happening, though it wasn't the point of
     * the round-6 move either.)
     *
     * Doubled from 9 to 18 (round 5, 2026-08-20) now that buildStampText()
     * returns two lines (name, then timestamp) instead of one - the image
     * row shrinks correspondingly (26 -> 17) to make room, still a
     * reasonable size, bigger than round 1's original 14pt.
     *
     * Grown further to 24 (round 8, 2026-08-20, "make the font more bigger,
     * to be readable") - giving the text row more of its own room is what
     * actually lets a bigger font_size (see buildPyHankoConfigYaml()) render
     * larger rather than just getting shrunk back down to fit, confirmed via
     * a real pyHanko render (see this round's own verification). Text
     * y-align also switched from bottom to mid ("make the text center
     * aligned") - x-align was already mid on both layers since round 3.
     *
     * Rebalanced 2026-08-20 (round 9, "the image e-signature is small [...]
     * make it bigger, and reduce/remove the spacing"): the image row is now
     * the fixed, driving value (20, up from round 8's leftover 11) with text
     * taking whatever remains, the reverse of round 8's text-driven split -
     * text shrinks somewhat as a result, which is the direct trade-off of
     * this round's stated priority. $margin dropped 3 -> 1 and $gap dropped
     * 2 -> 0 to reclaim a little more room for both and to literally remove
     * the gap between the two elements, per the same request.
     *
     * Also fixes a real bug found while touching this: $textTopMargin was
     * computed as `imageRowHeight + gap`, omitting the image's own leading
     * top $margin - so the text region actually started slightly *before*
     * the image region ended (a ~1-3pt overlap depending on the numbers in
     * play each round), rather than leaving the intended gap. Corrected to
     * `margin + imageRowHeight + gap`, which is what the two regions'
     * geometry actually requires.
     *
     * Branches to a side-by-side (column) split instead of the stacked (row)
     * split above when the box is too short for stacking to work at all -
     * added 2026-08-21 after the Department Head co-signing area (a genuinely
     * short-but-wide ~15pt-tall, 193pt-wide box in "7.B RECOMMENDATION" - see
     * leave_mapping.php's approver_signature_field) confirmed the stacked
     * split's image row alone could consume the *entire* box height, leaving
     * literally nothing for text - not a small/cramped result but a hard
     * zero: the real pyHanko output showed the text's own `cm` scale collapse
     * to 0 (invisible), not just small. A short-but-wide box has width to
     * spare, so splitting that dimension instead - rather than fighting for
     * height neither element has enough of - is what actually makes both
     * visible. MIN_STACKED_HEIGHT (20) is comfortably below the applicant's
     * own ~15-37pt-tall boxes seen across this file's history, so this only
     * changes behavior for a box shorter than any of those.
     */
    private function buildStampLayoutYaml(?array $fieldRect): string
    {
        $fieldRect = $fieldRect ?? $this->clampFieldRectToSafeArea(self::DEFAULT_FIELD_RECT);

        $boxWidth = $fieldRect['x2'] - $fieldRect['x1'];
        $boxHeight = $fieldRect['y2'] - $fieldRect['y1'];

        if ($boxHeight < self::MIN_STACKED_HEIGHT) {
            $margin = 1;
            $gap = 4;
            $imageColumnWidth = max(0, (int) round(($boxWidth - $gap) * 0.35));
            $backgroundRightMargin = max($margin, $boxWidth - $margin - $imageColumnWidth);
            $textLeftMargin = max($margin, $imageColumnWidth + $gap);

            $yaml = "        background-layout:\n";
            $yaml .= "            x-align: mid\n";
            $yaml .= "            y-align: mid\n";
            $yaml .= "            margins:\n";
            $yaml .= "                left: {$margin}\n";
            $yaml .= "                right: {$backgroundRightMargin}\n";
            $yaml .= "                top: {$margin}\n";
            $yaml .= "                bottom: {$margin}\n";
            $yaml .= "        inner-content-layout:\n";
            $yaml .= "            x-align: mid\n";
            $yaml .= "            y-align: mid\n";
            $yaml .= "            margins:\n";
            $yaml .= "                left: {$textLeftMargin}\n";
            $yaml .= "                right: {$margin}\n";
            $yaml .= "                top: {$margin}\n";
            $yaml .= "                bottom: {$margin}\n";

            return $yaml;
        }

        $margin = 1;
        $gap = 0;
        $imageRowHeight = min(20, max(0, $boxHeight - (2 * $margin) - $gap));
        $backgroundBottomMargin = max($margin, $boxHeight - $margin - $imageRowHeight);
        $textTopMargin = $margin + $imageRowHeight + $gap;

        $yaml = "        background-layout:\n";
        $yaml .= "            x-align: mid\n";
        $yaml .= "            y-align: top\n";
        $yaml .= "            margins:\n";
        $yaml .= "                left: {$margin}\n";
        $yaml .= "                right: {$margin}\n";
        $yaml .= "                top: {$margin}\n";
        $yaml .= "                bottom: {$backgroundBottomMargin}\n";
        $yaml .= "        inner-content-layout:\n";
        $yaml .= "            x-align: mid\n";
        $yaml .= "            y-align: mid\n";
        $yaml .= "            margins:\n";
        $yaml .= "                left: {$margin}\n";
        $yaml .= "                right: {$margin}\n";
        $yaml .= "                top: {$textTopMargin}\n";
        $yaml .= "                bottom: {$margin}\n";

        return $yaml;
    }

    /**
     * Escapes a value for a YAML double-quoted scalar. Backslashes must be escaped
     * first (otherwise the backslash inserted for a later replacement would itself
     * get re-escaped), and a literal newline must become the two-character `\n`
     * escape sequence - an unescaped raw newline inside a double-quoted YAML scalar
     * is invalid syntax. buildStampText() is the reason this needs newline handling
     * at all; every other caller only ever passes single-line file paths.
     */
    private function yamlString(string $value): string
    {
        $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);

        return '"'.$escaped.'"';
    }

    /**
     * Verify the produced PDF actually validates (revocation-checked) rather than
     * trusting pyHanko's exit code alone - this is what makes "do not silently
     * fall back to an unvalidated signature" concrete rather than just documentation.
     * --force-revinfo makes pyHanko itself refuse to certify validity if revocation
     * data turns out to be missing, reinforcing this check rather than relying on
     * our own inspection of the output. Reuses the same --config/--validation-context
     * as signing, so this check enforces the same (deliberately relaxed) key usage
     * policy the signing step itself was allowed to proceed under - otherwise this
     * would reject a signature pyHanko itself just produced.
     */
    private function assertLtvSignatureValid(string $signedPath, string $configPath): void
    {
        $command = [
            config('services.pnpki.pyhanko_bin'),
            '--config', $configPath,
            'sign', 'validate', '--pretty-print', '--force-revinfo',
            '--validation-context', 'default',
            $signedPath,
        ];

        $result = Process::timeout(30)->run($command);

        if ($result->failed()) {
            Log::error('PNPKI: LTV embedding failed - signed PDF did not pass pyHanko validation.', [
                'esignature_signing_id' => $this->signing->id,
                'exit_code' => $result->exitCode(),
                'output' => $result->output(),
            ]);

            throw new RuntimeException('PNPKI: signed PDF failed post-signing LTV validation (see logs).');
        }
    }

    public function failed(Throwable $e): void
    {
        // Laravel only calls failed() once all retries ($tries/$backoff above) are
        // exhausted, so this is the right point to mark the signing attempt as
        // permanently failed - the polling UI uses this to stop telling the
        // user to "keep checking back" once there's nothing left to wait for.
        $this->signing->markFailed($e->getMessage() ?: 'Signing failed.');

        HRAuditTrail::create([
            'actor_user_id' => null,
            'module' => 'esignature',
            'action' => 'esignature_signing_failed',
            'target_type' => EsignatureSigning::class,
            'target_id' => $this->signing->id,
            'details' => [
                'signable_type' => $this->signing->signable_type,
                'signable_id' => $this->signing->signable_id,
                'error' => $e->getMessage(),
            ],
        ]);

        Log::error('PNPKI signing job failed.', [
            'esignature_signing_id' => $this->signing->id,
            'error' => $e->getMessage(),
        ]);
    }

    private function assertPnpkiConfigured(): void
    {
        // The certificate/chain material is resolved from the signer's saved
        // ESignatureSetting in handle() above; this guards against a
        // misconfigured/incomplete setting (e.g. an empty stored file)
        // rather than assuming handle() always produces well-formed material.
        if (empty($this->certificate)) {
            throw new RuntimeException('PNPKI signing requires a certificate.');
        }

        if (empty($this->password)) {
            throw new RuntimeException('PNPKI signing requires a certificate password.');
        }

        if (empty($this->chainRootCa)) {
            throw new RuntimeException('PNPKI signing requires the trust chain root CA certificate.');
        }

        foreach ($this->chainIntermediates as $index => $intermediateBytes) {
            if (empty($intermediateBytes)) {
                throw new RuntimeException("PNPKI signing requires a non-empty intermediate certificate at index [{$index}].");
            }
        }
    }

    /**
     * Send a real (minimal) RFC 3161 request to the TSA before ever invoking pyHanko,
     * so a bad TSA is caught cheaply and with a clear, specific log message - rather
     * than relying on a plain connectivity ping, which is unreliable here: DICT's
     * SignServer TSA worker only responds to a properly-formed TimeStampReq POST and
     * will look "down" to a basic health check even when it's working fine.
     */
    private function assertTsaAvailable(string $tsaUrl, Rfc3161TimestampClient $tsaClient): void
    {
        $digest = hash('sha256', 'PNPKI TSA pre-flight check '.$this->signing->id.' '.now()->toIso8601String(), true);
        $result = $tsaClient->query($tsaUrl, $tsaClient->buildRequest($digest));

        if ($result['unreachable']) {
            Log::error('PNPKI: TSA unreachable.', [
                'esignature_signing_id' => $this->signing->id,
                'detail' => $result['statusText'],
            ]);

            throw new RuntimeException('PNPKI: TSA unreachable; refusing to produce an unvalidated signature.');
        }

        if (! $result['granted']) {
            Log::error('PNPKI: TSA rejected request.', [
                'esignature_signing_id' => $this->signing->id,
                'status' => $result['status'],
                'detail' => $result['statusText'],
            ]);

            throw new RuntimeException('PNPKI: TSA rejected the timestamp request; refusing to produce an unvalidated signature.');
        }

        Log::info('PNPKI: TSA pre-flight check succeeded.', [
            'esignature_signing_id' => $this->signing->id,
            'detail' => $result['statusText'],
        ]);
    }
}
