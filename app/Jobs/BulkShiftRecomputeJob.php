<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Attendance\WeeklyPunchPairReconciliationService;
use App\Services\PersonnelLogImportService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recomputes DTR for every employee in a bulk shift assignment. The shift_id
 * update itself is a single fast query done synchronously by
 * EmployeeScheduleController::bulkAssign(); only the potentially slow
 * per-employee DTR recompute is deferred here.
 */
class BulkShiftRecomputeJob implements ShouldQueue
{
    use Queueable;

    // Re-running is safe (recompute is idempotent), but we don't want
    // automatic retries silently re-processing a large employee set on failure.
    public int $tries = 1;

    // Allow up to 10 minutes for large employee sets.
    public int $timeout = 600;

    /**
     * $reconcileSince (Y-m-d, nullable) mirrors
     * EmployeeScheduleController::reconcileEagerlyIfNeeded() for the bulk
     * path: set only when the bulk submission actually produced an
     * in_only/out_only group (a Field Work Pair shift, or a hand-built
     * matching per-day grid), so a backdated effective_from's already-elapsed
     * weeks get voided immediately for every affected employee rather than
     * sitting on the plain "No Punch Required" gap label until the next
     * scheduled sweep. Left null for an ordinary bulk assignment, which never
     * needs this call at all.
     */
    public function __construct(
        public readonly array $userIds,
        public readonly ?string $reconcileSince = null,
    ) {}

    public function handle(PersonnelLogImportService $importService, WeeklyPunchPairReconciliationService $reconciliation): void
    {
        $since = $this->reconcileSince !== null ? Carbon::parse($this->reconcileSince) : null;

        User::whereIn('id', $this->userIds)->chunkById(100, function ($users) use ($importService, $reconciliation, $since): void {
            foreach ($users as $user) {
                $importService->recomputeFullRange($user);

                if ($since !== null) {
                    $reconciliation->reconcileForUser($user, $since);
                }
            }
        });
    }
}
