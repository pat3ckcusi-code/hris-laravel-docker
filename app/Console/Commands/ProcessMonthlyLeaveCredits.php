<?php

namespace App\Console\Commands;

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
        $query = User::active()
            ->whereIn('employee_type', User::LEAVE_ELIGIBLE_TYPES)
            ->whereHas('leaveBalance');

        if ($userId) {
            $query->where('id', $userId);
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($query->get() as $user) {
            try {
                $wasProcessed = DB::transaction(function () use ($user, $year, $month, $force) {
                    $attendance = MonthlyAttendance::firstOrNew([
                        'user_id' => $user->id,
                        'year' => $year,
                        'month' => $month,
                    ]);

                    if ($attendance->processed_at !== null && ! $force) {
                        return false;
                    }

                    $aggregate = $this->lwopService->computeForMonth($user, $year, $month);
                    $attendance->days_present = $aggregate['days_present'];
                    $attendance->abs_wop_days = $aggregate['abs_wop_days'];

                    $result = $this->creditService->computeMonthlyCredit($user, $attendance);

                    $attendance->computed_vl = $result['vl_earned'];
                    $attendance->computed_sl = $result['sl_earned'];
                    $attendance->processed_at = now();
                    $attendance->processed_by = null;
                    $attendance->save();

                    $remarks = $result['remarks'];
                    if ($aggregate['awol_days'] > 0) {
                        // AWOL and LWOP reduce credit the same way, but carry very different
                        // disciplinary implications -- call it out separately for HR.
                        $awolNote = "{$aggregate['awol_days']} AWOL day(s) detected.";
                        $remarks = $remarks ? "{$remarks} {$awolNote}" : $awolNote;
                    }

                    $this->ledgerService->writeLedgerEntry([
                        'user_id' => $user->id,
                        'transaction_date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
                        'transaction_type' => $result['transaction_type'],
                        'leave_type' => 'VL+SL',
                        'days_present' => $attendance->days_present,
                        'abs_wop_days' => $attendance->abs_wop_days > 0 ? $attendance->abs_wop_days : null,
                        'credit_vl' => $result['vl_earned'],
                        'credit_sl' => $result['sl_earned'],
                        'debit_vl' => 0,
                        'debit_sl' => 0,
                        'is_system' => true,
                        'remarks' => $remarks,
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
}
