<?php

namespace App\Console\Commands;

use App\Models\LeaveDate;
use App\Models\LeaveLedger;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLeaveLedgerPeriodDates extends Command
{
    protected $signature = 'leave-ledger:backfill-period-dates {--dry-run : Preview the changes without writing them}';

    protected $description = 'Recompute transaction_date/period_end_date on existing LEAVE_USED/LEAVE_CANCELLED leave_ledger rows from their linked leave_requests/leave_dates, instead of the processing-time date they were originally written with';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $samples = [];

        $run = function () use ($dryRun, &$updated, &$unchanged, &$skipped, &$samples) {
            $this->backfillLeaveUsed($dryRun, $updated, $unchanged, $skipped, $samples);
            $this->backfillLeaveCancelled($dryRun, $updated, $unchanged, $skipped, $samples);
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        foreach (array_slice($samples, 0, 10) as $sample) {
            $this->line($sample);
        }

        $this->newLine();
        $mode = $dryRun ? '[DRY RUN] Would update' : 'Updated';
        $this->info("{$mode} {$updated} row(s). Already correct: {$unchanged}. Skipped (no linked leave request): {$skipped}.");

        return self::SUCCESS;
    }

    private function backfillLeaveUsed(bool $dryRun, int &$updated, int &$unchanged, int &$skipped, array &$samples): void
    {
        LeaveLedger::where('transaction_type', 'LEAVE_USED')
            ->whereNotNull('reference_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($dryRun, &$updated, &$unchanged, &$skipped, &$samples) {
                $requests = LeaveRequest::whereIn('id', $rows->pluck('reference_id')->unique())
                    ->get(['id', 'start_date', 'end_date'])
                    ->keyBy('id');

                foreach ($rows as $row) {
                    $leaveRequest = $requests->get($row->reference_id);

                    if (! $leaveRequest || ! $leaveRequest->start_date) {
                        $skipped++;

                        continue;
                    }

                    $this->applyIfChanged($row, $leaveRequest->start_date, $leaveRequest->end_date, $dryRun, $updated, $unchanged, $samples);
                }
            });
    }

    private function backfillLeaveCancelled(bool $dryRun, int &$updated, int &$unchanged, int &$skipped, array &$samples): void
    {
        $rows = LeaveLedger::where('transaction_type', 'LEAVE_CANCELLED')
            ->whereNotNull('reference_id')
            ->get();

        $referenceIds = $rows->pluck('reference_id')->unique();

        $requests = LeaveRequest::whereIn('id', $referenceIds)
            ->get(['id', 'start_date', 'end_date'])
            ->keyBy('id');

        $cancelledDatesByRequest = LeaveDate::whereIn('leave_request_id', $referenceIds)
            ->where('is_cancelled', true)
            ->get(['leave_request_id', 'leave_date'])
            ->groupBy('leave_request_id');

        foreach ($rows as $row) {
            $leaveRequest = $requests->get($row->reference_id);

            if (! $leaveRequest) {
                $skipped++;

                continue;
            }

            $cancelledDates = $cancelledDatesByRequest->get($row->reference_id);

            if ($cancelledDates && $cancelledDates->isNotEmpty()) {
                $start = $cancelledDates->min('leave_date');
                $end = $cancelledDates->max('leave_date');
            } else {
                // Older leave request with no per-date rows - fall back to its own range.
                $start = $leaveRequest->start_date;
                $end = $leaveRequest->end_date;
            }

            if (! $start) {
                $skipped++;

                continue;
            }

            $this->applyIfChanged($row, $start, $end, $dryRun, $updated, $unchanged, $samples);
        }
    }

    private function applyIfChanged(LeaveLedger $row, string $newStart, ?string $newEnd, bool $dryRun, int &$updated, int &$unchanged, array &$samples): void
    {
        $currentStart = $row->transaction_date?->toDateString();
        $currentEnd = $row->period_end_date?->toDateString();

        if ($currentStart === $newStart && $currentEnd === $newEnd) {
            $unchanged++;

            return;
        }

        if (count($samples) < 10) {
            $samples[] = sprintf(
                '#%d [%s] %s → %s (end: %s → %s)',
                $row->id,
                $row->transaction_type,
                $currentStart ?? 'null',
                $newStart,
                $currentEnd ?? 'null',
                $newEnd ?? 'null'
            );
        }

        if (! $dryRun) {
            LeaveLedger::whereKey($row->id)->update([
                'transaction_date' => $newStart,
                'period_end_date' => $newEnd,
            ]);
        }

        $updated++;
    }
}
