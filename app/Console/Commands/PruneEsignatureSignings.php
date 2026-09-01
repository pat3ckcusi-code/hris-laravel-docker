<?php

namespace App\Console\Commands;

use App\Models\EsignatureSigning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneEsignatureSignings extends Command
{
    protected $signature = 'esignature-signing:prune';

    protected $description = 'Mark stale in-flight signings as failed, delete old failed attempts and their files, and remove superseded completed signing files';

    /**
     * Three independent cleanup passes:
     *
     * 1. Marks stale in-flight (pending/processing) signings as failed after a
     *    6-minute window with no update.
     * 2. Deletes old failed signings (>30 days) outright - both the row and its
     *    whole signings/{ULID} directory (unsigned.pdf plus any
     *    signature_field.json sidecar).
     * 3. deleteSupersededSignedFiles(): for every (signable_type, signable_id)
     *    with more than one completed signing - today only possible for a
     *    LeaveRequest with a Department Head and/or HR Manager co-sign stacked
     *    on top of the applicant's own signature (see
     *    SignESignatureRequestPdfJob::resolveCoSigningBasePdf()) - deletes ONLY
     *    the signed.pdf file of every completed row except the latest (by id)
     *    for that signable. Never deletes the row itself, never touches
     *    unsigned_path (already deleted by the signing job on success - see
     *    SignESignatureRequestPdfJob::handle()), and never calls
     *    deleteDirectory() (that would also remove a signature_field.json
     *    sidecar some rows have - deliberately kept: it records that specific
     *    signing pass's own on-page stamp placement, information the later,
     *    surviving signing's own file/sidecar doesn't preserve, unlike the PDF
     *    content itself). A superseded row's signed_path DB column is left
     *    pointing at a now-deleted file forever - the same already-established
     *    convention as unsigned_path staying stale on every completed row.
     *
     * The invariant this preserves: a completed ROW is never deleted, ever, and
     * a signable's single LATEST completed signing's file is never touched -
     * only a strictly-older, superseded completed row's file is removed, and
     * only once a newer completed row already exists to take over serving that
     * signable. This still protects LeaveRequestController::printSingle() (and
     * every other "latest completed signing" reader) exactly like before: it
     * always resolves and serves the single latest completed row, which pass 3
     * never removes the file for.
     *
     * Structurally a no-op for DocumentRequest: DocumentRequestEsignatureService
     * ::forwardedForSigningQuery() excludes any document that already has a
     * pending/processing/completed base signing from being re-dispatched, so a
     * DocumentRequest can only ever reach exactly one completed row - never a
     * pair for pass 3 to act on.
     */
    public function handle(): int
    {
        $staleCount = EsignatureSigning::whereIn('status', [EsignatureSigning::STATUS_PENDING, EsignatureSigning::STATUS_PROCESSING])
            ->where('updated_at', '<', now()->subSeconds(360))
            ->update(['status' => EsignatureSigning::STATUS_FAILED, 'error_message' => 'Signing timed out. Please try again.', 'failed_at' => now()]);

        $oldFailed = EsignatureSigning::where('status', EsignatureSigning::STATUS_FAILED)
            ->where('updated_at', '<', now()->subDays(30))
            ->get();

        foreach ($oldFailed as $signing) {
            $dir = dirname($signing->unsigned_path);
            Storage::disk('esignature')->deleteDirectory($dir);
            $signing->delete();
        }

        $supersededFileCount = $this->deleteSupersededSignedFiles();

        $this->info("Pruned {$staleCount} stale signing(s), deleted {$oldFailed->count()} old failed signing(s), and removed {$supersededFileCount} superseded signed file(s).");

        return self::SUCCESS;
    }

    private function deleteSupersededSignedFiles(): int
    {
        $deletedCount = 0;

        EsignatureSigning::where('status', EsignatureSigning::STATUS_COMPLETED)
            ->orderBy('id')
            ->get(['id', 'signable_type', 'signable_id', 'signed_path'])
            ->groupBy(fn (EsignatureSigning $signing) => "{$signing->signable_type}:{$signing->signable_id}")
            ->each(function ($signingsForSignable) use (&$deletedCount) {
                // Collection::groupBy() preserves each group's items in the base
                // query's own ascending-id order, so the LAST item in every group
                // is always that signable's current-latest completed signing -
                // never delete it, only whatever comes before it.
                $superseded = $signingsForSignable->slice(0, -1);

                foreach ($superseded as $signing) {
                    // Defensive only: markCompleted() always sets signed_path and
                    // status=completed together, so this shouldn't occur in
                    // practice - guard anyway since Storage::delete() takes a
                    // non-nullable string.
                    if (! $signing->signed_path) {
                        continue;
                    }

                    if (Storage::disk('esignature')->exists($signing->signed_path)) {
                        Storage::disk('esignature')->delete($signing->signed_path);
                        $deletedCount++;
                    }
                }
            });

        return $deletedCount;
    }
}
