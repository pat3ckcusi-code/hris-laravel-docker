<?php

namespace App\Services;

use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\Holiday;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Locator;
use App\Models\User;
use App\Support\LeaveTypeResolver;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LwopAggregationService
{
    /**
     * Compute days_present and abs_wop_days for a user's month, per the CSC Omnibus
     * Rules on Leave: a flat 30-day month, reduced by non-illness LWOP days and true
     * AWOL days (no attendance and nothing on file to cover it). LWOP arising from a
     * Sick Leave request counts as actual service and does not reduce days present.
     * If the employee was hired partway through the month, the 30-day baseline itself
     * is scaled down to their actual service window before either is subtracted —
     * "actual service since appointment" per CSC.
     *
     * Each LWOP request's lwop_days (a whole-request aggregate) is prorated by the
     * fraction of its date range that overlaps this month, since leave_dates.is_lwop
     * is never populated and cannot be used for per-date attribution.
     *
     * @return array{days_present: float, abs_wop_days: float, awol_days: float}
     */
    public function computeForMonth(User $user, int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        $serviceStart = $monthStart;

        if ($user->date_hired !== null) {
            $dateHired = $user->date_hired->copy()->startOfDay();

            if ($dateHired->greaterThan($monthEnd)) {
                // Not hired yet during this month at all.
                return ['days_present' => 0.0, 'abs_wop_days' => 0.0, 'awol_days' => 0.0];
            }

            if ($dateHired->greaterThan($monthStart)) {
                $serviceStart = $dateHired;
            }
        }

        $calendarDaysInMonth = $monthStart->daysInMonth;
        $serviceDaysInMonth = $serviceStart->diffInDays($monthEnd) + 1;
        $monthBaseline = 30 * ($serviceDaysInMonth / $calendarDaysInMonth);

        $requests = LeaveRequest::where('user_id', $user->id)
            ->whereIn('status', ['approved', 'Approved'])
            ->where('lwop_days', '>', 0)
            ->where('start_date', '<=', $monthEnd->toDateString())
            ->where('end_date', '>=', $monthStart->toDateString())
            ->get();

        $nonIllnessLwop = 0.0;

        foreach ($requests as $request) {
            $requestStart = Carbon::parse($request->start_date)->startOfDay();
            $requestEnd = Carbon::parse($request->end_date)->startOfDay();
            $totalRequestDays = $requestStart->diffInDays($requestEnd) + 1;

            if ($totalRequestDays <= 0) {
                continue;
            }

            $overlapStart = $requestStart->greaterThan($monthStart) ? $requestStart : $monthStart;
            $overlapEnd = $requestEnd->lessThan($monthEnd) ? $requestEnd : $monthEnd;
            $overlapDays = $overlapStart->diffInDays($overlapEnd) + 1;

            if ($overlapDays <= 0) {
                continue;
            }

            $prorated = (float) $request->lwop_days * ($overlapDays / $totalRequestDays);

            if (LeaveTypeResolver::fromLabel((string) $request->leave_type) !== 'SL') {
                $nonIllnessLwop += $prorated;
            }
        }

        $awolDays = $this->classifyWorkdays($user, $serviceStart, $monthEnd)->filter()->count();

        $totalUnpaidAbsence = $nonIllnessLwop + $awolDays;

        return [
            'days_present' => round(max(0, $monthBaseline - $totalUnpaidAbsence), 3),
            'abs_wop_days' => round($totalUnpaidAbsence, 3),
            'awol_days' => round($awolDays, 3),
        ];
    }

    /**
     * Classify every scheduled workday in [rangeStart, rangeEnd] for a user as AWOL or
     * not. Non-workdays (weekends, holidays, rest days) are excluded from the result
     * entirely -- they're never AWOL by definition, and excluding them lets a backward
     * streak walk skip over them for free.
     *
     * A workday is AWOL only if there's no attendance AND it isn't covered by an
     * approved leave request, a DTR excuse, an approved locator, or an approved ETA.
     *
     * @return Collection<string, bool> keyed by 'Y-m-d', chronological order
     */
    public function classifyWorkdays(User $user, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        $user->loadMissing('shift');

        $rangeStartStr = $rangeStart->toDateString();
        $rangeEndStr = $rangeEnd->toDateString();

        $dtrsByDate = Dtr::where('employee_id', $user->id)
            ->whereBetween('date', [$rangeStartStr, $rangeEndStr])
            ->get()
            ->keyBy(fn ($d) => $d->date->toDateString());

        $shiftSchedules = EmployeeShiftSchedule::where('user_id', $user->id)
            ->whereBetween('date', [$rangeStartStr, $rangeEndStr])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        // Warm the shift-assignment-history memo once so the per-date
        // WorkSchedule calls in the day-walk below stay O(1).
        WorkSchedule::preloadShiftAssignments([$user->id]);

        $holidays = Holiday::whereBetween('holiday_date', [$rangeStartStr, $rangeEndStr])
            ->pluck('holiday_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $leaveCoveredDates = LeaveDate::whereHas('leaveRequest', function ($q) use ($user) {
            $q->where('user_id', $user->id)->whereIn('status', ['approved', 'Approved']);
        })
            ->whereBetween('leave_date', [$rangeStartStr, $rangeEndStr])
            ->where('is_cancelled', false)
            ->pluck('leave_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $excusedDates = DtrExcuse::where('user_id', $user->id)
            ->whereBetween('date', [$rangeStartStr, $rangeEndStr])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $locatorDates = Locator::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$rangeStartStr, $rangeEndStr])
            ->pluck('travel_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $etas = Eta::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('departure_date', '<=', $rangeEndStr)
            ->where('arrival_date', '>=', $rangeStartStr)
            ->get(['departure_date', 'arrival_date']);

        $result = collect();
        $cursor = $rangeStart->copy();

        while ($cursor->lessThanOrEqualTo($rangeEnd)) {
            $dateStr = $cursor->toDateString();

            $isWorkday = WorkSchedule::isWorkday($user, $cursor, $shiftSchedules)
                && ! isset($holidays[$dateStr]);

            if (! $isWorkday) {
                $cursor->addDay();

                continue;
            }

            $dtr = $dtrsByDate->get($dateStr);
            $wasPresent = $dtr && ! $dtr->is_absent && $dtr->status !== 'absent';

            if ($wasPresent) {
                $result->put($dateStr, false);
                $cursor->addDay();

                continue;
            }

            $isCovered = isset($leaveCoveredDates[$dateStr])
                || isset($excusedDates[$dateStr])
                || isset($locatorDates[$dateStr])
                || $etas->contains(fn ($eta) => $cursor->between($eta->departure_date, $eta->arrival_date));

            $result->put($dateStr, ! $isCovered);

            $cursor->addDay();
        }

        return $result;
    }

    /**
     * Current consecutive AWOL workday streak ending the day before $asOf (today's DTR
     * may not be resolved yet). Capped at a 60-workday lookback -- past that the
     * employee is already well beyond the CSC 30-working-day separation threshold
     * regardless of the exact count.
     *
     * @return array{streak: int, streak_started_on: string|null, capped: bool}
     */
    public function computeCurrentAwolStreak(User $user, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $rangeEnd = $asOf->copy()->subDay();
        $rangeStart = $rangeEnd->copy()->subDays(90); // wide enough to surface ~60 workdays

        $classified = $this->classifyWorkdays($user, $rangeStart, $rangeEnd);

        $streak = 0;
        $streakStart = null;
        $capped = false;

        foreach ($classified->reverse() as $date => $isAwol) {
            if (! $isAwol) {
                break;
            }

            $streak++;
            $streakStart = $date;

            if ($streak >= 60) {
                $capped = true;

                break;
            }
        }

        return [
            'streak' => $streak,
            'streak_started_on' => $streakStart,
            'capped' => $capped,
        ];
    }

    /**
     * Number of distinct AWOL runs (episodes) within the CSC semester containing $asOf
     * (Jan-Jun or Jul-Dec) -- a hint toward the "3x in a semester" circumvention pattern
     * CSC MC 13-2007 allows treating as grounds for separation. Informational only —
     * whether it constitutes an actual circumvention scheme is a judgment call for HR,
     * not an automatic determination.
     */
    public function countAwolEpisodesThisSemester(User $user, ?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $semesterStart = $asOf->month <= 6
            ? Carbon::create($asOf->year, 1, 1)
            : Carbon::create($asOf->year, 7, 1);
        $semesterEnd = $asOf->copy()->subDay();

        if ($semesterEnd->lessThan($semesterStart)) {
            return 0;
        }

        $classified = $this->classifyWorkdays($user, $semesterStart, $semesterEnd);

        $episodes = 0;
        $inEpisode = false;

        foreach ($classified as $isAwol) {
            if ($isAwol && ! $inEpisode) {
                $episodes++;
                $inEpisode = true;
            } elseif (! $isAwol) {
                $inEpisode = false;
            }
        }

        return $episodes;
    }
}
