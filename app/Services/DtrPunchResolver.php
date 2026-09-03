<?php

namespace App\Services;

use App\Services\Attendance\AttendanceMatcher;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\HoursWorkedCalculator;
use App\Services\Attendance\LateCalculator;
use App\Services\Attendance\MatchResult;
use App\Services\Attendance\OvertimeCalculator;
use App\Services\Attendance\UndertimeCalculator;
use App\Support\WorkSchedule;
use Carbon\Carbon;

/**
 * Resolves a single shift's raw biometric punches into the four CSC Form 48
 * slots (arrival, break-out, break-in, departure) plus tardiness, undertime,
 * hours worked, and an attendance status.
 *
 * Shared by PersonnelLogImportService (writes the dtrs table) and
 * Form48ExportService (export fallback) so the two can never disagree.
 *
 * Punches arrive as full datetimes already grouped onto one logical shift date
 * (see ShiftPunchGrouper), so a night shift's pre-midnight arrival and
 * post-midnight departure sort and score correctly across the day boundary.
 *
 * This class is a thin orchestrator over app/Services/Attendance:
 *
 *   1. AttendanceMatcher aligns the punches to the schedule's expected events
 *      (nearest-scheduled-time within eligibility windows, order-preserving -
 *      NEVER positional, so a missing punch stays null instead of pulling a
 *      later log upward). Punches no event can claim come back unmatched, for
 *      review. Matching completes fully before anything is computed.
 *   2. LateCalculator / UndertimeCalculator / HoursWorkedCalculator score the
 *      match result (late from IN events only, undertime from OUT events only,
 *      hours from complete in->out spans only).
 *   3. AttendanceStatusResolver names the day (present / late / undertime /
 *      half_day_am / half_day_pm / missing_in / missing_out / incomplete).
 *      Absent is never produced here - a punchless day gets no dtrs row at
 *      all, and absence stays a read-time classification.
 *
 * Matching tunables (windows, dedupe, late bias) live in config/attendance.php.
 */
class DtrPunchResolver
{
    public function __construct(
        private readonly AttendanceMatcher $matcher = new AttendanceMatcher,
        private readonly LateCalculator $lateCalculator = new LateCalculator,
        private readonly UndertimeCalculator $undertimeCalculator = new UndertimeCalculator,
        private readonly OvertimeCalculator $overtimeCalculator = new OvertimeCalculator,
        private readonly HoursWorkedCalculator $hoursWorkedCalculator = new HoursWorkedCalculator,
        private readonly AttendanceStatusResolver $statusResolver = new AttendanceStatusResolver,
    ) {}

    /**
     * @param  iterable<int, Carbon|string>  $punches  full punch datetimes for ONE shift
     * @param  array<string, array{0:string,1:string}|null>  $excludedSlots  slot key ('am_in'|'am_out'|'pm_in'|'pm_out')
     *                                                                       => exclusion window ['H:i', 'H:i'], or null for an unconditional
     *                                                                       exclusion (e.g. a DtrExcuse, where no punch is physically possible
     *                                                                       regardless of time). A windowed slot only has "no real punch
     *                                                                       expected" while a punch falls inside the window (e.g. a locator's
     *                                                                       [departure, arrival] span) - a punch outside it (before departure,
     *                                                                       or after arrival) is real and must land in its natural slot.
     * @return array{am_in:?string, am_out:?string, pm_in:?string, pm_out:?string,
     *               time_in_ot:?string, time_out_ot:?string,
     *               late_minutes:int, undertime_minutes:int, overtime_minutes:int,
     *               worked_minutes:int, hours_worked:float, status:string, unmatched:list<string>}
     */
    public function resolve(iterable $punches, string $shiftDate, WorkSchedule $schedule, array $excludedSlots = []): array
    {
        $result = $this->matcher->match($punches, $shiftDate, $schedule, $excludedSlots);

        $late = $this->lateCalculator->minutes($result, $shiftDate, $schedule);
        $undertime = $this->undertimeCalculator->minutes($result, $shiftDate, $schedule);
        $overtime = $this->overtimeCalculator->minutes($result);
        $workedMinutes = $this->hoursWorkedCalculator->workedMinutes($result, $schedule->noBreak);
        $status = $this->statusResolver->resolve($result, $late, $undertime, $schedule->noBreak, $schedule->punchRequirement);

        return [
            'am_in' => $this->fmt($result->slot('am_in')),
            'am_out' => $this->fmt($result->slot('am_out')),
            'pm_in' => $this->fmt($result->slot('pm_in')),
            'pm_out' => $this->fmt($result->slot('pm_out')),
            'time_in_ot' => $this->fmt($result->slot('ot_in')),
            'time_out_ot' => $this->fmt($result->slot('ot_out')),
            'late_minutes' => $late,
            'undertime_minutes' => $undertime,
            'overtime_minutes' => $overtime,
            'worked_minutes' => $workedMinutes,
            'hours_worked' => round($workedMinutes / 60, 2),
            'status' => $status->value,
            'unmatched' => array_map(fn (Carbon $c) => $c->format('H:i:s'), $result->unmatched),
        ];
    }

