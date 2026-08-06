<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\LeaveLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMonthlyCreditBalances extends Command
{
    protected $signature = 'leave-balance:backfill-monthly-credits {--dry-run : Preview the changes without writing them}';

    protected $description = 'One-time bridge command: apply every CREDIT_EARNED/CREDIT_EARNED_WOP/CREDIT_CORRECTION leave_ledger row ever written to the real leave_balances.VL/.SL, since the monthly-credit flow historically never touched that table. NOT safely re-runnable (running it twice double-credits) — run it once, right after deploying the fix that makes the flow update leave_balances going forward.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $netByUser = LeaveLedger::whereIn('transaction_type', ['CREDIT_EARNED', 'CREDIT_EARNED_WOP', 'CREDIT_CORRECTION'])
            ->selectRaw('user_id, SUM(credit_vl - debit_vl) as net_vl, SUM(credit_sl - debit_sl) as net_sl, COUNT(*) as entry_count')
            ->groupBy('user_id')
            ->get();

        if ($netByUser->isEmpty()) {
            $this->info('No CREDIT_EARNED/CREDIT_EARNED_WOP/CREDIT_CORRECTION ledger entries found — nothing to backfill.');

            return self::SUCCESS;
        }

        $applied = 0;
        $skippedNoBalance = 0;
        $samples = [];

        $run = function () use ($netByUser, $dryRun, &$applied, &$skippedNoBalance, &$samples) {
            foreach ($netByUser as $row) {
                $balance = LeaveBalance::where('user_id', $row->user_id)->lockForUpdate()->first();

                if (! $balance) {
                    $skippedNoBalance++;

                    continue;
                }

                $oldVl = (float) $balance->VL;
                $oldSl = (float) $balance->SL;
                $newVl = round($oldVl + (float) $row->net_vl, 3);
                $newSl = round($oldSl + (float) $row->net_sl, 3);

                if (count($samples) < 10) {
                    $samples[] = sprintf(
                        'user_id=%d (%d entries): VL %.3f → %.3f, SL %.3f → %.3f',
                        $row->user_id,
                        $row->entry_count,
                        $oldVl,
                        $newVl,
                        $oldSl,
                        $newSl
                    );
                }

                if (! $dryRun) {
                    $balance->VL = $newVl;
                    $balance->SL = $newSl;
                    $balance->save();
                }

                $applied++;
            }
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        foreach ($samples as $sample) {
            $this->line($sample);
        }

        $this->newLine();
        $mode = $dryRun ? '[DRY RUN] Would credit' : 'Credited';
        $this->info("{$mode} {$applied} employee(s). Skipped (no leave_balances row): {$skippedNoBalance}.");

        return self::SUCCESS;
    }
}
