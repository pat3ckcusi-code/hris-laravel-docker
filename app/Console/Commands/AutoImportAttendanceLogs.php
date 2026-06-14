<?php

namespace App\Console\Commands;

use App\Jobs\ImportAttendanceLogsJob;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AutoImportAttendanceLogs extends Command
{
    protected $signature = 'attendance:auto-import';

    protected $description = 'Automatically pull biometric punch logs per HR settings interval';

    public function handle(): int
    {
        $setting = Setting::first();

        if (! $setting || ! $setting->auto_import_enabled) {
            $this->info('Auto-import is disabled. Enable it in HR Settings → Attendance.');
            return 0;
        }

        $interval = max(15, (int) $setting->auto_import_interval_minutes);

        /** @var Carbon|null $lastRun */
        $lastRun = Cache::get('attendance_auto_import_last_run');

        if ($lastRun && now()->diffInMinutes($lastRun) < $interval) {
            $nextRun = $lastRun->copy()->addMinutes($interval);
            $this->info("Skipped: next auto-import in ~{$nextRun->diffInMinutes(now())} min (last ran {$lastRun->diffForHumans()}).");
            return 0;
        }

        $alreadyQueued = DB::table('jobs')
            ->where('payload', 'like', '%ImportAttendanceLogsJob%')
            ->exists();

        if ($alreadyQueued) {
            $this->warn('Skipped: a previous auto-import job is still pending in the queue.');
            return 0;
        }

        $from = today()->subDay()->toDateString();
        $to   = today()->toDateString();

        ImportAttendanceLogsJob::dispatch($from, $to, null, $setting->auto_import_dept_id);

        Cache::put('attendance_auto_import_last_run', now(), now()->addMinutes($interval + 1));

        $this->info("Queued attendance auto-import: {$from} → {$to}");

        return 0;
    }
}
