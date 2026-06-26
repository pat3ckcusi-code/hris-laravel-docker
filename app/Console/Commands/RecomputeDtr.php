<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\PersonnelLogImportService;
use Illuminate\Console\Command;

class RecomputeDtr extends Command
{
    protected $signature = 'dtr:recompute
        {--from= : Start date (Y-m-d); defaults to the earliest attendance log}
        {--to=   : End date (Y-m-d); defaults to the latest attendance log}
        {--user= : Restrict to a single user ID}';

    protected $description = 'Rebuild dtrs rows from attendance_logs for all employees using the current DtrPunchResolver logic';

    public function handle(PersonnelLogImportService $service): int
    {
        $from = $this->option('from') ?: AttendanceLog::min('logdate');
        $to = $this->option('to') ?: AttendanceLog::max('logdate');

        if (! $from || ! $to) {
            $this->warn('No attendance_logs found - nothing to recompute.');

            return self::SUCCESS;
        }

        $userIds = AttendanceLog::query()
            ->when($this->option('user'), fn ($q) => $q->where('user_id', $this->option('user')))
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            $this->warn('No matching employees with attendance logs.');

            return self::SUCCESS;
        }

        $this->info("Recomputing DTRs for {$userIds->count()} employee(s), {$from} → {$to}");
        $bar = $this->output->createProgressBar($userIds->count());
        $bar->start();

        $done = 0;
        foreach ($userIds as $id) {
            if ($user = User::find($id)) {
                $service->recomputeDtr($user, (string) $from, (string) $to);
                $done++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Recomputed {$done} employee(s).");

        return self::SUCCESS;
    }
}
