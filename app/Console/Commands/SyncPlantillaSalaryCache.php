<?php

namespace App\Console\Commands;

use App\Services\EmployeeAssignmentService;
use Illuminate\Console\Command;

/**
 * Flips users.salary_grade/salary_step forward/back to whatever
 * employee_assignments row covers today, so a future-dated promotion or
 * assignment's start date - or one expiring - takes effect in the cache
 * without anyone re-triggering it manually. Mirrors shift:sync-cache's
 * identical daily sync for users.shift_id.
 */
class SyncPlantillaSalaryCache extends Command
{
    protected $signature = 'plantilla:sync-salary-cache';

    protected $description = "Sync users.salary_grade/salary_step to whichever employee_assignments row covers today's date";

    public function handle(EmployeeAssignmentService $employeeAssignmentService): int
    {
        $updated = $employeeAssignmentService->syncAllSalaryCaches();

        $this->info("Synced salary cache for {$updated} user(s).");

        return self::SUCCESS;
    }
}
