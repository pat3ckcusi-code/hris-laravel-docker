<?php

namespace App\Console\Commands;

use App\Models\HRAuditTrail;
use App\Models\User;
use App\Services\DtrExemptionService;
use Illuminate\Console\Command;

/**
 * Daily sync of users.dtr_exempt* to whichever dtr_exemption_periods row (if
 * any) covers today, mirroring shift:sync-cache's users.shift_id sync from
 * ShiftAssignment history. Subsumes the command's original narrower purpose
 * (restore once an until_date passes) - deriving "today's" state fresh from
 * history each run also makes a future-dated effective_date activate on its
 * own, with no separate "start" job needed.
 *
 * Kept under its original signature/class name (this command predates
 * dtr_exemption_periods) even though its role has broadened, so the existing
 * scheduler entry and command-name references don't need to change.
 *
 * Legacy fallback: a user with dtr_exempt=true but no dtr_exemption_periods
 * row at all (pre-migration data that hasn't gone through
 * dtr:backfill-exemption-periods yet, or - since a manual DB write is the
 * only way this can happen post-migration - test/seed data built directly
 * against the old single-flag shape) is handled the ORIGINAL way: restored
 * only once an explicit dtr_exempt_until_date has passed, exactly as this
 * command always did before dtr_exemption_periods existed. An indefinite
 * (until_date null) or not-yet-expired legacy exemption is left untouched,
 * same as before.
 */
class RestoreExpiredDtrExemptions extends Command
{
    protected $signature = 'dtr:restore-expired-exemptions';

    protected $description = 'Sync users.dtr_exempt* to whichever DTR exemption period covers today';

    public function handle(DtrExemptionService $dtrExemptionService): int
    {
        $today = now()->toDateString();
        $affected = 0;

        User::whereHas('dtrExemptionPeriods')->chunkById(200, function ($users) use (&$affected, $dtrExemptionService) {
            foreach ($users as $user) {
                $before = $user->dtr_exempt;
                $dtrExemptionService->syncCache($user);

                if ($user->dtr_exempt !== $before) {
                    $affected++;

                    HRAuditTrail::create([
                        'actor_user_id' => null,
                        'module' => 'shift_management',
                        'action' => 'dtr_exemption_toggled',
                        'target_type' => 'user',
                        'target_id' => $user->id,
                        'details' => [
                            'exempt' => $user->dtr_exempt,
                            'reason' => $user->dtr_exempt_reason,
                            'effective_date' => $user->dtr_exempt_effective_date?->toDateString(),
                            'until_date' => $user->dtr_exempt_until_date?->toDateString(),
                            'auto_expired' => ! $user->dtr_exempt,
                        ],
                    ]);
                }
            }
        });

        User::where('dtr_exempt', true)
            ->whereDoesntHave('dtrExemptionPeriods')
            ->whereNotNull('dtr_exempt_until_date')
            ->where('dtr_exempt_until_date', '<', $today)
            ->chunkById(200, function ($users) use (&$affected) {
                foreach ($users as $user) {
                    $user->update([
                        'dtr_exempt' => false,
                        'dtr_exempt_reason' => null,
                        'dtr_exempt_effective_date' => null,
                        'dtr_exempt_until_date' => null,
                    ]);

                    HRAuditTrail::create([
                        'actor_user_id' => null,
                        'module' => 'shift_management',
                        'action' => 'dtr_exemption_toggled',
                        'target_type' => 'user',
                        'target_id' => $user->id,
                        'details' => [
                            'exempt' => false,
                            'reason' => null,
                            'effective_date' => null,
                            'until_date' => null,
                            'auto_expired' => true,
                        ],
                    ]);

                    $affected++;
                }
            });

        $this->info("Synced DTR exemption cache for {$affected} employee(s).");

        return self::SUCCESS;
    }
}
