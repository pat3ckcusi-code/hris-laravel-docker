<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Punch-to-event matching
    |--------------------------------------------------------------------------
    |
    | AttendanceMatcher aligns raw biometric punches against the expected
    | attendance events derived from an employee's WorkSchedule (AM In,
    | AM Out, PM In, PM Out). These values bound and weight that matching;
    | the inner window edges between adjacent events come from the schedule
    | itself, not from config.
    |
    */

    'matching' => [

        // Repeated scans within this many seconds collapse to the first punch.
        'dedupe_seconds' => 60,

        // A punch may precede a scheduled IN event by at most this many hours
        // and still be considered that event's punch (early arrivals).
        'early_in_hours' => 4.0,

        // A punch may follow a scheduled OUT event by at most this many hours
        // and still be considered that event's punch (late departures /
        // overtime). Beyond it the punch is kept unmatched for review.
        'late_out_hours' => 4.0,

        // Weight applied to the punch-to-scheduled-time distance when a punch
        // is AFTER a scheduled IN event. Lateness is the expected failure mode
        // for arrivals, so a 10:30 punch on an 08:00-12:00 morning should read
        // as a very late arrival rather than an early break-out. 0.5 puts the
        // AM In / AM Out switchover of that morning at ~10:40.
        'in_late_bias' => 0.5,

        // Same idea for OUT events: punching AFTER a scheduled OUT (leaving
        // late for lunch, working past shift end) is the benign, common
        // deviation, while an early OUT is undertime. Without this, a 12:03
        // lunch-out on an 11:00-break schedule would sit nearer the 13:00
        // PM In than its own 11:00 AM Out by raw distance. 0.33 puts that
        // schedule's AM Out / PM In switchover at ~12:30, matching observed
        // behavior (a verified 12:29 punch was a late lunch-out; typical
        // early returns land 12:45+).
        'out_late_bias' => 0.33,

        // When a punch is exactly equidistant (after weighting) between an IN
        // and an OUT event, prefer the IN event.
        'tie_break' => 'in',

        // How much (in weighted minutes) matching a punch to an event is worth.
        // Each match scores (reward - weighted distance), so a punch only gets
        // matched when it fits within this budget - and, crucially, squeezing
        // one MORE punch into the slots never wins when doing so contorts the
        // whole assignment (e.g. a stray 09:30 re-scan must go to the
        // unmatched pool, not claim AM Out and shove the real 12:01 break-out
        // into PM In). Must exceed the largest legitimate weighted distance
        // (a locator-displaced departure can sit ~180 from its slot).
        'match_reward_minutes' => 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Weekly punch-pair reconciliation ("Field Work" shift)
    |--------------------------------------------------------------------------
    |
    | WeeklyPunchPairReconciliationService retroactively resolves a Monday
    | in_only / Friday out_only shift pairing once a week has fully closed.
    | See App\Console\Commands\ReconcilePunchPairWeeks.
    |
    */

    'punch_pair_reconciliation' => [

        // How many days back from "today" to scan for weeks needing
        // reconciliation. Wide enough to self-heal a late-corrected week
        // (e.g. a backfilled punch import) without rescanning an employee's
        // entire history every night.
        'lookback_days' => 45,
    ],

];
