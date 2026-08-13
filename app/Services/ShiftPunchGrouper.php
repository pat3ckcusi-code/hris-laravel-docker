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
 *
 * A true 24-hour shift (time_in === time_out, crosses_midnight) is a special
 * case shiftDateFor() can't resolve alone: its duration formula collapses to
 * exactly 1440 minutes, leaving zero grace on the late side of the boundary -
 * a checkout at or after the exact clock mark always reads as a brand-new
 * arrival instead of the previous shift's (perfectly normal) slightly-late
 * close. Worse, for a rotation with multiple consecutive rest days between
 * on-days, the real closing punch can land days later - far beyond any fixed
 * clock tolerance. For this specific shift shape, group() tracks an explicit
 * open/close state across the whole punch stream: a shift opens on its first
 * punch and stays eligible to absorb further punches (including the actual
 * close, whenever it arrives) until either a fixed grace period past the
 * exact 24h mark, or the start of the next actually-scheduled workday -
 * whichever is later - has passed.
 *
 * An ordinary (non-24h) crossing shift needs the same open/close tracking for
 * a narrower reason: shiftDateFor() resolves which schedule to evaluate a
 * punch against using *that punch's own logdate* - correct for the shift's
 * first (pre-midnight) punch, but wrong for its closing (post-midnight) punch
 * whenever the following calendar day happens to carry a *different*
 * schedule (a rest day, WFH, or a wholly different shift - all ordinary,
 * expected day-to-day variation in this app). Without tracking, that closing
 * punch gets evaluated against tomorrow's schedule instead of the shift it
 * actually belongs to, and silently lands on the wrong shift-date - or its
 * own, permanently incomplete one - instead of closing out last night's
 * shift. So once a crossing shift opens, it stays eligible for a *tight*
 * grace window past its own workEnd (the same "still the same event"
 * tolerance used elsewhere, not the 24h case's rest-day-walking widening -
 * an ordinary night shift's close is expected within hours, not days, and
 * widening further risks swallowing a genuinely new day's own early arrival
 * into the previous night's shift instead). Only a plain, non-crossing
 * schedule (day shifts with a genuine off-period) is untouched and keeps
 * using shiftDateFor() directly, exactly as before.
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
        $openShift = null; // ['date' => string, 'eligibleUntil' => Carbon] | null - set for any crossing shift, not just a true 24h one

        foreach ($this->sortedPunches($logs) as $log) {
            $logdate = $log->logdate instanceof Carbon
                ? $log->logdate->toDateString()
                : Carbon::parse((string) $log->logdate)->toDateString();
            $logtime = substr((string) $log->logtime, 0, 8);

            if ($logtime === '') {
                continue;
            }

            $punch = Carbon::parse("$logdate $logtime");

            if ($openShift !== null && $punch->lte($openShift['eligibleUntil'])) {
                // Still within the still-open shift's eligibility window - or
                // simply still mid-shift - so this punch belongs to it,
                // whichever side of any single-day clock boundary it falls
                // on. Deliberately does NOT reset $openShift here: a stray
                // mid-shift punch (e.g. a meal-break tap) must not clobber
                // the tracker before the real closing punch arrives - only a
                // punch genuinely past the window (the else branch) ever
                // replaces it.
                $shiftDate = $openShift['date'];
            } else {
                $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($logdate), $assignments);

                $shiftDate = $this->isFullDayCrossing($schedule)
                    ? $logdate // no fold-back wanted for a fresh 24h-shift start
                    : $schedule->shiftDateFor($logdate, $logtime);

                // See class docblock: a full 24h shift keeps its wide,
                // rest-day-walking eligibility window; an ordinary crossing
                // shift gets a tight grace-only window off its own workEnd so
                // its closing punch can't be mis-evaluated against tomorrow's
                // (possibly unrelated) schedule; a non-crossing schedule needs
                // no tracking at all.
                $openShift = match (true) {
                    $this->isFullDayCrossing($schedule) => [
                        'date' => $shiftDate,
                        'eligibleUntil' => $this->eligibleUntil($user, $shiftDate, $schedule, $assignments),
                    ],
                    $schedule->crossesMidnight => [
                        'date' => $shiftDate,
                        'eligibleUntil' => $schedule->referenceDateTime($shiftDate, $schedule->workEnd)
                            ->copy()->addHours($this->closeGraceHours()),
                    ],
                    default => null,
                };
            }

            $groups[$shiftDate] ??= collect();
            $groups[$shiftDate]->push($punch);
        }

        return $groups;
    }

    /**
     * @param  Collection<int, AttendanceLog>  $logs
     * @return Collection<int, AttendanceLog>
     */
    private function sortedPunches(Collection $logs): Collection
    {
        return $logs->sortBy(
            fn (AttendanceLog $log) => ((string) $log->logdate).' '.((string) $log->logtime)
        )->values();
    }

    private function isFullDayCrossing(WorkSchedule $schedule): bool
    {
        return $schedule->crossesMidnight && $schedule->workStart === $schedule->workEnd;
    }

    /**
     * The later of: a fixed grace period past the exact 24h mark, or the
     * start of the next actually-scheduled workday (walking forward through
     * however many consecutive rest days are configured, capped at 30 days as
     * a safety bound against a pathological/misconfigured schedule).
     */
    private function eligibleUntil(User $user, string $shiftDate, WorkSchedule $schedule, ?Collection $assignments): Carbon
    {
        $graceClose = $schedule->referenceDateTime($shiftDate, $schedule->workEnd)
            ->copy()->addHours($this->closeGraceHours());

        $cursor = Carbon::parse($shiftDate)->addDay();
        $cap = Carbon::parse($shiftDate)->addDays(30);
        while ($cursor->lte($cap) && ! WorkSchedule::isWorkday($user, $cursor, $assignments)) {
            $cursor->addDay();
        }
        $nextWorkdayStart = $schedule->referenceDateTime($cursor->toDateString(), $schedule->workStart, isShiftStart: true);

        return $graceClose->gt($nextWorkdayStart) ? $graceClose : $nextWorkdayStart;
    }

    // Reuses the existing "how late is still the same event" tolerance rather
    // than adding a new config key - AttendanceMatcher's own no-break PM-out
    // event window is bounded by this exact same value off this exact same
    // anchor (workEnd), so a punch this accepts as "the close" is guaranteed
    // to still be eligible for the matcher to actually match it, not reject
    // it into unmatched. Shared by both the full-24h case (as the "grace"
    // half of eligibleUntil()'s wider window) and the ordinary-crossing case
    // (as the whole window).
    private function closeGraceHours(): float
    {
        return (float) config('attendance.matching.late_out_hours', 4.0);
    }
}
