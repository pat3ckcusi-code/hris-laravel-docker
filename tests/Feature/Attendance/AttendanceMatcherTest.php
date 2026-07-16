<?php

namespace Tests\Feature\Attendance;

use App\Services\Attendance\AttendanceMatcher;
use App\Services\Attendance\MatchResult;
use App\Support\WorkSchedule;
use App\Services\DtrPunchResolver;
use Tests\TestCase;

/**
 * AttendanceMatcher in isolation: biometric punches are unordered
 * observations aligned to the schedule's expected events by weighted nearest
 * scheduled time - NEVER by position. A missing punch stays NULL (no later
 * log is ever shifted upward to fill it), and a punch no event can plausibly
 * claim lands in the unmatched pool for review instead of a wrong slot.
 *
 * Uses an 08:00 / 12:00 / 13:00 / 17:00 day (morningEnd 12:00) so the
 * scenarios mirror the engine spec's worked examples.
 */
class AttendanceMatcherTest extends TestCase
{
    private const DATE = '2026-06-11';

    private function specDay(): WorkSchedule
    {
        // workStart, lunchReturn, workEnd, morningEnd, noonEnd
        return new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false);
    }

    private function noBreakDay(): WorkSchedule
    {
        return new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false, true);
    }

    private function nightShift(): WorkSchedule
    {
        return new WorkSchedule('22:00', '02:30', '06:00', '02:00', '06:00', true);
    }

    private function twentyFourHourShift(): WorkSchedule
    {
        return new WorkSchedule('08:00', '08:00', '08:00', '08:00', '08:00', true, true);
    }

    /** @param  list<string>  $times  H:i[:s] on the shift date */
    private function match(array $times, ?WorkSchedule $schedule = null, array $excludedSlots = []): MatchResult
    {
        $punches = array_map(fn (string $t) => self::DATE.' '.(strlen($t) === 5 ? "$t:00" : $t), $times);

        return (new AttendanceMatcher)->match($punches, self::DATE, $schedule ?? $this->specDay(), $excludedSlots);
    }

    private function assertSlots(MatchResult $result, ?string $amIn, ?string $amOut, ?string $pmIn, ?string $pmOut): void
    {
        $fmt = fn (string $slot) => $result->slot($slot)?->format('H:i');
        $this->assertSame($amIn, $fmt('am_in'), 'am_in');
        $this->assertSame($amOut, $fmt('am_out'), 'am_out');
        $this->assertSame($pmIn, $fmt('pm_in'), 'pm_in');
        $this->assertSame($pmOut, $fmt('pm_out'), 'pm_out');
    }

    /** @param  list<string>  $times  H:i expected in the unmatched pool */
    private function assertUnmatched(MatchResult $result, array $times): void
    {
        $this->assertSame($times, array_map(fn ($c) => $c->format('H:i'), $result->unmatched));
    }

    public function test_complete_day_matches_all_four_events(): void
    {
        $r = $this->match(['08:04', '12:01', '13:03', '17:01']);

        $this->assertSlots($r, '08:04', '12:01', '13:03', '17:01');
        $this->assertUnmatched($r, []);
    }

    public function test_boundary_punches_discriminate_between_am_out_and_pm_in(): void
    {
        // 12:02 is a hair past the scheduled 12:00 break-out, not an early lunch return.
        $this->assertSlots($this->match(['12:02']), null, '12:02', null, null);

        // 12:58 is nearly the 13:00 lunch return, not a wildly late break-out.
        $this->assertSlots($this->match(['12:58']), null, null, '12:58', null);
    }

    public function test_very_late_sole_arrival_reads_as_am_in_up_to_the_bias_switchover(): void
    {
        // 10:30 alone: weighted 75 toward the 08:00 arrival beats 90 toward the 12:00 break-out.
        $this->assertSlots($this->match(['10:30']), '10:30', null, null, null);

        // 11:30 alone: 30 toward the break-out beats weighted 105 toward the arrival.
        $this->assertSlots($this->match(['11:30']), null, '11:30', null, null);
    }

    public function test_late_arrival_pair_resolves_when_the_neighboring_event_is_taken(): void
    {
        // Overlapping windows let 10:30 read as am_in once 12:00 claims am_out -
        // a hard midpoint cutoff would forbid this correct reading.
        $this->assertSlots($this->match(['10:30', '12:00']), '10:30', '12:00', null, null);
    }

    public function test_quick_lunch_pair_is_aligned_optimally_not_greedily(): void
    {
        // Greedy nearest-first grabs 12:05 -> am_out (distance 5) and strands
        // 11:50; the order-preserving optimum is am_out=11:50, pm_in=12:05.
        $this->assertSlots($this->match(['11:50', '12:05']), null, '11:50', '12:05', null);
    }

    public function test_missing_am_in_leaves_the_slot_null_without_shifting_logs_upward(): void
    {
        $r = $this->match(['12:02', '13:01', '17:00']);

        $this->assertSlots($r, null, '12:02', '13:01', '17:00');
        $this->assertUnmatched($r, []);
    }

    public function test_missing_am_out_leaves_the_slot_null(): void
    {
        $this->assertSlots($this->match(['07:55', '13:02', '17:00']), '07:55', null, '13:02', '17:00');
    }

    public function test_missing_pm_in_leaves_the_slot_null(): void
    {
        $this->assertSlots($this->match(['07:55', '12:01', '17:00']), '07:55', '12:01', null, '17:00');
    }

    public function test_missing_pm_out_leaves_the_slot_null(): void
    {
        $this->assertSlots($this->match(['07:55', '12:01', '12:58']), '07:55', '12:01', '12:58', null);
    }

    public function test_morning_half_day_fills_only_the_am_pair(): void
    {
        $this->assertSlots($this->match(['07:55', '12:03']), '07:55', '12:03', null, null);
    }

    public function test_afternoon_half_day_fills_only_the_pm_pair(): void
    {
        $this->assertSlots($this->match(['12:57', '17:02']), null, null, '12:57', '17:02');
    }

    public function test_each_single_punch_lands_in_its_own_slot(): void
    {
        $this->assertSlots($this->match(['07:58']), '07:58', null, null, null);
        $this->assertSlots($this->match(['12:01']), null, '12:01', null, null);
        $this->assertSlots($this->match(['13:05']), null, null, '13:05', null);
        $this->assertSlots($this->match(['17:05']), null, null, null, '17:05');
    }

    public function test_zero_punches_yield_all_null_and_nothing_unmatched(): void
    {
        $r = $this->match([]);

        $this->assertSlots($r, null, null, null, null);
        $this->assertUnmatched($r, []);
    }

    public function test_rescan_punches_go_to_the_unmatched_pool(): void
    {
        $r = $this->match(['07:55', '09:00', '12:01', '12:58', '15:00', '17:05']);

        $this->assertSlots($r, '07:55', '12:01', '12:58', '17:05');
        $this->assertUnmatched($r, ['09:00', '15:00']);
    }

    public function test_repeated_scans_within_the_dedupe_threshold_collapse(): void
    {
        $r = $this->match(['08:00:00', '08:00:30']);

        $this->assertSlots($r, '08:00', null, null, null);
        $this->assertUnmatched($r, []);
    }

    public function test_punch_outside_every_window_is_unmatched_not_forced_into_a_slot(): void
    {
        $r = $this->match(['03:00']);

        $this->assertSlots($r, null, null, null, null);
        $this->assertUnmatched($r, ['03:00']);
    }

    public function test_night_shift_matches_across_midnight(): void
    {
        $next = '2026-06-12';
        $punches = [self::DATE.' 21:58:00', "$next 02:01:00", "$next 02:29:00", "$next 05:45:00"];

        $r = (new AttendanceMatcher)->match($punches, self::DATE, $this->nightShift());

        $this->assertSame('21:58', $r->slot('am_in')?->format('H:i'));
        $this->assertSame('02:01', $r->slot('am_out')?->format('H:i'));
        $this->assertSame('02:29', $r->slot('pm_in')?->format('H:i'));
        $this->assertSame('05:45', $r->slot('pm_out')?->format('H:i'));
        $this->assertUnmatched($r, []);
    }

    public function test_24_hour_no_break_shift_matches_its_two_punches(): void
    {
        $punches = [self::DATE.' 08:10:00', '2026-06-12 07:50:00'];

        $r = (new AttendanceMatcher)->match($punches, self::DATE, $this->twentyFourHourShift());

        $this->assertSame('08:10', $r->slot('am_in')?->format('H:i'));
        $this->assertNull($r->slot('am_out'));
        $this->assertNull($r->slot('pm_in'));
        $this->assertSame('07:50', $r->slot('pm_out')?->format('H:i'));
    }

    public function test_no_break_shift_sends_a_midday_punch_to_the_unmatched_pool(): void
    {
        $r = $this->match(['08:01', '12:00', '16:55'], $this->noBreakDay());

        $this->assertSlots($r, '08:01', null, null, '16:55');
        $this->assertUnmatched($r, ['12:00']);
    }

    public function test_excused_slot_punch_goes_unmatched_instead_of_sliding_into_the_next_slot(): void
    {
        // A DtrExcuse (null window) removes the am_in event entirely; the
        // 07:55 punch is before workStart so no other event can claim it.
        $r = $this->match(['07:55'], excludedSlots: ['am_in' => null]);

        $this->assertSlots($r, null, null, null, null);
        $this->assertUnmatched($r, ['07:55']);
    }

    public function test_locator_window_rejects_inside_punches_but_keeps_outside_ones(): void
    {
        $exclusion = ['pm_in' => ['13:00', '15:00']];

        // 12:48 is before the declared departure - the genuine lunch return.
        $this->assertSlots(
            $this->match(['07:55', '12:01', '12:48'], excludedSlots: $exclusion),
            '07:55', '12:01', '12:48', null
        );

        // 14:00 is inside the declared travel window - never a real pm_in.
        $this->assertNull(
            $this->match(['07:55', '12:01', '14:00'], excludedSlots: $exclusion)->slot('pm_in')
        );
    }

    public function test_resolver_and_matcher_agree_on_slot_assignment(): void
    {
        // The orchestrator must expose exactly what the matcher decided.
        $resolved = (new DtrPunchResolver)->resolve(
            [self::DATE.' 12:02:00', self::DATE.' 13:01:00', self::DATE.' 17:00:00'],
            self::DATE,
            $this->specDay()
        );

        $this->assertNull($resolved['am_in']);
        $this->assertSame('12:02:00', $resolved['am_out']);
        $this->assertSame('13:01:00', $resolved['pm_in']);
        $this->assertSame('17:00:00', $resolved['pm_out']);
    }
}
