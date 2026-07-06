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

// Auto-import biometric punch logs. The command self-throttles via cache
// based on the interval configured in HR Settings → Attendance.
Schedule::command('attendance:auto-import')->everyMinute();

// Clean up export jobs stuck in processing/pending beyond the 6-minute window.
Schedule::command('export:prune')->everyFiveMinutes();
