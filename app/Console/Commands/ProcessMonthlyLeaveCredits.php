<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\MonthlyAttendance;
use App\Models\User;
use App\Services\LeaveCreditComputationService;
use App\Services\LeaveLedgerService;
use App\Services\LwopAggregationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMonthlyLeaveCredits extends Command
{
    protected $signature = 'credit:process-monthly
                            {--year= : Year to process (default: last month\'s year)}
                            {--month= : Month to process (default: last month)}
                            {--user= : Process a single user ID only}
                            {--force : Re-process even if already processed}';

    protected $description = 'Compute and post monthly leave credits for all eligible employees';

    public function __construct(
        private LeaveCreditComputationService $creditService,
        private LeaveLedgerService $ledgerService,
        private LwopAggregationService $lwopService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lastMonth = Carbon::now()->subMonthNoOverflow();
        $year = (int) ($this->option('year') ?? $lastMonth->year);
        $month = (int) ($this->option('month') ?? $lastMonth->month);
        $force = (bool) $this->option('force');
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $this->info(sprintf('Processing leave credits for %04d-%02d', $year, $month));

        $result = $this->processBatch($year, $month, $userId, $force);

        if ($result['processed'] === 0 && $result['skipped'] === 0 && $result['failed'] === 0) {
            $this->warn('No eligible users found.');

            return self::SUCCESS;
        }

        foreach ($result['errors'] as $error) {
            $this->error("Failed user {$error['user_id']}: {$error['message']}");
        }

        $this->info("Done. Processed: {$result['processed']}, Skipped (already done): {$result['skipped']}, Failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Compute and post monthly leave credits for all eligible employees (or a single one).
     *
     * Each employee's attendance save + ledger write happen inside one DB transaction so a
     * failed ledger write can't leave `processed_at` committed with no corresponding credit
     * ever posted — that employee stays eligible for a retry instead of being silently and
     * permanently skipped.
     *
     * @return array{processed: int, skipped: int, failed: int, errors: array<int, array{user_id: int, message: string}>}
     */
    public function processBatch(int $year, int $month, ?int $userId, bool $force): array
    {
        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->eligibleUsersQuery($userId)->get() as $user) {
            try {
                $wasProcessed = DB::transaction(function () use ($user, $year, $month, $force) {
                    $computed = $this->computeCreditForUser($user, $year, $month, $force);

                    if ($computed['status'] === 'error') {
                        throw new \RuntimeException($computed['message']);
                    }

                    if ($computed['status'] === 'skip') {
                        return false;
                    }

                    $attendance = $computed['attendance'];
                    $attendance->computed_vl = $computed['vl_earned'];
                    $attendance->computed_sl = $computed['sl_earned'];
                    $attendance->processed_at = now();
                    $attendance->processed_by = null;
                    $attendance->save();

                    // Credit the real balance the employee actually files leave against --
                    // writeLedgerEntry() below only records the transaction, it never touches
                    // leave_balances itself (see LeaveLedgerService::writeLedgerEntry()).
                    $balance = LeaveBalance::where('user_id', $user->id)->lockForUpdate()->first();
                    if ($balance) {
                        $balance->VL = round((float) $balance->VL + $computed['vl_earned'], 3);
                        $balance->SL = round((float) $balance->SL + $computed['sl_earned'], 3);
                        $balance->save();
                    }

                    $this->ledgerService->writeLedgerEntry([
                        'user_id' => $user->id,
                        'transaction_date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
                        'transaction_type' => $computed['transaction_type'],
                        'leave_type' => 'VL+SL',
                        'days_present' => $attendance->days_present,
                        'abs_wop_days' => $attendance->abs_wop_days > 0 ? $attendance->abs_wop_days : null,
                        'credit_vl' => $computed['vl_earned'],
                        'credit_sl' => $computed['sl_earned'],
                        'debit_vl' => 0,
                        'debit_sl' => 0,
                        'is_system' => true,
                        'remarks' => $computed['remarks'],
                    ]);

                    return true;
                });

                $wasProcessed ? $processed++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['user_id' => $user->id, 'message' => $e->getMessage()];
                Log::error('ProcessMonthlyLeaveCredits failed for user', [
                    'user_id' => $user->id,
                    'year' => $year,
                    'month' => $month,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Preview what processBatch() would do for a year/month, without writing anything.
     * Shares the same eligibility query and computation as processBatch() (via
     * computeCreditForUser()), so the previewed VL/SL are guaranteed identical to what a
     * following processBatch() call would post, provided nothing in the underlying
     * attendance/leave data changes in between.
     *
     * Only 'would_process' and 'error' rows are listed individually -- already-processed
     * employees are counted (would_skip) but not returned as rows, since this command only
     * ever creates new MonthlyAttendance rows, never touches existing ones.
     *
     * @return array{summary: array{would_process: int, would_skip: int, would_fail: int}, rows: array}
     */
    public function previewBatch(int $year, int $month, ?int $userId = null): array
    {
        $wouldProcess = 0;
        $wouldSkip = 0;
        $wouldFail = 0;
        $rows = [];

        foreach ($this->eligibleUsersQuery($userId)->get() as $user) {
            $computed = $this->computeCreditForUser($user, $year, $month, false);

            if ($computed['status'] === 'error') {
                $wouldFail++;
                $rows[] = ['user_id' => $user->id, 'status' => 'error', 'message' => $computed['message']];

                continue;
            }

            if ($computed['status'] === 'skip') {
                $wouldSkip++;

                continue;
            }

            $wouldProcess++;
            $rows[] = [
                'user_id' => $user->id,
                'status' => 'would_process',
                'abs_wop_days' => $computed['attendance']->abs_wop_days,
                'vl_earned' => $computed['vl_earned'],
                'sl_earned' => $computed['sl_earned'],
                'transaction_type' => $computed['transaction_type'],
            ];
        }

        return [
            'summary' => ['would_process' => $wouldProcess, 'would_skip' => $wouldSkip, 'would_fail' => $wouldFail],
            'rows' => $rows,
        ];
    }

    private function eligibleUsersQuery(?int $userId)
    {
        $query = User::active()
            ->whereIn('employee_type', User::LEAVE_ELIGIBLE_TYPES)
            ->whereHas('leaveBalance');

        if ($userId) {
            $query->where('id', $userId);
        }

        return $query;
    }

    /**
     * Pure computation for one employee's month -- no DB writes, no transaction. Shared by
     * processBatch() (which wraps this in a transaction and persists the result) and
     * previewBatch() (which just reads the result).
     *
     * @return array{status: 'would_process'|'skip'|'error', attendance?: MonthlyAttendance, vl_earned?: float, sl_earned?: float, transaction_type?: string, remarks?: string|null, message?: string}
     */
    private function computeCreditForUser(User $user, int $year, int $month, bool $force): array
    {
        try {
            $attendance = MonthlyAttendance::firstOrNew([
                'user_id' => $user->id,
                'year' => $year,
                'month' => $month,
            ]);

            if ($attendance->processed_at !== null && ! $force) {
                return ['status' => 'skip', 'attendance' => $attendance];
            }

            $aggregate = $this->lwopService->computeForMonth($user, $year, $month);
            $attendance->days_present = $aggregate['days_present'];
            $attendance->abs_wop_days = $aggregate['abs_wop_days'];

            $result = $this->creditService->computeMonthlyCredit($user, $attendance);

            $remarks = $result['remarks'];
            if ($aggregate['awol_days'] > 0) {
                // AWOL doesn't reduce credit (see LwopAggregationService::computeForMonth()) --
                // this is purely a visibility note for HR, since AWOL is still a serious
                // disciplinary matter even though it no longer docks leave credit.
                $awolNote = "{$aggregate['awol_days']} AWOL day(s) detected.";
                $remarks = $remarks ? "{$remarks} {$awolNote}" : $awolNote;
            }

            return [
                'status' => 'would_process',
                'attendance' => $attendance,
                'vl_earned' => $result['vl_earned'],
                'sl_earned' => $result['sl_earned'],
                'transaction_type' => $result['transaction_type'],
                'remarks' => $remarks,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
