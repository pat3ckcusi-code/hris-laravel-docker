<?php

namespace App\Console\Commands;

use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\EmployeeAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPlantillaSalarySync extends Command
{
    protected $signature = 'plantilla:backfill-salary-sync {--dry-run : Preview the changes without writing them}';

    protected $description = "Recompute users.salary_grade/salary_step from each employee's current (date-range, not just open-ended) plantilla assignment, fixing rows wrongly nulled/stale by the old open-ended-only definition";

    public function handle(EmployeeAssignmentService $employeeAssignmentService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $unchanged = 0;
        $samples = [];

        $employeeIds = EmployeeAssignment::query()->distinct()->pluck('employee_id');

        $run = function () use ($employeeIds, $employeeAssignmentService, $dryRun, &$updated, &$unchanged, &$samples) {
            User::whereIn('id', $employeeIds)
                ->orderBy('id')
                ->chunkById(200, function ($users) use ($employeeAssignmentService, $dryRun, &$updated, &$unchanged, &$samples) {
                    foreach ($users as $user) {
                        $target = $employeeAssignmentService->currentSalaryFor($user->id);

                        if ($target['salary_grade'] === $user->salary_grade && $target['salary_step'] === $user->salary_step) {
                            $unchanged++;

                            continue;
                        }

                        if (count($samples) < 10) {
                            $samples[] = sprintf(
                                '#%d SG %s→%s, Step %s→%s',
                                $user->id,
                                $user->salary_grade ?? 'null',
                                $target['salary_grade'] ?? 'null',
                                $user->salary_step ?? 'null',
                                $target['salary_step'] ?? 'null'
                            );
                        }

                        if (! $dryRun) {
                            $employeeAssignmentService->syncUserSalary($user->id);
                        }

                        $updated++;
                    }
                });
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        foreach ($samples as $sample) {
            $this->line($sample);
        }

        $this->newLine();
        $mode = $dryRun ? '[DRY RUN] Would update' : 'Updated';
        $this->info("{$mode} {$updated} user(s). Already correct: {$unchanged}.");

        return self::SUCCESS;
    }
}
