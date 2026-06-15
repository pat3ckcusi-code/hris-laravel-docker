<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Process monthly leave credits on the 1st of every month at 00:30.
Schedule::command('credit:process-monthly')->monthlyOn(1, '00:30');

// Auto-import biometric punch logs. The command self-throttles via cache
// based on the interval configured in HR Settings → Attendance.
Schedule::command('attendance:auto-import')->everyMinute();

// Clean up export jobs stuck in processing/pending beyond the 6-minute window.
Schedule::command('export:prune')->everyFiveMinutes();
