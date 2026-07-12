<?php

namespace App\Services;

use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes to the shift_assignments history table, keeping it non-overlapping:
 * creating a new assignment truncates any existing row(s) it overlaps rather
 * than deleting them (deleting only when the truncated row started on the
 * exact same date, since that's a same-day correction, not real history).
 * No row is ever resurrected once its window closes - once an assignment's
 * effective_until passes with nothing else scheduled, the employee simply
 * has no covering row, and WorkSchedule falls back to Standard Day (the
 * global default), the same as an employee who was never assigned a shift.
 * Also keeps users.shift_id (a denormalized "today" cache read by
 * WorkSchedule::forUser() and most of the UI) in sync on every write.
 */
class ShiftAssignmentService
{
    public function assign(User $user, ?int $shiftId, Carbon $from, ?Carbon $until, ?int $createdBy, ?array $daysOfWeek = null): ShiftAssignment
    {
        // Normalize here (not just in the model's cast) so overlaps()'s
        // comparison below is guaranteed to work regardless of whether the
        // caller passed request-sourced strings or literal ints.
        if ($daysOfWeek !== null) {
            $daysOfWeek = array_values(array_unique(array_map('intval', $daysOfWeek)));
        }

        return DB::transaction(function () use ($user, $shiftId, $from, $until, $createdBy, $daysOfWeek) {
            $existing = ShiftAssignment::forUser($user->id)->lockForUpdate()->get();

            foreach ($existing as $row) {
                if (! $this->overlaps($row, $from, $until, $daysOfWeek)) {
                    continue;
                }

                if ($row->effective_from->equalTo($from)) {
                    // Same start date being re-specified: this is a correction,
                    // not a new historical fact, so replace it outright rather
                    // than leave a same-day row behind.
                    $row->delete();
                } else {
                    $row->update(['effective_until' => $from->copy()->subDay()]);
                }
            }

            $assignment = ShiftAssignment::create([
                'user_id' => $user->id,
                'shift_id' => $shiftId,
                'days_of_week' => $daysOfWeek,
                'effective_from' => $from,
                'effective_until' => $until,
                'created_by' => $createdBy,
            ]);

            $this->syncCache($user);

            return $assignment;
        });
    }

    /**
     * Refresh users.shift_id to whatever assignment applies today, per $user.
     * Skips DTR-exempt employees: exemption clears shift_id independently of
     * this history table (EmployeeScheduleController::toggleExempt()), and
     * should not be overwritten by a stale scheduled assignment.
     */
    public function syncCache(User $user): void
    {
        if ($user->dtr_exempt) {
            return;
        }

        $today = Carbon::today();
        $applicable = ShiftAssignment::forUser($user->id)->effectiveOn($today)->get()
            ->first(fn (ShiftAssignment $row) => $row->appliesOnDate($today));

        $user->update(['shift_id' => $applicable?->shift_id]);
    }

    /**
     * Two rows only conflict (and trigger truncation) when their date ranges
     * overlap AND their day-of-week sets intersect - null on either side means
     * "every day," which always conflicts. This is what lets an MWF assignment
     * and a TTH assignment coexist for the same employee over the same
     * open-ended period without truncating each other.
     */
    private function overlaps(ShiftAssignment $row, Carbon $from, ?Carbon $until, ?array $daysOfWeek): bool
    {
        if ($row->effective_until !== null && $row->effective_until->lt($from)) {
            return false;
        }

        if ($until !== null && $row->effective_from->gt($until)) {
            return false;
        }

        if ($daysOfWeek === null || $row->days_of_week === null) {
            return true;
        }

        return count(array_intersect($daysOfWeek, $row->days_of_week)) > 0;
    }
}
