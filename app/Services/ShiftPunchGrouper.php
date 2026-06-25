<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\EmployeeShiftSchedule;
use App\Models\User;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Groups an employee's raw biometric punches into logical shifts using their
 * assigned schedule. A night shift's pre-midnight and post-midnight punches are
 * folded onto the single shift date the shift began (see WorkSchedule's
 * shiftDateFor rule). For a standard day shift this reproduces the previous
 * group-by-logdate behaviour exactly.
 *
 * When $assignments is provided (a date-string-keyed Collection of
 * EmployeeShiftSchedule), each punch is evaluated against the schedule
 * effective on its own logdate. This supports employees whose shift rotates
 * day-to-day without causing N+1 queries.
 *
 * Shared by PersonnelLogImportService (writes dtrs) and Form48ExportService
 * (export fallback) so the two always agree on shift boundaries.
 */
class ShiftPunchGrouper
{
    /**
     * @param  Collection<int, AttendanceLog>  $logs
     * @param  Collection<string, EmployeeShiftSchedule>|null  $assignments  pre-loaded per-date schedules
     * @return array<string, Collection<int, Carbon>> shiftDate (Y-m-d) => punch datetimes
     */
    public function group(User $user, Collection $logs, ?Collection $assignments = null): array
    {
        $groups = [];

        foreach ($logs as $log) {
            $logdate = $log->logdate instanceof Carbon
                ? $log->logdate->toDateString()
                : Carbon::parse((string) $log->logdate)->toDateString();
            $logtime = substr((string) $log->logtime, 0, 8);

            if ($logtime === '') {
                continue;
            }

            $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($logdate), $assignments);
            $shiftDate = $schedule->shiftDateFor($logdate, $logtime);

            $groups[$shiftDate] ??= collect();
            $groups[$shiftDate]->push(Carbon::parse("$logdate $logtime"));
        }

        return $groups;
    }
}
