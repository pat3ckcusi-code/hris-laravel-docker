<?php

namespace App\Console\Commands;

use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Flips users.shift_id forward/back to whatever shift_assignments row covers
 * today, so a future-scheduled assignment's start date - or an expiring
 * assignment falling back to Standard Day (null) once nothing covers the
 * date anymore - takes effect without anyone manually re-saving the
 * employee's shift on that day.
 */
class SyncShiftAssignmentCache extends Command
{
    protected $signature = 'shift:sync-cache';

    protected $description = "Sync users.shift_id to whichever shift_assignments row covers today's date";

    public function handle(): int
    {
        $updated = 0;
        $now = now();

        User::whereHas('shiftAssignments')->chunkById(200, function ($users) use (&$updated, $now) {
            $today = ShiftAssignment::whereIn('user_id', $users->pluck('id'))
                ->effectiveOn($now)
                ->get()
                ->groupBy('user_id');

            foreach ($users as $user) {
                if ($user->dtr_exempt) {
                    continue;
                }

                $applicable = $today->get($user->id, collect())
                    ->first(fn (ShiftAssignment $row) => $row->appliesOnDate($now));
                $shiftId = $applicable?->shift_id;

                if ($user->shift_id !== $shiftId) {
                    $user->update(['shift_id' => $shiftId]);
                    $updated++;
                }
            }
        });

        $this->info("Synced shift assignment cache for {$updated} user(s).");

        return self::SUCCESS;
    }
}