    /**
     * Display/reporting-only estimate for a missing arrival punch, covering
     * both schedule shapes. An uncertain START of a segment (an IN punch
     * missing while its OUT sibling proves the segment happened) is always
     * charged here, as late - never as undertime, which is reserved for an
     * uncertain END of a segment (see imputedUndertimeMinutes()).
     *
     *  - With-break (4-slot): sums two independent components, mirroring
     *    LateCalculator's own additive am_in/pm_in formula -
     *      AM In missing, AM Out present -> impute workStart→morningEnd.
     *      PM In missing, PM Out present -> impute lunchReturn→workEnd.
     *    Either, both, or neither may apply on a given day.
     *  - No-break (2-slot, punchRequirement 'both'): there is no AM Out/PM In
     *    at all, so the only possible sibling proof is PM Out - when it
     *    exists but AM In never got recorded, charge the full
     *    workStart→workEnd span (a 2-punch schedule has no half-day midpoint
     *    to anchor to).
     *
     * Never applies to an in_only/out_only (Field Work Shift) schedule, even
     * if noBreak also happens to be true on that row: that shape expects
     * exactly ONE punch for the date, so there is no sibling-proves-presence
     * concept - its own absence/confirmation logic belongs entirely to
     * WeeklyPunchPairReconciliationService. Guarded explicitly rather than
     * relying on those schedules' columns happening to stay null, since an
     * out_only day genuinely populates time_out_pm.
     *
     * Unlike resolve(), this never runs at import time and is never
     * persisted - each sibling punch already existing is itself the "this
     * segment is over" signal, so no separate now() gate is needed the way
     * imputedUndertimeMinutes() needs one.
     *
     * $coveredSlots lists slot keys ('am_in'|'pm_in') already known to be
     * explained by something else (Locator/DtrExcuse/WorkSuspension) -
     * callers must gate per-component this way rather than accepting or
     * rejecting the whole return value based on a single slot's coverage,
     * since the two components are independent and a source can explain
     * only one of them (e.g. a Locator window covering only 'pm_in' must not
     * suppress a genuine, unrelated 'am_in' lateness, and vice versa).
     *
     * $firedComponents is an out-param: filled with whichever slot key(s)
     * ('am_in'|'pm_in') actually contributed a non-zero component on this
     * call, so a caller needing per-cell attribution (the DTR page) can
     * highlight exactly the slot(s) responsible instead of collapsing the
     * whole summed total into a single "something was imputed" flag - a
     * caller that did that previously mislabeled a pm_in-only gap as an
     * am_in problem. The no-break single-span case reports 'am_in' (its own
     * semantic anchor, matching the return-0 guard's own coveredSlots check
     * above). Always reset to [] at the top of this call.
     */
    public function imputedLateMinutes(?string $timeInAm, ?string $timeOutAm, ?string $timeInPm, ?string $timeOutPm, string $shiftDate, WorkSchedule $schedule, array $coveredSlots = [], ?array &$firedComponents = null): int
    {
        $firedComponents = [];

        if ($schedule->punchRequirement !== 'both') {
            return 0;
        }

        if ($schedule->noBreak) {
            if ($timeInAm || ! $timeOutPm || in_array('am_in', $coveredSlots, true)) {
                return 0;
            }

            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);
            $firedComponents[] = 'am_in';

            return (int) $startRef->diffInMinutes($endRef);
        }

        $minutes = 0;

