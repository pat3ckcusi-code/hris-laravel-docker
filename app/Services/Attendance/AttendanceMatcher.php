<?php

namespace App\Services\Attendance;

use App\Support\WorkSchedule;
use Carbon\Carbon;

/**
 * Matches one shift's raw biometric punches against the expected attendance
 * events derived from the employee's WorkSchedule.
 *
 * Punches are UNORDERED OBSERVATIONS, never positional: the 1st punch is not
 * assumed to be AM In. Each punch is aligned to the nearest plausible expected
 * event (by weighted distance to the event's scheduled time, bounded by an
 * eligibility window); an event with no plausible punch stays NULL, and a
 * punch no event can claim goes to the unmatched pool for review. A missing
 * punch therefore never pulls a later log upward into the wrong slot.
 *
 * Alignment is an order-preserving optimal assignment, not a greedy
 * nearest-first pass: sorted punches must map to chronologically ordered
 * events IN ORDER (a break-out cannot precede an arrival), so the matcher
 * runs a small sequence-alignment DP maximizing the total score, where each
 * match earns (match_reward_minutes - weighted distance). Greedy misfires on
 * pairs like 11:50 + 12:05 against AM Out 12:00 / PM In 13:00 (it grabs
 * 12:05 -> AM Out and strands 11:50); the DP correctly yields am_out=11:50,
 * pm_in=12:05. The bounded per-match reward (rather than "always maximize
 * match count") is what keeps a stray re-scan in the unmatched pool instead
 * of letting it contort the whole assignment just to fill one more slot.
 *
 * Event eligibility windows deliberately OVERLAP between neighbors - a hard
 * midpoint cutoff would forbid the correct reading when the neighboring event
 * is already taken (a 10:30 + 12:00 pair must resolve to am_in=10:30 /
 * am_out=12:00). Distance plus the IN-late bias decide inside overlaps; the
 * windows are only outer plausibility bounds. Tunables live in
 * config/attendance.php.
 *
 * This class is independent of every calculator: it only decides WHICH punch
 * is WHICH event. Late/undertime/hours/status are computed afterwards from
 * its MatchResult.
 */
class AttendanceMatcher
{
    private const SLOT_ORDER = ['am_in', 'am_out', 'pm_in', 'pm_out', 'ot_in', 'ot_out'];

    /**
     * @param  iterable<int, Carbon|string>  $punches  full punch datetimes for ONE shift
     * @param  array<string, array{0:string,1:string}|null>  $excludedSlots  slot key => exclusion window
     *                                                                       ['H:i', 'H:i'], or null for an unconditional exclusion (a DtrExcuse,
     *                                                                       where no real punch is expected regardless of time - the event is
     *                                                                       removed from the expected set entirely). A windowed slot (a locator's
     *                                                                       [departure, arrival] span) keeps its event but rejects punches inside
     *                                                                       the window - a punch outside it is real and must land in its natural slot.
     */
    public function match(iterable $punches, string $shiftDate, WorkSchedule $schedule, array $excludedSlots = []): MatchResult
    {
        $deduped = $this->normalize($punches);
        $events = $this->buildExpectedEvents($shiftDate, $schedule, $excludedSlots);

        [$pairs, $unmatchedIdx] = $this->align($deduped, $events);

        $matched = array_fill_keys(self::SLOT_ORDER, null);
        foreach ($pairs as [$punchIdx, $eventIdx]) {
            $matched[$events[$eventIdx]->slot] = $deduped[$punchIdx];
        }

        return new MatchResult(
            matched: $matched,
            unmatched: array_values(array_map(fn (int $i) => $deduped[$i], $unmatchedIdx)),
        );
    }

    /**
     * Normalize to Carbon, sort ascending, and collapse repeated scans made
     * within the dedupe threshold of the previous kept punch.
     *
     * @param  iterable<int, Carbon|string>  $punches
     * @return list<Carbon>
     */
    private function normalize(iterable $punches): array
    {
        $sorted = collect($punches)
            ->map(fn ($p) => $p instanceof Carbon ? $p->copy() : Carbon::parse((string) $p))
            ->sortBy(fn (Carbon $c) => $c->getTimestamp())
            ->values();

        $threshold = (int) config('attendance.matching.dedupe_seconds', 60);
        $deduped = [];

        foreach ($sorted as $punch) {
            $last = $deduped === [] ? null : $deduped[count($deduped) - 1];

            if ($last === null || $punch->getTimestamp() - $last->getTimestamp() >= $threshold) {
                $deduped[] = $punch;
            }
        }

        return $deduped;
    }

