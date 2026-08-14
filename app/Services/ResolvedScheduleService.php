<?php

namespace App\Services;

use App\Models\EmployeeShiftSchedule;
use App\Models\User;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Combines ShiftAssignment history and EmployeeShiftSchedule per-date
 * overrides into a single day-by-day "what actually happens" view for one
 * employee - the same precedence WorkSchedule::isWorkday()/forUserOnDate()
 * already apply for DTR/payroll, just surfaced for a human to read at a
 * glance instead of cross-referencing the Shift Assignment and Shift
 * Schedule screens separately.
 */
class ResolvedScheduleService
{
    /**
     * @return Collection<string, array{date: Carbon, label: string, source: string, shiftName: ?string, hours: ?string, noBreak: bool, isWorkday: bool, isRestDay: bool, isFieldWork: bool, isFieldWorkPairGap: bool, isVoidedAbsence: bool, shadowedAssignmentShiftName: ?string}>
     */
    public function buildMonth(User $user, Carbon $monthStart): Collection
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $overrides = EmployeeShiftSchedule::where('user_id', $user->id)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->with('shift')
            ->get()
            ->keyBy(fn (EmployeeShiftSchedule $o) => $o->date->toDateString());

        WorkSchedule::preloadShiftAssignments([$user->id]);

        $days = collect();
        for ($date = $monthStart->copy(); $date->lte($monthEnd); $date->addDay()) {
            $dateStr = $date->toDateString();

            $isRestDay = WorkSchedule::isRestDay($user, $date, $overrides);
            $isFieldWork = WorkSchedule::isFieldWork($user, $date, $overrides);
            $isWorkday = WorkSchedule::isWorkday($user, $date, $overrides);
            $isVoidedAbsence = WorkSchedule::isFieldWorkPairVoidedAbsence($user, $date, $overrides);
            $resolution = WorkSchedule::resolutionSource($user, $date, $overrides);

            // A Field Work Pair gap day (e.g. Tue/Wed/Thu of a Monday-in/
            // Friday-out week) is also neither a workday nor field work, but
            // gets its own distinct label below rather than collapsing into
            // "Rest Day" like an ordinary day-of-week gap does - see
            // WorkSchedule::isFieldWorkPairGapDay()'s docblock.
            $isFieldWorkPairGap = ! $isWorkday && ! $isFieldWork && ! $isRestDay
                && WorkSchedule::isFieldWorkPairGapDay($user, $date, $overrides);

            // Display-only: any other day that's neither a workday nor field
            // work is effectively a rest day, whether it's an explicit
            // override or an implicit day-of-week gap between assignments -
            // see WorkSchedule::isRestDay()'s docblock for why that narrower,
            // override-only definition must stay untouched for DTR/payroll.
            $isEffectiveRestDay = $isRestDay || (! $isWorkday && ! $isFieldWork && ! $isFieldWorkPairGap);

            $hours = null;
            $noBreak = false;
            if ($isWorkday && ! $isRestDay && ! $isVoidedAbsence) {
                $ws = WorkSchedule::forUserOnDate($user, $date, $overrides);
                $hours = $ws->workStart.'-'.$ws->workEnd;
                $noBreak = $ws->noBreak;
            }

            $label = match (true) {
                $isVoidedAbsence => 'Absent (Unconfirmed Field Work)',
                $isFieldWorkPairGap => 'No Punch Required',
                $isEffectiveRestDay => 'Rest Day',
                $isFieldWork => 'Field Work',
                $isWorkday => $resolution['shiftName'] ?? 'Standard Day',
                default => 'Off',
            };

            $days->put($dateStr, [
                'date' => $date->copy(),
                'label' => $label,
                'source' => $resolution['source'],
                'shiftName' => $resolution['shiftName'],
                'hours' => $hours,
                'noBreak' => $noBreak,
                'isWorkday' => $isWorkday,
                'isRestDay' => $isEffectiveRestDay,
                'isFieldWork' => $isFieldWork,
                'isFieldWorkPairGap' => $isFieldWorkPairGap,
                'isVoidedAbsence' => $isVoidedAbsence,
                'shadowedAssignmentShiftName' => $resolution['shadowedAssignmentShiftName'],
            ]);
        }

        WorkSchedule::flushShiftAssignmentMemo();

        return $days;
    }
}
