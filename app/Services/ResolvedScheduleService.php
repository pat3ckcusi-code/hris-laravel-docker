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
     * @return Collection<string, array{date: Carbon, label: string, source: string, shiftName: ?string, hours: ?string, isWorkday: bool, isRestDay: bool, isFieldWork: bool, shadowedAssignmentShiftName: ?string}>
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
            $resolution = WorkSchedule::resolutionSource($user, $date, $overrides);

            $hours = null;
            if ($isWorkday && ! $isRestDay) {
                $ws = WorkSchedule::forUserOnDate($user, $date, $overrides);
                $hours = $ws->workStart.'-'.$ws->workEnd;
            }

            $label = match (true) {
                $isRestDay => 'Rest Day',
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
                'isWorkday' => $isWorkday,
                'isRestDay' => $isRestDay,
                'isFieldWork' => $isFieldWork,
                'shadowedAssignmentShiftName' => $resolution['shadowedAssignmentShiftName'],
            ]);
        }

        WorkSchedule::flushShiftAssignmentMemo();

        return $days;
    }
}
