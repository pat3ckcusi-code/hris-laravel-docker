<?php

namespace App\Services;

use App\Models\EmployeeShiftSchedule;
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
 *
 * Truncating or replacing a row also deletes any is_rotation_generated
 * EmployeeShiftSchedule rows that fall outside its new (possibly nonexistent)
 * coverage - otherwise a rotation's own rest-day markers, written for the
 * whole rotation up front, keep dictating "Rest Day" past the point where a
 * newer assignment (e.g. from Remove, or a plain re-assignment) takes over,
 * leaving a stale half-cancelled-looking rotation instead of a clean handoff.
 */
class ShiftAssignmentService
{
    public function assign(User $user, ?int $shiftId, Carbon $from, ?Carbon $until, ?int $createdBy, ?array $daysOfWeek = null, ?array $workDays = null, bool $noBreak = false): ShiftAssignment
    {
        // Normalize here (not just in the model's cast) so overlaps()'s
        // comparison below is guaranteed to work regardless of whether the
        // caller passed request-sourced strings or literal ints.
        if ($daysOfWeek !== null) {
            $daysOfWeek = array_values(array_unique(array_map('intval', $daysOfWeek)));
        }
        if ($workDays !== null) {
            $workDays = array_values(array_unique(array_map('intval', $workDays)));
        }

        // When days_of_week restricts which dates this row even governs,
        // work_days must match exactly - a day this row doesn't govern can
        // never be "worked" under it regardless of what was submitted, and a
        // day it does govern but work_days excludes silently became an
        // unexplained rest day even though a broader Work Days selection
        // said otherwise. Forcing equality here removes that trap at the
        // single write path rather than relying on UI/validation to catch it.
        if ($daysOfWeek !== null) {
            $workDays = $daysOfWeek;
        }

        return DB::transaction(function () use ($user, $shiftId, $from, $until, $createdBy, $daysOfWeek, $workDays, $noBreak) {
            $existing = ShiftAssignment::forUser($user->id)->lockForUpdate()->get();

            foreach ($existing as $row) {
                if (! $this->overlaps($row, $from, $until, $daysOfWeek)) {
                    continue;
                }

                if ($row->effective_from->equalTo($from)) {
                    // Same start date being re-specified: this is a correction,
                    // not a new historical fact, so replace it outright rather
                    // than leave a same-day row behind. Its entire original
                    // range no longer has a row backing it, so clean up any
                    // rotation-generated overrides across the whole thing.
                    $this->deleteStaleRotationOverrides($user, $row->effective_from, $row->effective_until);
                    $row->delete();
                } else {
                    $newUntil = $from->copy()->subDay();
                    $this->deleteStaleRotationOverrides($user, $from, $row->effective_until);
                    $row->update(['effective_until' => $newUntil]);
                }
            }

            $assignment = ShiftAssignment::create([
                'user_id' => $user->id,
                'shift_id' => $shiftId,
                'days_of_week' => $daysOfWeek,
                'work_days' => $workDays,
                'no_break' => $noBreak,
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
     * Deletes is_rotation_generated EmployeeShiftSchedule rows for dates
     * between $from and $until (inclusive; $until null means unbounded) -
     * the gap a rotation-generated assignment no longer covers after being
     * truncated or replaced. Overrides that aren't rotation-generated (a
     * deliberate manual entry on the Shift Schedule page) are never touched.
     */
    private function deleteStaleRotationOverrides(User $user, Carbon $from, ?Carbon $until): void
    {
        EmployeeShiftSchedule::where('user_id', $user->id)
            ->where('is_rotation_generated', true)
            ->where('date', '>=', $from)
            ->when($until !== null, fn ($q) => $q->where('date', '<=', $until))
            ->delete();
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
