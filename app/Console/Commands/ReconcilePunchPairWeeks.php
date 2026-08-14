<?php

namespace App\Console\Commands;

use App\Services\Attendance\WeeklyPunchPairReconciliationService;
use Illuminate\Console\Command;

/**
 * Retroactively resolves any "Field Work" Monday in_only / Friday out_only
 * punch pairing whose week has fully closed - see
 * WeeklyPunchPairReconciliationService for the full rule. Runs nightly,
 * scoped to Fridays already strictly in the past, so it can never race
 * attendance:auto-import (which only ever touches today-1/today).
 */
class ReconcilePunchPairWeeks extends Command
{
    protected $signature = 'attendance:reconcile-punch-pairs';

    protected $description = 'Retroactively resolve incomplete Field Work Monday/Friday punch pairings into real absences';

    public function handle(WeeklyPunchPairReconciliationService $service): int
    {
        $result = $service->reconcile();

        $this->info("Checked {$result['weeks_checked']} week(s), reconciled {$result['weeks_reconciled']}.");

        return self::SUCCESS;
    }
}
