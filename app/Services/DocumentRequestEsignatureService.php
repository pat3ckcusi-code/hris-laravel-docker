<?php

namespace App\Services;

use App\Jobs\SignESignatureRequestPdfJob;
use App\Models\DocumentRequest;
use App\Models\EsignatureSigning;
use App\Models\HRAuditTrail;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Drives the Front Desk -> HR Manager document-request signing handoff and
 * renders the PDF that gets signed. Document requests have no pre-made
 * fillable PDF template (unlike LEAVE.pdf), so the unsigned PDF is rendered
 * fresh via dompdf from frontdesk/print-pdf.blade.php instead of stamped
 * onto a template via FPDI the way LeaveRequestService::buildEsignaturePdfBytes()
 * does.
 *
 * dispatchHrManagerSigning() always dispatches with field_name = null,
 * treating the HR Manager as the document's sole/base signer - exactly like
 * how LeaveRequestController::dispatchEsignatureSigning() treats the leave
 * applicant's own base signature. SignESignatureRequestPdfJob only calls its
 * LeaveRequest-typed resolveCoSigningBasePdf() when field_name !== null, so
 * this keeps the job, EsignatureSigning, and the Signable interface fully
 * unmodified - no co-signing "prior signature" concept is needed here since
 * a document request is never signed by more than one person.
 *
 * "Is it signed" is never a separate persisted flag to branch queries on -
 * it's always derived from whether a completed, field_name IS NULL
 * EsignatureSigning row exists for the document, mirroring
 * LeaveRequestService::forwardedForSigningQuery()'s own philosophy. The one
 * deliberate persisted exception is DocumentRequest.signed_by/signed_at,
 * written by EsignatureSigningObserver once signing actually completes.
 */
class DocumentRequestEsignatureService
{
    /**
     * @return array{bytes: string, field_rect: array{page: int, x1: float, y1: float, x2: float, y2: float}}
     */
    public function renderUnsignedPdf(DocumentRequest $documentRequest): array
    {
        $documentRequest->loadMissing(['employee.department', 'documentType']);

        $pdf = Pdf::loadView('frontdesk.print-pdf', [
            'documentRequest' => $documentRequest,
            'template' => $documentRequest->documentType->parts ?? [],
            'employee' => $documentRequest->employee,
            'replacements' => DocumentPlaceholderResolver::resolve($documentRequest->employee),
        ])->setPaper('letter');

        $bytes = $pdf->output();
        $pageCount = $pdf->getDomPDF()->getCanvas()->get_page_count();

        return [
            'bytes' => $bytes,
            // Must match frontdesk/print-pdf.blade.php's .signature-area rule exactly
            // (left:0.9in/right:0.9in/bottom:3.25in/height:0.55in -> x1/x2/y1/y2 in
            // pt, 1in = 72pt): x1 = 0.9in, x2 = 8.5in (letter width) - 0.9in,
            // y1 = 3.25in, y2 = 3.25in + 0.55in. bottom was nudged down from 3.35in
            // to 3.25in (the user reported visible dead space between the stamp and
            // the printed name below it, once signed for real - buildStampLayoutYaml()
            // fills nearly this whole box with only ~1pt internal margins top/bottom,
            // so the box's own bottom edge is what the gap-to-name is really governed
            // by). Deliberately lands directly above
            // the primary (first-listed) signatory's printed name in
            // .primary-sig-block (bottom:2.6in), which in turn clears .footer
            // (bottom:0.5in) - every one of these three is independently
            // `position: fixed` at its own bottom offset, not stacked in normal
            // flow, so none of their positions depend on the others' actual
            // rendered height.
            //
            // These bottom offsets are deliberately NOT anchored close to the page's
            // true bottom edge (unlike a typical fixed footer) - @page's own bottom
            // margin only bounds how far normal-flow body/closing-remark text is
            // ALLOWED to reach, it has no effect on where a position:fixed element
            // actually renders (confirmed empirically). Anchoring this whole zone
            // near the true page bottom left a large, unbalanced-looking blank gap
            // between a short document's body and the signature block on real
            // output - moving every anchor here further up the page (closer to
            // where this app's short, single-page certificates actually finish
            // their body text) closes most of that gap.
            //
            // The 0.9in -> 0.55in height reduction (and this rect's exact y1/y2)
            // came from a second real-world round: once the header/footer images
            // were sized to their correct, full-content-width aspect ratio (see
            // .doc-header-img/.doc-footer-img below - previously undersized at a
            // fixed small `width`), the now-taller header pushed the body's own
            // end position down far enough that the original 0.9in-tall stamp box
            // barely cleared it (~15pt gap). Rather than push the whole reserved
            // zone down further (it was already near its floor against
            // .primary-sig-block), the box height itself was reduced - proven
            // safe by SignESignatureRequestPdfJob::DEFAULT_FIELD_RECT, whose own
            // production stamp area for leave requests is only 37pt (0.51in)
            // tall; buildStampLayoutYaml() switches to a side-by-side (image
            // beside text) layout below a height threshold rather than needing a
            // tall stacked box, so 0.55in has real headroom over the proven-safe
            // minimum, not just a guess.
            //
            // All of the specific gaps/offsets here were calibrated empirically by
            // rendering real samples and measuring actual glyph positions via
            // PyMuPDF (fitz), not by computing them from font-size/line-height -
            // .footer in particular did not track its own `bottom` value as
            // predictably as .primary-sig-block/.signature-area did (its rendered
            // position came out anywhere from ~1pt to ~27pt off the CSS value
            // across different renders, for reasons not fully understood - possibly
            // an interaction between its padding-top/border-top and dompdf's fixed-
            // position layout), so the gap above it is deliberately generous rather
            // than tightly computed. Also confirmed empirically: <img> elements in
            // normal flow (like .doc-header-img) size relative to <body>'s own
            // content box, which dompdf renders at the FULL page width here,
            // ignoring @page's left/right margins - a `width: 100%` header image
            // rendered edge-to-edge at 8.5in instead of the intended 6.7in content
            // width, hence the explicit `width: 6.7in` below instead of a
            // percentage. Re-verify by rendering + measuring, don't just recompute
            // these numbers on paper, if they're ever adjusted again.
            'field_rect' => [
                'page' => $pageCount,
                'x1' => 64.8,
                'y1' => 234.0,
                'x2' => 547.2,
                'y2' => 273.6,
            ],
        ];
    }

