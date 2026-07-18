<?php

namespace App\Services\Attendance;

use Carbon\Carbon;

/**
 * The outcome of matching one shift's punches against its expected events:
 * each of the four slots holds its matched punch or null (a genuinely
 * missing punch - never backfilled from a later log), and any punch that
 * matched no event is kept for review rather than silently dropped.
 */
final class MatchResult
{
    /**
     * @param  array{am_in: ?Carbon, am_out: ?Carbon, pm_in: ?Carbon, pm_out: ?Carbon, ot_in: ?Carbon, ot_out: ?Carbon}  $matched
     *                                                                                                                  ot_in/ot_out are only ever populated on a Standard Day schedule
     * @param  list<Carbon>  $unmatched  punches no event could plausibly claim, ascending
     */
    public function __construct(
        public readonly array $matched,
        public readonly array $unmatched,
    ) {}

    public function slot(string $key): ?Carbon
    {
        return $this->matched[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->slot($key) !== null;
    }
}
