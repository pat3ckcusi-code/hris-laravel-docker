<?php

namespace App\Services;

use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves a single shift's raw biometric punches into the four CSC Form 48
 * slots (arrival, break-out, break-in, departure) plus tardiness and undertime
 * minutes.
 *
 * Shared by PersonnelLogImportService (writes the dtrs table) and
 * Form48ExportService (export fallback) so the two can never disagree.
 *
 * Punches arrive as full datetimes already grouped onto one logical shift date
 * (see ShiftPunchGrouper), so a night shift's pre-midnight arrival and
 * post-midnight departure sort and score correctly across the day boundary.
 *
 * Slot assignment:
 *  - 1–4 punches: SEQUENTIAL - 1st → arrival, 2nd → break-out, 3rd → break-in,
 *    4th → departure. Fewer than four fill only the leading slots.
 *  - 5+ punches (re-scans): the bookends anchor arrival (first) and departure
 *    (last), and the midday cluster collapses to the break pair (first/last
 *    punch inside the [break-out, shift-end) window).
 *
 * Penalties are TIME-AWARE: a slot is only scored when its datetime is plausible
 * for the role, so misread punches on malformed shifts don't inflate totals.
 */
class DtrPunchResolver
{
    /**
     * @param  iterable<int, Carbon|string>  $punches  full punch datetimes for ONE shift
     * @param  array<int, string>  $excludedSlots  slot keys ('am_in'|'am_out'|'pm_in'|'pm_out') known to
     *                                             have no real punch (e.g. covered by a DtrExcuse), so the
     *                                             1-4 punch case doesn't mis-slot a later punch into them
     * @return array{am_in:?string, am_out:?string, pm_in:?string, pm_out:?string, late_minutes:int, undertime_minutes:int}
     */
    public function resolve(iterable $punches, string $shiftDate, WorkSchedule $schedule, array $excludedSlots = []): array
    {
        // Normalize to Carbon, de-duplicate (repeated scans within a minute), sort ascending.
        $sorted = collect($punches)
            ->map(fn ($p) => $p instanceof Carbon ? $p->copy() : Carbon::parse((string) $p))
            ->sortBy(fn (Carbon $c) => $c->getTimestamp())
            ->values();

        $deduped = collect();
        foreach ($sorted as $punch) {
            $last = $deduped->last();
            if ($last === null || abs($punch->getTimestamp() - $last->getTimestamp()) >= 60) {
                $deduped->push($punch);
            }
        }

        [$amIn, $amOut, $pmIn, $pmOut] = $this->assignSlots($deduped, $shiftDate, $schedule, $excludedSlots);

        // ── Reference datetimes (rolled past midnight for crossing shifts) ──
        $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
        $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);
        $breakInRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);
        $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

        // ── Time-aware penalties ──
        $late = 0;

        // Arrival lateness - only when the arrival is genuinely a shift-start punch.
        if ($amIn !== null && $amIn->lt($breakOutRef)) {
            $late += $this->minutesLate($amIn, $startRef);
        }

        // Break-return lateness - only when the return lands inside the break window.
        if ($pmIn !== null && $pmIn->gte($breakOutRef) && $pmIn->lt($endRef)) {
            $late += $this->minutesLate($pmIn, $breakInRef);
        }

        // Undertime - only when the departure is genuinely a shift-end punch.
        // For no-break shifts pm_out is the only departure punch, so use workStart
        // as the lower bound instead of breakInRef (which has no meaning without a break).
        $undertime = 0;
        $pmOutLower = $schedule->noBreak ? $startRef : $breakInRef;
        if ($pmOut !== null && $pmOut->gte($pmOutLower)) {
            $undertime = $this->minutesEarly($pmOut, $endRef);
        }

        return [
            'am_in' => $this->fmt($amIn),
            'am_out' => $this->fmt($amOut),
            'pm_in' => $this->fmt($pmIn),
            'pm_out' => $this->fmt($pmOut),
            'late_minutes' => $late,
            'undertime_minutes' => $undertime,
        ];
    }

    /**
     * Map sorted, de-duplicated punch datetimes to the four slots.
     *
     * @param  Collection<int, Carbon>  $sorted  ascending punch datetimes
     * @param  array<int, string>  $excludedSlots  slot keys known to have no real punch
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?Carbon, 3: ?Carbon} [am_in, am_out, pm_in, pm_out]
     */
    private function assignSlots(Collection $sorted, string $shiftDate, WorkSchedule $schedule, array $excludedSlots = []): array
    {
        $count = $sorted->count();

        // No-break shifts: employees only punch IN and OUT (no lunch break).
        // Map first punch → am_in, last punch → pm_out; middle punches are re-scans.
        if ($schedule->noBreak) {
            return [$sorted->get(0), null, null, $count >= 2 ? $sorted->last() : null];
        }

        // 1–4 punches map straight to the four slots in chronological order, unless
        // some slots are known excused (no real punch expected there) - then skip
        // those slots so later punches land in their correct slot instead of being
        // pushed into the excused one. Only applies when there are few enough
        // punches that the exclusion is plausible; if punches exist for every slot
        // anyway, trust the data and keep the full positional assignment.
        if ($count <= 4) {
            if ($excludedSlots !== []) {
                $slotOrder = ['am_in', 'am_out', 'pm_in', 'pm_out'];
                $eligibleSlots = array_values(array_diff($slotOrder, $excludedSlots));

                if ($count <= count($eligibleSlots)) {
                    $values = array_fill_keys($slotOrder, null);
                    foreach ($eligibleSlots as $i => $slotKey) {
                        $values[$slotKey] = $sorted->get($i);
                    }

                    return [$values['am_in'], $values['am_out'], $values['pm_in'], $values['pm_out']];
                }
            }

            // A punch at/after shift end is unambiguously a departure (nobody returns
            // from lunch after the shift already ended) - anchor it to pm_out even with
            // fewer than 4 total punches, instead of leaving it in its naive positional
            // slot where it reads as a lunch punch. No-op when count === 4, since the
            // last punch is already positionally pm_out there.
            if ($count >= 2) {
                $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);
                $last = $sorted->last();

                if ($last->gte($endRef)) {
                    $leading = $sorted->slice(0, $count - 1)->values();

                    return [$leading->get(0), $leading->get(1), $leading->get(2), $last];
                }
            }

            return [$sorted->get(0), $sorted->get(1), $sorted->get(2), $sorted->get(3)];
        }

        // 5+ punches: the shift has re-scans. Anchor the bookends, then collapse
        // the midday cluster inside the [break-out, shift-end) window.
        $amIn = $sorted->first();
        $pmOut = $sorted->last();

        $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);
        $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

        $break = $sorted
            ->slice(1, $count - 2)
            ->filter(fn (Carbon $t) => $t->gte($breakOutRef) && $t->lt($endRef))
            ->values();

        if ($break->count() >= 2) {
            $amOut = $break->first();   // first break-window punch = out for break
            $pmIn = $break->last();     // last break-window punch  = back from break
        } else {
            // No clear cluster - fall back to the 2nd and 2nd-to-last punch.
            $amOut = $sorted->get(1);
            $pmIn = $sorted->get($count - 2);
        }

        return [$amIn, $amOut, $pmIn, $pmOut];
    }

    private function minutesLate(Carbon $actual, Carbon $reference): int
    {
        return $actual->gt($reference) ? (int) $reference->diffInMinutes($actual) : 0;
    }

    private function minutesEarly(Carbon $actual, Carbon $reference): int
    {
        return $actual->lt($reference) ? (int) $actual->diffInMinutes($reference) : 0;
    }

    private function fmt(?Carbon $time): ?string
    {
        return $time?->format('H:i:s');
    }
}
