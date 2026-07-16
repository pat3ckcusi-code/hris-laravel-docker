<?php

namespace App\Services\Attendance;

use Carbon\Carbon;

/**
 * One scheduled attendance event a biometric punch can be matched against:
 * an expected IN or OUT at a specific datetime, with an eligibility window
 * bounding how far a punch may stray and still plausibly be this event.
 *
 * All datetimes are built from WorkSchedule::referenceDateTime(), so a
 * crossing shift's post-midnight events already live on shiftDate + 1.
 */
final class ExpectedEvent
{
    /**
     * @param  string  $slot  'am_in'|'am_out'|'pm_in'|'pm_out' (dtrs column the match lands in)
     * @param  bool  $isIn  IN events weight post-scheduled distance by the late bias
     * @param  array{0: Carbon, 1: Carbon}|null  $exclusionWindow  punches inside this span are
     *                                                             ineligible for this event only (a locator's
     *                                                             [departure, arrival] coverage)
     */
    public function __construct(
        public readonly string $slot,
        public readonly bool $isIn,
        public readonly Carbon $scheduledAt,
        public readonly Carbon $windowStart,
        public readonly Carbon $windowEnd,
        public readonly ?array $exclusionWindow = null,
    ) {}
}
