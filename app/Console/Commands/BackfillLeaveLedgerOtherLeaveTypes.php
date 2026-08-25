<?php

namespace App\Console\Commands;

use App\Models\LeaveLedger;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLeaveLedgerOtherLeaveTypes extends Command
{
    protected $signature = 'leave-ledger:backfill-other-leave-types {--dry-run : Preview the changes without writing them}';

    protected $description = 'Backfill WLNS/SPL/CTO/SP debit/credit amounts (added after the columns did not exist yet) on existing LEAVE_USED/LEAVE_CANCELLED leave_ledger rows from their linked leave_requests.printing_deduction_details, and correct the leave_type label to list every type actually involved';

    private const OTHER_TYPES = ['WLNS', 'SPL', 'CTO', 'SP'];

    private const EPSILON = 0.001;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $unchanged = 0;
        $skippedNoSource = 0;
        $skippedPartialMismatch = [];
        $samples = [];

        $run = function () use ($dryRun, &$updated, &$unchanged, &$skippedNoSource, &$skippedPartialMismatch, &$samples) {
            $this->backfillLeaveUsed($dryRun, $updated, $unchanged, $skippedNoSource, $samples);
            $this->backfillLeaveCancelled($dryRun, $updated, $unchanged, $skippedNoSource, $skippedPartialMismatch, $samples);
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        foreach (array_slice($samples, 0, 15) as $sample) {
            $this->line($sample);
        }

        if (! empty($skippedPartialMismatch)) {
            $this->newLine();
            $this->warn('Skipped LEAVE_CANCELLED rows whose credit_vl/credit_sl does not match the full printing_deduction_details (likely a partial-date cancellation/reschedule — cannot be safely reconstructed, review manually):');
            foreach (array_slice($skippedPartialMismatch, 0, 30) as $id) {
                $this->line("  leave_ledger.id={$id}");
            }
            if (count($skippedPartialMismatch) > 30) {
                $this->line('  ... and '.(count($skippedPartialMismatch) - 30).' more.');
            }
        }

        $this->newLine();
        $mode = $dryRun ? '[DRY RUN] Would update' : 'Updated';
        $this->info("{$mode} {$updated} row(s). Already correct: {$unchanged}. No linked leave request / no printing_deduction_details: {$skippedNoSource}. Skipped as unreconstructable partial: ".count($skippedPartialMismatch).'.');

        return self::SUCCESS;
    }

    private function backfillLeaveUsed(bool $dryRun, int &$updated, int &$unchanged, int &$skippedNoSource, array &$samples): void
    {
        LeaveLedger::where('transaction_type', 'LEAVE_USED')
            ->where('reference_type', 'leave_request')
            ->whereNotNull('reference_id')
            ->where('debit_wlns', 0)
            ->where('debit_spl', 0)
            ->where('debit_cto', 0)
            ->where('debit_sp', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($dryRun, &$updated, &$unchanged, &$skippedNoSource, &$samples) {
                $requests = LeaveRequest::whereIn('id', $rows->pluck('reference_id')->unique())
                    ->get(['id', 'printing_deduction_details'])
                    ->keyBy('id');

                foreach ($rows as $row) {
                    $leaveRequest = $requests->get($row->reference_id);
                    $deductionLog = $this->decodeDeductionLog($leaveRequest?->printing_deduction_details);

                    if ($deductionLog === null) {
                        $skippedNoSource++;

                        continue;
                    }

                    // LEAVE_USED's printing_deduction_details is always the exact, complete
                    // amount deducted for that leave request -- no partial-subset ambiguity.
                    $this->applyIfChanged($row, $deductionLog, $dryRun, $updated, $unchanged, $samples);
                }
            });
    }

    private function backfillLeaveCancelled(bool $dryRun, int &$updated, int &$unchanged, int &$skippedNoSource, array &$skippedPartialMismatch, array &$samples): void
    {
        LeaveLedger::where('transaction_type', 'LEAVE_CANCELLED')
            ->where('reference_type', 'leave_request')
            ->whereNotNull('reference_id')
            ->where('debit_wlns', 0)
            ->where('debit_spl', 0)
            ->where('debit_cto', 0)
            ->where('debit_sp', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($dryRun, &$updated, &$unchanged, &$skippedNoSource, &$skippedPartialMismatch, &$samples) {
                $requests = LeaveRequest::whereIn('id', $rows->pluck('reference_id')->unique())
                    ->get(['id', 'printing_deduction_details'])
                    ->keyBy('id');

                foreach ($rows as $row) {
                    $leaveRequest = $requests->get($row->reference_id);
                    $deductionLog = $this->decodeDeductionLog($leaveRequest?->printing_deduction_details);

                    if ($deductionLog === null) {
                        $skippedNoSource++;

                        continue;
                    }

                    // A LEAVE_CANCELLED row can be a whole-request cancellation (its
                    // credit_vl/credit_sl == the full printing_deduction_details, safe to
                    // trust the other keys too) or a partial-date cancellation/reschedule
                    // (its credit_vl/credit_sl covers only a subset of dates -- the other
                    // keys in printing_deduction_details would overstate what this specific
                    // row actually refunded). Verify against the row's own already-recorded
                    // VL/SL amounts rather than trusting a remarks string, since the
                    // reschedule call site can produce either shape under the same remarks.
                    $recordedVl = (float) $row->credit_vl;
                    $recordedSl = (float) $row->credit_sl;
                    $sourceVl = (float) ($deductionLog['VL'] ?? 0);
                    $sourceSl = (float) ($deductionLog['SL'] ?? 0);

                    if (abs($recordedVl - $sourceVl) > self::EPSILON || abs($recordedSl - $sourceSl) > self::EPSILON) {
                        $skippedPartialMismatch[] = $row->id;

                        continue;
                    }

                    $this->applyIfChanged($row, $deductionLog, $dryRun, $updated, $unchanged, $samples);
                }
            });
    }

    /**
     * @return array<string, float>|null
     */
    private function decodeDeductionLog(?string $json): ?array
    {
        if (! $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && ! empty($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, float>  $deductionLog
     */
    private function applyIfChanged(LeaveLedger $row, array $deductionLog, bool $dryRun, int &$updated, int &$unchanged, array &$samples): void
    {
        $newValues = [];
        foreach (self::OTHER_TYPES as $type) {
            $newValues['debit_'.strtolower($type)] = $row->transaction_type === 'LEAVE_USED' ? (float) ($deductionLog[$type] ?? 0) : 0;
            $newValues['credit_'.strtolower($type)] = $row->transaction_type === 'LEAVE_CANCELLED' ? (float) ($deductionLog[$type] ?? 0) : 0;
        }
        $newLeaveType = implode('+', array_keys($deductionLog));

        $otherAmountsChanged = false;
        foreach ($newValues as $column => $value) {
            if (abs((float) $row->{$column} - $value) > self::EPSILON) {
                $otherAmountsChanged = true;
                break;
            }
        }
        $leaveTypeChanged = $row->leave_type !== $newLeaveType;

        if (! $otherAmountsChanged && ! $leaveTypeChanged) {
            $unchanged++;

            return;
        }

        if (count($samples) < 15) {
            $samples[] = sprintf(
                '#%d [%s] leave_type %s → %s | other-type amounts: %s',
                $row->id,
                $row->transaction_type,
                $row->leave_type,
                $newLeaveType,
                json_encode(array_filter($newValues))
            );
        }

        if (! $dryRun) {
            LeaveLedger::whereKey($row->id)->update($newValues + ['leave_type' => $newLeaveType]);
        }

        $updated++;
    }
}