    /**
     * The expected events for this shift, chronological. Windows overlap by
     * design (see class docblock); the exact bounds:
     *
     *   AM In   [workStart - early_in_hours, morningEnd)
     *   AM Out  [workStart, lunchReturn)
     *   PM In   (morningEnd, workEnd)
     *   PM Out  (lunchReturn, workEnd + late_out_hours]  - lower bound clamped
     *           to workEnd so a punch exactly at shift end still qualifies on a
     *           degenerate schedule whose lunchReturn equals workEnd
     *
     * A no-break shift (including 24h duty) has only two events, mapped to the
     * am_in / pm_out slots per the existing dtrs convention:
     *
     *   IN   [workStart - early_in_hours, workEnd)
     *   OUT  (workStart, workEnd + late_out_hours]
     *
     * On a Standard Day schedule ($schedule->isStandardDay - the global
     * default, never a per-employee Shift template) two more events are
     * appended, OT In / OT Out, both open-ended through the end of the shift
     * date. PM Out's own window is ALSO widened to the end of the day for a
     * Standard Day (instead of the late_out_hours cap every other schedule
     * keeps), so a single very-late punch with no dedicated OT pair is never
     * excluded from matching PM Out. OT In and OT Out are deliberately BOTH
     * built with isIn=false (out_late_bias, not in_late_bias) even though
     * "In" is in the name - this makes their reward identical for the same
     * distance from the anchor, which is what makes a single leftover late
     * punch resolve to the earliest-in-sequence tied event: PM Out when no
     * other late-side event has claimed it yet, OT In (never OT Out) when PM
     * Out is already matched to a genuine on-time punch.
     *
     * @param  array<string, array{0:string,1:string}|null>  $excludedSlots
     * @return list<ExpectedEvent>
     */
    private function buildExpectedEvents(string $shiftDate, WorkSchedule $schedule, array $excludedSlots): array
    {
        $earlyIn = (float) config('attendance.matching.early_in_hours', 4.0);
        $lateOut = (float) config('attendance.matching.late_out_hours', 4.0);

        $start = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
        $end = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

        // Standard Day schedules widen PM Out's own late-side window to the
        // end of the shift date (instead of the late_out_hours cap) so a
        // lone very-late punch always stays eligible for PM Out rather than
        // being excluded and misread as an orphaned OT In. Every other
        // schedule keeps today's exact late_out_hours-capped window.
        $pmOutWindowEnd = $schedule->isStandardDay
            ? $end->copy()->endOfDay()
            : $end->copy()->addMinutes((int) round($lateOut * 60));

        if ($schedule->noBreak) {
            $candidates = [
                new ExpectedEvent('am_in', true, $start, $start->copy()->subMinutes((int) round($earlyIn * 60)), $end->copy()->subSecond()),
                new ExpectedEvent('pm_out', false, $end, $start->copy()->addSecond(), $pmOutWindowEnd),
            ];
        } else {
            $breakOut = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);
            $lunchReturn = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);

