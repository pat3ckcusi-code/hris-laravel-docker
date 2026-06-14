<?php

namespace App\Console\Commands;

use App\Models\MonthlyAttendance;
use App\Models\User;
use App\Services\LeaveCreditComputationService;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lastMonth = Carbon::now()->subMonthNoOverflow();
        $year = (int) ($this->option('year') ?? $lastMonth->year);
        $month = (int) ($this->option('month') ?? $lastMonth->month);
        $force = (bool) $this->option('force');

        $this->info(sprintf('Processing leave credits for %04d-%02d', $year, $month));

        $query = User::query()
            ->where('Status', 'Active')
            ->whereIn('employee_type', [...User::LEAVE_ELIGIBLE_TYPES, 'part_time'])
            ->whereHas('leaveBalance');

        if ($this->option('user')) {
            $query->where('id', (int) $this->option('user'));
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn('No eligible users found.');

            return self::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $attendance = MonthlyAttendance::firstOrNew([
                    'user_id' => $user->id,
                    'year' => $year,
                    'month' => $month,
                ]);

                if ($attendance->processed_at !== null && ! $force) {
                    $skipped++;

                    continue;
                }

                $result = $this->creditService->computeMonthlyCredit($user, $attendance);

                $attendance->computed_vl = $result['vl_earned'];
                $attendance->computed_sl = $result['sl_earned'];
                $attendance->processed_at = now();
                $attendance->processed_by = null;
                $attendance->save();

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
                    'remarks' => $result['remarks'],
                ]);

                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('ProcessMonthlyLeaveCredits failed for user', [
                    'user_id' => $user->id,
                    'year' => $year,
                    'month' => $month,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed user {$user->id}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Processed: {$processed}, Skipped (already done): {$skipped}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
