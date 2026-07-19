<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PersonnelLogImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recomputes DTR for every active, non-exempt employee on a single date after
 * a work suspension is declared, edited, or removed - so already-imported
 * punches on that date re-resolve against the (now updated) suspension state.
 * Company-wide, unlike a DtrExcuse recompute (which is per-employee), so this
 * is dispatched instead of run synchronously - modeled on BulkShiftRecomputeJob.
 */
class WorkSuspensionRecomputeJob implements ShouldQueue
{
    use Queueable;

    // Re-running is safe (recompute is idempotent), but we don't want
    // automatic retries silently re-processing the whole company on failure.
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $date) {}

    public function handle(PersonnelLogImportService $importService): void
    {
        User::active()->where('dtr_exempt', false)
            ->chunkById(100, function ($users) use ($importService): void {
                foreach ($users as $user) {
                    $importService->recomputeDtr($user, $this->date, $this->date);
                }
            });
    }
}
