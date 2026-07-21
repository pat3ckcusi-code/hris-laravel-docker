<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monthly leave credits are triggered manually by a Leave Manager from the
// Leave Ledger UI (LeaveManagerController::runMonthlyCredits) rather than on
// a schedule; the credit:process-monthly command remains available for CLI use.

// Flip users.shift_id forward/back to whatever shift_assignments row covers
// today, so a scheduled future/expiring shift change takes effect on its own
// without anyone re-saving it. Runs before the per-minute import below so the
// day's DTR processing sees the correct shift from the start.
Schedule::command('shift:sync-cache')->dailyAt('00:05');

// Auto-import biometric punch logs. The command self-throttles via cache
// based on the interval configured in HR Settings → Attendance.
Schedule::command('attendance:auto-import')->everyMinute();

// Clean up export jobs stuck in processing/pending beyond the 6-minute window.
Schedule::command('export:prune')->everyFiveMinutes();

// Delete already-expired database cache rows, which otherwise accumulate
// indefinitely (the database cache driver never garbage-collects them itself).
Schedule::command('cache:prune-expired')->daily();
