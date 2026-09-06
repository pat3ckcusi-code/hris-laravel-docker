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

// Sync users.salary_grade/salary_step to whichever employee_assignments row
// covers today, mirroring shift:sync-cache's identical users.shift_id sync -
// closes the gap where a future-dated promote()/store() correctly leaves the
// cache on the employee's still-current position, but nothing else advances
// it once that future date actually arrives.
Schedule::command('plantilla:sync-salary-cache')->dailyAt('00:06');

// Sync users.dtr_exempt* to whichever dtr_exemption_periods row covers today
// (restoring an employee whose Date Until has passed, and activating one
// whose Effective Date has just arrived), mirroring shift:sync-cache's own
// history-to-cache sync. Runs after shift:sync-cache but before the
// per-minute import so a same-day change is picked up immediately.
Schedule::command('dtr:restore-expired-exemptions')->dailyAt('00:07');

// Auto-import biometric punch logs. The command self-throttles via cache
// based on the interval configured in HR Settings → Attendance.
Schedule::command('attendance:auto-import')->everyMinute();

// Clean up export jobs stuck in processing/pending beyond the 6-minute window.
Schedule::command('export:prune')->everyFiveMinutes();

// Clean up PNPKI e-signature signings: mark stuck in-flight ones failed
// after 6 minutes, delete old failed attempts' rows+files after 30 days,
// and delete a superseded (non-latest) completed signing's FILE once a
// newer completed signing exists for the same document. Completed ROWS are
// never deleted, and a signable's single latest completed file is never
// touched - see the command's own docblock for the full invariant.
Schedule::command('esignature-signing:prune')->everyFiveMinutes();

// Delete already-expired database cache rows, which otherwise accumulate
// indefinitely (the database cache driver never garbage-collects them itself).
Schedule::command('cache:prune-expired')->daily();

// Set Status=Inactive for any Job Orders employee whose appointment history
// has fully lapsed (no job_order_appointments row covering today or later).
// Auto-reactivation on renewal is handled separately, in
// JobOrderAppointmentService, not by this command.
Schedule::command('job-order:deactivate-expired')->dailyAt('00:10');

// Retroactively resolve a "Field Work" Monday in_only/Friday out_only punch
// pairing once its week has fully closed: if the pairing is incomplete and
// nothing (Leave/Locator/ETA/Office/Travel Order/Holiday/Suspension)
// explains the gap, the affected dates become real, consequence-bearing
// absences (see WeeklyPunchPairReconciliationService for the full rule).
// Only ever evaluates a week whose Friday is already strictly in the past,
// so it can never race attendance:auto-import's rolling yesterday/today window.
Schedule::command('attendance:reconcile-punch-pairs')->dailyAt('01:15');