    public function dispatchHrManagerSigning(DocumentRequest $documentRequest, User $hrManager, string $password): EsignatureSigning
    {
        if (! $this->forwardedForSigningQuery()->whereKey($documentRequest->id)->exists()) {
            throw new \RuntimeException('This document is no longer awaiting your signature.');
        }

        ['bytes' => $bytes, 'field_rect' => $fieldRect] = $this->renderUnsignedPdf($documentRequest);

        $token = (string) Str::ulid();
        $dir = "signings/{$token}";

        Storage::disk('esignature')->put("{$dir}/unsigned.pdf", $bytes);
        Storage::disk('esignature')->put("{$dir}/signature_field.json", json_encode($fieldRect));

        $signing = EsignatureSigning::create([
            'signable_type' => DocumentRequest::class,
            'signable_id' => $documentRequest->id,
            'requested_by' => $hrManager->id,
            'field_name' => null,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => "{$dir}/unsigned.pdf",
        ]);

        SignESignatureRequestPdfJob::dispatch($signing, $password)->onQueue('exports');

        HRAuditTrail::create([
            'actor_user_id' => $hrManager->id,
            'module' => 'frontdesk',
            'action' => 'signature_dispatched',
            'target_type' => DocumentRequest::class,
            'target_id' => $documentRequest->id,
            'details' => ['document_type' => $documentRequest->document_type],
        ]);

        return $signing;
    }

    /**
     * Accepted document requests whose document type requires HR e-signature -
     * the pool every other query below narrows further. status stays on the
     * original Requested/Accepted/Completed/Rejected vocabulary throughout;
     * signature_status is an orthogonal column only meaningful once a
     * document is Accepted.
     */
    public function eligibleQuery(): Builder
    {
        return DocumentRequest::where('status', 'Accepted')
            ->whereHas('documentType', fn (Builder $q) => $q->where('requires_esignature', true));
    }

