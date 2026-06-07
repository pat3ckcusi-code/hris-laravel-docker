<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves a single day's raw biometric punches into the four CSC Form 48
 * slots (AM arrival/departure, PM arrival/departure) plus tardiness and
 * undertime minutes.
 *
 * Shared by PersonnelLogImportService (writes the dtrs table) and
 * Form48ExportService (export fallback) so the two can never disagree.
 *
 * Slot assignment:
 *  - 1–4 punches: SEQUENTIAL — 1st → AM arrival, 2nd → AM departure,
 *    3rd → PM arrival, 4th → PM departure. Fewer than four fill only the
 *    leading slots; no slot is duplicated and the PM arrival is never dropped.
 *  - 5+ punches (re-scans): the bookends anchor AM arrival (first) and PM
 *    departure (last), and the midday cluster collapses to the lunch pair
 *    (first/last punch in the 11:00–14:00 window). This prevents the real
 *    arrival/departure from being lost behind duplicate scans.
 *
 * Penalties are TIME-AWARE: a slot is only scored when its time is plausible
 * for the role (morning arrival < 11:00; lunch return in 11:00–14:00; afternoon
 * departure >= 13:00), so misread punches on malformed days (e.g. a noon-only
 * day with no morning scan) don't inflate the totals.
 */
class DtrPunchResolver
{
    private const WORK_START = '08:00';

    private const LUNCH_RETURN = '13:00';

    private const WORK_END = '17:00';

    private const MORNING_END = '11:00';

    private const NOON_END = '14:00';

    /**
     * @param  iterable<int, string>  $times  punch times for ONE day (HH:MM:SS)
     * @return array{am_in:?string, am_out:?string, pm_in:?string, pm_out:?string, late_minutes:int, undertime_minutes:int}
     */
    public function resolve(iterable $times, string $date): array
    {
        // Normalize, de-duplicate (repeated scans within a minute), sort ascending.
        $sorted = collect($times)
            ->map(fn ($t) => substr((string) $t, 0, 8))
            ->filter(fn ($t) => $t !== '')
            ->unique()
            ->sort()
            ->values();

        [$amIn, $amOut, $pmIn, $pmOut] = $this->assignSlots($sorted);

        $hm = fn (?string $t): ?string => $t !== null ? substr($t, 0, 5) : null;

        // ── Time-aware penalties ──
        $late = 0;

        // Morning lateness — only when the arrival is genuinely a morning punch.
        if ($amIn !== null && $hm($amIn) < self::MORNING_END) {
            $late += $this->minutesLate($date, $amIn, self::WORK_START);
        }

        // Lunch-return lateness — only when the PM arrival lands in the lunch window.
        if ($pmIn !== null && $hm($pmIn) >= self::MORNING_END && $hm($pmIn) < self::NOON_END) {
            $late += $this->minutesLate($date, $pmIn, self::LUNCH_RETURN);
        }

        // Undertime — only when the departure is genuinely an afternoon punch.
        $undertime = 0;
        if ($pmOut !== null && $hm($pmOut) >= self::LUNCH_RETURN) {
            $undertime = $this->minutesEarly($date, $pmOut, self::WORK_END);
        }

        return [
            'am_in' => $amIn,
            'am_out' => $amOut,
            'pm_in' => $pmIn,
            'pm_out' => $pmOut,
            'late_minutes' => $late,
            'undertime_minutes' => $undertime,
        ];
    }

    /**
     * Map sorted, de-duplicated punches to the four slots.
     *
     * @param  Collection<int, string>  $sorted  ascending HH:MM:SS punch times
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} [am_in, am_out, pm_in, pm_out]
     */
    private function assignSlots(Collection $sorted): array
    {
        $count = $sorted->count();

        // 1–4 punches map straight to the four slots in chronological order.
        // Missing positions stay null (Collection::get returns null when absent).
        if ($count <= 4) {
            return [$sorted->get(0), $sorted->get(1), $sorted->get(2), $sorted->get(3)];
        }

        // 5+ punches: the day has re-scans. Anchor the bookends so the genuine
        // arrival and departure are never lost, then collapse the midday cluster.
        $amIn = $sorted->first();
        $pmOut = $sorted->last();

        $lunch = $sorted
            ->slice(1, $count - 2)                       // exclude the bookends
            ->filter(fn ($t) => substr($t, 0, 5) >= self::MORNING_END
                             && substr($t, 0, 5) < self::NOON_END)
            ->values();

        if ($lunch->count() >= 2) {
            $amOut = $lunch->first();   // first lunch-window punch = out for lunch
            $pmIn = $lunch->last();     // last lunch-window punch  = back from lunch
        } else {
            // No clear midday cluster — fall back to the 2nd and 2nd-to-last
            // punch. With 5+ punches these are always distinct, so no slot
            // is ever duplicated.
            $amOut = $sorted->get(1);
            $pmIn = $sorted->get($count - 2);
        }

        return [$amIn, $amOut, $pmIn, $pmOut];
    }

    private function minutesLate(string $date, string $actual, string $reference): int
    {
        $ref = Carbon::parse("$date $reference");
        $act = Carbon::parse("$date $actual");

        return $act->gt($ref) ? (int) $ref->diffInMinutes($act) : 0;
    }

    private function minutesEarly(string $date, string $actual, string $reference): int
    {
        $ref = Carbon::parse("$date $reference");
        $act = Carbon::parse("$date $actual");

        return $act->lt($ref) ? (int) $act->diffInMinutes($ref) : 0;
    }
}