            $candidates = [
                new ExpectedEvent('am_in', true, $start, $start->copy()->subMinutes((int) round($earlyIn * 60)), $breakOut->copy()->subSecond()),
                new ExpectedEvent('am_out', false, $breakOut, $start->copy(), $lunchReturn->copy()->subSecond()),
                new ExpectedEvent('pm_in', true, $lunchReturn, $breakOut->copy()->addSecond(), $end->copy()->subSecond()),
                new ExpectedEvent('pm_out', false, $end, $lunchReturn->copy()->addSecond()->min($end), $pmOutWindowEnd),
            ];
        }

        if ($schedule->isStandardDay) {
            $dayEnd = $end->copy()->endOfDay(); // already rolls to shiftDate+1 if $end itself crossed midnight

            $candidates[] = new ExpectedEvent('ot_in', false, $end, $end->copy(), $dayEnd->copy());
            $candidates[] = new ExpectedEvent('ot_out', false, $end, $end->copy(), $dayEnd->copy());
        }

        $events = [];

        foreach ($candidates as $event) {
            if (! array_key_exists($event->slot, $excludedSlots)) {
                $events[] = $event;

                continue;
            }

            $window = $excludedSlots[$event->slot];

            if ($window === null) {
                // Unconditional exclusion (DtrExcuse): no punch is expected
                // here at all - drop the event so no punch can be claimed by it.
                continue;
            }

            $events[] = new ExpectedEvent(
                slot: $event->slot,
                isIn: $event->isIn,
                scheduledAt: $event->scheduledAt,
                windowStart: $event->windowStart,
                windowEnd: $event->windowEnd,
                exclusionWindow: [
                    Carbon::parse($shiftDate.' '.substr($window[0], 0, 5).':00'),
                    Carbon::parse($shiftDate.' '.substr($window[1], 0, 5).':00'),
                ],
            );
        }

        return $events;
    }

    /**
     * Order-preserving optimal alignment of ascending punches to
     * chronologically ordered events, maximizing total score: each match
     * earns (match_reward_minutes - weighted distance). The bounded reward
     * means an extra match is only taken when the punch genuinely fits -
     * never by contorting the rest of the assignment to squeeze one more
     * punch into a slot. On exact ties, matching beats skipping the event,
     * which beats sending the punch to the unmatched pool.
     *
     * @param  list<Carbon>  $punches
     * @param  list<ExpectedEvent>  $events
     * @return array{0: list<array{0:int, 1:int}>, 1: list<int>} matched [punchIdx, eventIdx] pairs and unmatched punch indexes
     */
    private function align(array $punches, array $events): array
    {
        $n = count($punches);
        $m = count($events);
        $reward = (float) config('attendance.matching.match_reward_minutes', 240);

        // dp[i][j] = [score, choice] for the subproblem starting at punch i /
        // event j. choice: M = match both, E = skip event, P = skip punch.
        $dp = [];

        for ($i = $n; $i >= 0; $i--) {
            for ($j = $m; $j >= 0; $j--) {
                if ($i === $n || $j === $m) {
                    $dp[$i][$j] = [0.0, null];

                    continue;
                }

                $best = null;
                $choice = null;

                if ($this->eligible($punches[$i], $events[$j])) {
                    $gain = $reward - $this->weightedDistance($punches[$i], $events[$j]);
                    $best = $dp[$i + 1][$j + 1][0] + $gain;
                    $choice = 'M';
                }

                foreach (['E' => $dp[$i][$j + 1][0], 'P' => $dp[$i + 1][$j][0]] as $alt => $score) {
                    if ($best === null || $score > $best) {
                        $best = $score;
                        $choice = $alt;
                    }
                }

                $dp[$i][$j] = [$best, $choice];
            }
        }

        $pairs = [];
        $unmatched = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            switch ($dp[$i][$j][1]) {
                case 'M':
                    $pairs[] = [$i, $j];
                    $i++;
                    $j++;
                    break;
                case 'E':
                    $j++;
                    break;
                default: // 'P'
                    $unmatched[] = $i;
                    $i++;
            }
        }

        for (; $i < $n; $i++) {
            $unmatched[] = $i;
        }

        return [$pairs, $unmatched];
    }

    private function eligible(Carbon $punch, ExpectedEvent $event): bool
    {
        if ($punch->lt($event->windowStart) || $punch->gt($event->windowEnd)) {
            return false;
        }

        if ($event->exclusionWindow !== null
            && $punch->gte($event->exclusionWindow[0])
            && $punch->lte($event->exclusionWindow[1])) {
            return false;
        }

        return true;
    }

    /**
     * Distance in minutes between a punch and an event's scheduled time,
     * weighted by the late-side bias: punching AFTER the scheduled time is
     * the expected, benign deviation for both event types (a late arrival
     * for IN, a late lunch-out or post-shift departure for OUT), so
     * post-scheduled distance counts for less than pre-scheduled distance.
     * When tie_break is 'in', IN events get a hair's-width nudge so an
     * exactly equidistant punch prefers the IN reading.
     */
    private function weightedDistance(Carbon $punch, ExpectedEvent $event): float
    {
        $distance = abs($punch->getTimestamp() - $event->scheduledAt->getTimestamp()) / 60.0;

        if ($punch->gt($event->scheduledAt)) {
            $key = $event->isIn ? 'in_late_bias' : 'out_late_bias';
            $distance *= (float) config("attendance.matching.$key", 0.5);
        }

        if ($event->isIn && config('attendance.matching.tie_break', 'in') === 'in') {
            $distance -= 1e-6;
        }

        return $distance;
    }
}