    private function signedSubquery(Builder $query): Builder
    {
        return $query->whereHas('esignatureSignings', fn (Builder $q) => $q
            ->whereNull('field_name')
            ->where('status', EsignatureSigning::STATUS_COMPLETED));
    }

    /**
     * The HR Manager's sign queue - forwarded, not yet signed, and no
     * signing attempt currently in flight for it either.
     */
    public function forwardedForSigningQuery(): Builder
    {
        return $this->eligibleQuery()
            ->where('signature_status', 'forwarded')
            ->whereDoesntHave('esignatureSignings', fn (Builder $q) => $q
                ->whereNull('field_name')
                ->whereIn('status', [
                    EsignatureSigning::STATUS_PENDING,
                    EsignatureSigning::STATUS_PROCESSING,
                    EsignatureSigning::STATUS_COMPLETED,
                ]));
    }

    public function rejectedQuery(): Builder
    {
        return $this->eligibleQuery()->where('signature_status', 'rejected');
    }

    /**
     * Documents with a completed base signing, regardless of their
     * signature_status - the derived "is it signed" state, matching this
     * class's own docblock philosophy.
     */
    public function signedHistoryQuery(): Builder
    {
        return $this->signedSubquery($this->eligibleQuery());
    }

    public function isSigned(DocumentRequest $documentRequest): bool
    {
        return $this->signedSubquery(DocumentRequest::query()->whereKey($documentRequest->id))->exists();
    }

    /**
     * Front Desk review action: sends an Accepted, e-signature-required
     * document to the HR Manager's sign queue. Only valid from a document
     * that hasn't already been forwarded/rejected - re-checked here rather
     * than trusted from the caller's route-model binding.
     */
    public function forward(DocumentRequest $documentRequest, User $actor): void
    {
        if (! $this->eligibleQuery()->whereKey($documentRequest->id)->whereNull('signature_status')->exists()) {
            throw new \RuntimeException('This document is not awaiting forward for signature.');
        }

        $documentRequest->update([
            'signature_status' => 'forwarded',
            'signature_reviewed_by' => $actor->id,
            'signature_reviewed_at' => now(),
            'signature_review_remarks' => null,
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'frontdesk',
            'action' => 'forwarded_for_signature',
            'target_type' => DocumentRequest::class,
            'target_id' => $documentRequest->id,
            'details' => ['document_type' => $documentRequest->document_type],
        ]);
    }

    /**
     * HR Manager review action: declines to sign a forwarded document, with
     * a required reason, sending it back to Front Desk's Rejected view.
     */
    public function reject(DocumentRequest $documentRequest, User $actor, string $remarks): void
    {
        if (! $this->forwardedForSigningQuery()->whereKey($documentRequest->id)->exists()) {
            throw new \RuntimeException('This document is no longer awaiting your signature.');
        }

        $documentRequest->update([
            'signature_status' => 'rejected',
            'signature_reviewed_by' => $actor->id,
            'signature_reviewed_at' => now(),
            'signature_review_remarks' => $remarks,
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'frontdesk',
            'action' => 'signature_rejected',
            'target_type' => DocumentRequest::class,
            'target_id' => $documentRequest->id,
            'details' => ['remarks' => $remarks],
        ]);
    }

    /**
     * Front Desk action: clears a rejected document back to "eligible, not
     * yet forwarded" so it can be corrected and re-forwarded.
     */
    public function reopen(DocumentRequest $documentRequest, User $actor): void
    {
        if ($documentRequest->signature_status !== 'rejected') {
            throw new \RuntimeException('This document is not currently rejected.');
        }

        $previousRemarks = $documentRequest->signature_review_remarks;

        $documentRequest->update([
            'signature_status' => null,
            'signature_reviewed_by' => null,
            'signature_reviewed_at' => null,
            'signature_review_remarks' => null,
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'frontdesk',
            'action' => 'signature_reopened',
            'target_type' => DocumentRequest::class,
            'target_id' => $documentRequest->id,
            'details' => ['previous_remarks' => $previousRemarks],
        ]);
    }
}
