<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PersonnelLogImportService;
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

    public function __construct(public readonly array $userIds) {}

    public function handle(PersonnelLogImportService $importService): void
    {
        User::whereIn('id', $this->userIds)->chunkById(100, function ($users) use ($importService): void {
            foreach ($users as $user) {
                $importService->recomputeFullRange($user);
            }
        });
    }
}
