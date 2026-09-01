<?php

namespace App\Console\Commands;

use App\Models\HRAuditTrail;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Restores any employee whose DTR/biometric exemption carries an optional
 * dtr_exempt_until_date that has passed, back onto normal DTR tracking -
 * mirroring DeactivateExpiredJobOrders' auto-expiry pattern. An exemption
 * with no until_date (the default) is indefinite and never touched here;
 * restoration is otherwise identical to EmployeeScheduleController::
 * toggleExempt()'s manual "Restore to DTR" path (same fields cleared, same
 * dtr_exemption_toggled hr_audit_trails action), just system-triggered with
 * a null actor_user_id, the same convention attendance:auto-import's own
 * scheduled runs use.
 */
class RestoreExpiredDtrExemptions extends Command
{
    protected $signature = 'dtr:restore-expired-exemptions';

    protected $description = 'Restore DTR/biometric exemption for employees whose optional exemption end date has passed';

    public function handle(): int
    {
        $today = now()->toDateString();
        $restored = 0;

        User::where('dtr_exempt', true)
            ->whereNotNull('dtr_exempt_until_date')
            ->where('dtr_exempt_until_date', '<', $today)
            ->chunkById(200, function ($users) use (&$restored) {
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

                    $restored++;
                }
            });

        $this->info("Restored {$restored} employee(s) whose DTR exemption had expired.");

        return self::SUCCESS;
    }
}
