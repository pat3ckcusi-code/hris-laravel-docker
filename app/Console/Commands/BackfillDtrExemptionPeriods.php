<?php

namespace App\Console\Commands;

use App\Models\DtrExemptionPeriod;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time bridge command: creates a dtr_exemption_periods row for every
 * user still sitting in the pre-dtr_exemption_periods single-flag state
 * (dtr_exempt=true with an effective_date, no matching period row yet), so
 * no currently-active exemption is silently lost once the periods table
 * becomes the source of truth for per-date coverage. Idempotent - skips any
 * user who already has at least one period row (either because a period was
 * already backfilled, or because they've gone through DtrExemptionService
 * since this was deployed).
 */
class BackfillDtrExemptionPeriods extends Command
{
    protected $signature = 'dtr:backfill-exemption-periods {--dry-run : Preview the changes without writing them}';

    protected $description = 'Create a dtr_exemption_periods row for every user still in the old dtr_exempt single-flag state';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skippedNoEffectiveDate = 0;

        $run = function () use ($dryRun, &$created, &$skippedNoEffectiveDate) {
            User::where('dtr_exempt', true)
                ->whereDoesntHave('dtrExemptionPeriods')
                ->chunkById(200, function ($users) use ($dryRun, &$created, &$skippedNoEffectiveDate) {
                    foreach ($users as $user) {
                        if (! $user->dtr_exempt_effective_date) {
                            // Pre-2026-08-28 data has no effective_date at all - fall
                            // back to today so the period is at least well-formed;
                            // an indefinite exemption (no until_date) loses nothing
                            // by this, since it was never date-scoped to begin with.
                            $skippedNoEffectiveDate++;
                        }

                        $this->line(sprintf(
                            '%s #%d: effective %s, until %s, reason "%s"',
                            $dryRun ? '[DRY RUN] Would create' : 'Creating',
                            $user->id,
                            $user->dtr_exempt_effective_date?->toDateString() ?? now()->toDateString().' (fallback)',
                            $user->dtr_exempt_until_date?->toDateString() ?? 'indefinite',
                            $user->dtr_exempt_reason ?? ''
                        ));

                        if (! $dryRun) {
                            DtrExemptionPeriod::create([
                                'user_id' => $user->id,
                                'reason' => $user->dtr_exempt_reason ?? 'Backfilled from pre-existing exemption',
                                'effective_date' => $user->dtr_exempt_effective_date ?? now()->toDateString(),
                                'until_date' => $user->dtr_exempt_until_date,
                                'created_by' => null,
                            ]);
                        }

                        $created++;
                    }
                });
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        $this->newLine();
        $mode = $dryRun ? '[DRY RUN] Would create' : 'Created';
        $this->info("{$mode} {$created} dtr_exemption_periods row(s) ({$skippedNoEffectiveDate} had no effective_date and fell back to today).");

        return self::SUCCESS;
    }
}