        if (! $timeInAm && $timeOutAm && ! in_array('am_in', $coveredSlots, true)) {
            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
            $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);
            $minutes += (int) $startRef->diffInMinutes($breakOutRef);
            $firedComponents[] = 'am_in';
        }

        if (! $timeInPm && $timeOutPm && ! in_array('pm_in', $coveredSlots, true)) {
            $lunchReturnRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);
            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);
            $minutes += (int) $lunchReturnRef->diffInMinutes($endRef);
            $firedComponents[] = 'pm_in';
        }

        return $minutes;
    }

    /**
     * Display/reporting-only estimate for a missing departure punch,
     * covering both schedule shapes. An uncertain END of a segment (an OUT
     * punch missing while its IN sibling proves the segment started) is
     * always charged here, as undertime - the mirror of
     * imputedLateMinutes().
     *
     *  - With-break (4-slot): sums two independent components, mirroring
     *    UndertimeCalculator's own additive am_out/pm_out formula -
     *      AM Out missing, AM In present -> impute workStart→morningEnd,
     *      once the AM half's own window (morningEnd) has passed - can't
     *      assume "missing" before lunch would even have started.
     *      PM Out missing, PM In present -> impute lunchReturn→workEnd,
     *      once the shift's own end has passed.
     *    Either, both, or neither may apply on a given day.
     *
     *    A third shape, added later: the WHOLE am_in/am_out pair missing
     *    while PM is fully punched, or the whole pm_in/pm_out pair missing
     *    while AM is fully punched - AttendanceStatusResolver's half_day_pm/
     *    half_day_am. Unlike the two shapes above there's no sibling punch
     *    proving the segment happened, but none is needed: the OTHER half
     *    being fully punched already proves the day is over, so the entire
     *    missing half is charged as undertime for its full scheduled span
     *    (same am_out/pm_out component keys and coveredSlots gate as above -
     *    a half day is not itself a new kind of component, just a second way
     *    to arrive at the same one).
     *  - No-break (2-slot, punchRequirement 'both'): there is no PM In/AM Out
     *    at all, so the only possible sibling proof is AM In - when it
     *    exists but PM Out never got recorded, charge the full
     *    workStart→workEnd span. A no-break schedule's own half-day-shaped
     *    gap (am_in missing, pm_out present) is already fully handled by
     *    imputedLateMinutes()'s no-break branch instead (charged as Late,
     *    since a 2-slot day has no AM/PM split to anchor an Undertime
     *    component to) - deliberately not duplicated here.
     *
     * Never applies to an in_only/out_only schedule (see
     * imputedLateMinutes()).
     *
     * $coveredSlots lists slot keys ('am_out'|'pm_out') already known to be
     * explained by something else - see imputedLateMinutes()'s docblock for
     * why this must gate each component independently rather than accepting
     * or rejecting the whole return value on a single slot's coverage. This
     * is what a Locator/DtrExcuse/WorkSuspension covering only 'am_out'
     * (e.g. a morning personal errand) must pass so a genuine, unrelated
     * 'pm_out' undertime figure is never suppressed by it, and vice versa -
     * previously a Locator covering only 'am_out' let this function's
     * 'am_out' component through mislabeled as undertime on a day the
     * employee's actual PM Out was on time.
     *
     * $firedComponents is an out-param, mirror of imputedLateMinutes()'s -
     * filled with whichever slot key(s) ('am_out'|'pm_out') actually
     * contributed a non-zero component on this call. The no-break single-span
     * case reports 'pm_out' (its own semantic anchor). Always reset to []
     * at the top of this call.
     */
    public function imputedUndertimeMinutes(?string $timeInAm, ?string $timeOutAm, ?string $timeInPm, ?string $timeOutPm, string $shiftDate, WorkSchedule $schedule, array $coveredSlots = [], ?array &$firedComponents = null): int
    {
        $firedComponents = [];

        if ($schedule->punchRequirement !== 'both') {
            return 0;
        }

        if ($schedule->noBreak) {
            if (! $timeInAm || $timeOutPm || in_array('pm_out', $coveredSlots, true)) {
                return 0;
            }

            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

            if (Carbon::now()->lt($endRef)) {
                return 0;
            }

            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
            $firedComponents[] = 'pm_out';

            return (int) $startRef->diffInMinutes($endRef);
        }

        $minutes = 0;

        if ($timeInAm && ! $timeOutAm && ! in_array('am_out', $coveredSlots, true)) {
            $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);

            if (Carbon::now()->gte($breakOutRef)) {
                $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
                $minutes += (int) $startRef->diffInMinutes($breakOutRef);
                $firedComponents[] = 'am_out';
            }
        }

        if ($timeInPm && ! $timeOutPm && ! in_array('pm_out', $coveredSlots, true)) {
            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

            if (Carbon::now()->gte($endRef)) {
                $breakInRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);
                $minutes += (int) $breakInRef->diffInMinutes($endRef);
                $firedComponents[] = 'pm_out';
            }
        }

        // Half day: the whole AM pair missing, PM fully punched. Same
        // morningEnd-passed guard as the am_out component above, for
        // consistency, even though a fully punched PM already implies it.
        if (! $timeInAm && ! $timeOutAm && $timeInPm && $timeOutPm && ! in_array('am_out', $coveredSlots, true)) {
            $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);

            if (Carbon::now()->gte($breakOutRef)) {
                $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
                $minutes += (int) $startRef->diffInMinutes($breakOutRef);
                $firedComponents[] = 'am_out';
            }
        }

        // Half day: the whole PM pair missing, AM fully punched - mirror of
        // the AM case above.
        if (! $timeInPm && ! $timeOutPm && $timeInAm && $timeOutAm && ! in_array('pm_out', $coveredSlots, true)) {
            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

            if (Carbon::now()->gte($endRef)) {
                $breakInRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);
                $minutes += (int) $breakInRef->diffInMinutes($endRef);
                $firedComponents[] = 'pm_out';
            }
        }

        return $minutes;
    }

    /**
     * Resolves REAL (already-punched, non-imputed) late/undertime minutes for
     * a day that's only PARTIALLY covered, gated per-slot by $coveredSlots -
     * the counterpart to imputedLateMinutes()/imputedUndertimeMinutes() above
     * for punches that DID happen rather than ones that are missing.
     *
     * $storedLateMinutes/$storedUndertimeMinutes are the day's already-stored
     * dtrs.late_minutes/undertime_minutes - each itself a sum of two
     * independent components (LateCalculator: am_in + pm_in; UndertimeCalculator:
     * am_out + pm_out - see those classes' own docblocks). A caller that
     * decides "explained or not" for the WHOLE stored value from a single
     * slot's coverage (e.g. "pm_in is suspension-covered, so zero
     * late_minutes") wrongly discards a genuine, unrelated component from the
     * OTHER slot whenever only part of a day is covered - the same bug class
     * already fixed on the imputed side above and in DtrController's Locator
     * branch (Form48ExportService::computeSlotPenalties()).
     *
     * This method resolves each metric (late/undertime) independently by how
     * many of its OWN two relevant slots $coveredSlots names:
     *   - Neither covered: the stored value is trusted as-is. This is
     *     deliberate, not a shortcut - a stored dtrs.late_minutes/
     *     undertime_minutes is already correctly component-scoped for
     *     whichever punches actually happened (Locator/DtrExcuse/
     *     WorkSuspension exclusions are baked in at import time via
     *     PersonnelLogImportService's excludedSlots param), so there is
     *     nothing to recompute when nothing about this metric is covered.
     *   - Both covered: 0, unconditionally - a whole-day source (ETA/Office
     *     Order/Travel Order/full-day Leave/full-day WorkSuspension) covers
     *     every slot regardless of punch data, and the day is fully
     *     authorized either way.
     *   - Exactly one covered: this is the only case genuinely needing
     *     recomputation, since the stored aggregate can't be split without
     *     looking at which slot actually produced which part of it. Rebuilds
     *     a MatchResult from the raw punch times (nulling the covered slot)
     *     and reruns it through the real LateCalculator/UndertimeCalculator,
     *     so the uncovered slot's own genuine contribution survives exactly
     *     as it would in a fully-uncovered day.
     *
     * No punchRequirement guard, unlike the imputed methods above: neither
     * calculator special-cases in_only/out_only, and a real punch's lateness
     * (e.g. a late Field Work Monday check-in) should still score normally -
     * unlike imputation, this isn't inferring a missing punch's existence.
     *
     * Each punch's Carbon is anchored to whichever of {the day before, the
     * same day as, the day after} its slot's own reference time
     * (WorkSchedule::referenceDateTime(), e.g. workEnd for pm_out) lands
     * CLOSEST to that reference - not simply WorkSchedule::slotDate()'s own
     * calendar day for the slot, and not referenceDateTime()+isShiftStart run
     * directly against the punch. Neither of those alone is safe here:
     * referenceDateTime()'s day-rollover rule only holds for the schedule's
     * own fixed threshold values, which have a known, always-forward
     * relationship to workStart - an arbitrary real punch (late or early by
     * definition) does not, so running it against the punch directly can
     * roll a merely-late-but-same-day punch into the wrong day entirely.
     * slotDate() alone is closer (it only ever asks "which day does the
     * THRESHOLD fall on"), but a schedule's own crossesMidnight flag only
     * describes whether the schedule is DESIGNED to cross midnight - it says
     * nothing about whether a given PUNCH happens to spill past midnight
     * anyway. A real, recurring shape in this data: an evening shift ending
     * at e.g. 23:00 (crossesMidnight=false, since workStart < workEnd) whose
     * pm_out is punched a little after 00:00 because the employee stayed
     * unusually late - AttendanceMatcher's own late_out_hours tolerance
     * matches that punch as pm_out at import time, using the punch's own
     * genuine attendance_logs datetime, so there's no ambiguity there; but a
     * bare dtrs.time_out_pm column only keeps the clock value, and pairing
     * it with slotDate()'s answer (unchanged, since crossesMidnight is
     * false) reconstructs it same-day - ~23 hours before the real 23:00
     * reference instead of ~1 hour after it, producing a wildly wrong
     * multi-hour "undertime" instead of the correct 0. Picking whichever of
     * the three adjacent-day candidates is nearest in absolute time to the
     * slot's own reference self-corrects this the same way a human reading
     * "00:04" right after a 23:00 shift end would, while leaving an
     * ordinary, genuinely-close-to-its-reference punch (the overwhelming
     * majority of rows) exactly where slotDate() would already put it.
     *
     * @param  array<int, string>  $coveredSlots  slot keys ('am_in'|'am_out'|'pm_in'|'pm_out')
     *                                             already explained by something else - see
     *                                             imputedLateMinutes()'s docblock for why each
     *                                             component must be gated independently.
     * @return array{late_minutes: int, undertime_minutes: int}
     */
    public function realPenalties(?string $timeInAm, ?string $timeOutAm, ?string $timeInPm, ?string $timeOutPm, string $shiftDate, WorkSchedule $schedule, int $storedLateMinutes, int $storedUndertimeMinutes, array $coveredSlots = []): array
    {
        $lateCoveredCount = count(array_intersect(['am_in', 'pm_in'], $coveredSlots));
        $undertimeCoveredCount = count(array_intersect(['am_out', 'pm_out'], $coveredSlots));

        $recomputed = null;
        if ($lateCoveredCount === 1 || $undertimeCoveredCount === 1) {
            $referenceTime = fn (string $slot): string => match ($slot) {
                'am_in' => $schedule->workStart,
                'am_out' => $schedule->morningEnd,
                'pm_in' => $schedule->lunchReturn,
                'pm_out' => $schedule->workEnd,
            };

            $carbon = function (?string $time, string $slot) use ($shiftDate, $schedule, $coveredSlots, $referenceTime): ?Carbon {
                if (! $time || in_array($slot, $coveredSlots, true)) {
                    return null;
                }

                $reference = $schedule->referenceDateTime($shiftDate, $referenceTime($slot), isShiftStart: $slot === 'am_in');
                $hhmm = substr($time, 0, 5);
                $sameDay = Carbon::parse($schedule->slotDate($shiftDate, $slot).' '.$hhmm.':00');

                $best = $sameDay;
                $bestDiff = abs($sameDay->diffInMinutes($reference, false));
                foreach ([-1, 1] as $dayOffset) {
                    $candidate = $sameDay->copy()->addDays($dayOffset);
                    $diff = abs($candidate->diffInMinutes($reference, false));
                    if ($diff < $bestDiff) {
                        $best = $candidate;
                        $bestDiff = $diff;
                    }
                }

                return $best;
            };

            $result = new MatchResult([
                'am_in' => $carbon($timeInAm, 'am_in'),
                'am_out' => $carbon($timeOutAm, 'am_out'),
                'pm_in' => $carbon($timeInPm, 'pm_in'),
                'pm_out' => $carbon($timeOutPm, 'pm_out'),
                'ot_in' => null,
                'ot_out' => null,
            ], []);

            $recomputed = [
                'late_minutes' => $this->lateCalculator->minutes($result, $shiftDate, $schedule),
                'undertime_minutes' => $this->undertimeCalculator->minutes($result, $shiftDate, $schedule),
            ];
        }

        return [
            'late_minutes' => match ($lateCoveredCount) {
                0 => $storedLateMinutes,
                2 => 0,
                default => $recomputed['late_minutes'],
            },
            'undertime_minutes' => match ($undertimeCoveredCount) {
                0 => $storedUndertimeMinutes,
                2 => 0,
                default => $recomputed['undertime_minutes'],
            },
        ];
    }

    private function fmt(?Carbon $time): ?string
    {
        return $time?->format('H:i:s');
    }
}
