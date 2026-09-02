<?php

namespace App\Services;

use App\Models\DtrExemptionPeriod;
use App\Models\HRAuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sole write path for dtr_exemption_periods - the DTR/biometric exemption
 * history table behind users.dtr_exempt*, which is now a pure cache of
 * "is an exemption active today" (see syncCache()), mirroring how
 * users.shift_id caches ShiftAssignment history via ShiftAssignmentService.
 *
 * Unlike JobOrderAppointment (always a fixed-end contract), an exemption's
 * until_date is nullable/open-ended, closer to ShiftAssignment.effective_until -
 * but exemption has no ShiftAssignment-style day-of-week concurrent-split
 * concept, so overlapping periods are simply rejected (mirroring
 * JobOrderAppointmentService's "reject, don't truncate" convention) rather
 * than truncated.
 *
 * create() is also what makes a backdated/historical window actually take
 * effect: it triggers a targeted PersonnelLogImportService recompute over
 * the period's own (already-elapsed) span, so any dtrs rows already sitting
 * in that window get cleared - see PersonnelLogImportService::
 * upsertDtrRecords()'s per-date exemption check.
 */
class DtrExemptionService
{
    public function __construct(private readonly PersonnelLogImportService $importService) {}

    public function create(User $user, ?User $actor, string $reason, string $effectiveDate, ?string $untilDate): DtrExemptionPeriod
    {
        $this->assertValidRange($effectiveDate, $untilDate);
        $this->assertNoOverlap($user->id, $effectiveDate, $untilDate);

        $period = DB::transaction(function () use ($user, $actor, $reason, $effectiveDate, $untilDate) {
            $period = DtrExemptionPeriod::create([
                'user_id' => $user->id,
                'reason' => $reason,
                'effective_date' => $effectiveDate,
                'until_date' => $untilDate,
                'created_by' => $actor?->id,
            ]);

            $this->syncCache($user);

            return $period;
        });

        // Clear whatever dtrs rows already exist in the (possibly fully past)
        // portion of this window - the recompute loop skips any date this new
        // period covers, so it never re-produces them. Skipped entirely for a
        // future-dated period (nothing imported there yet to clear), and
        // never reaches past today either way.
        if ($effectiveDate <= today()->toDateString()) {
            $recomputeTo = $untilDate !== null && $untilDate < today()->toDateString()
                ? $untilDate
                : today()->toDateString();
            $this->importService->recomputeDtr($user, $effectiveDate, $recomputeTo);
        }

        $this->logToggled($actor, $user, true, $reason, $effectiveDate, $untilDate);

        return $period;
    }

    /**
     * Closes the currently-active period (yesterday becomes its last covered
     * day, so the restore takes effect immediately today - until_date is
     * inclusive, so setting it to today would leave the period still
     * covering today) and re-syncs the cache to false. A period whose
     * effective_date is today (created and restored the same day) is
     * deleted outright instead - a same-day correction has no history value
     * as an inverted range, mirroring ShiftAssignmentService's identical
     * same-effective_from convention. Mirrors toggleExempt()'s previous
     * manual "Restore to DTR" behavior.
     */
    public function restore(User $user, ?User $actor): void
    {
        DB::transaction(function () use ($user) {
            $active = DtrExemptionPeriod::where('user_id', $user->id)
                ->coveringDate(today()->toDateString())
                ->first();

            if ($active !== null) {
                if ($active->effective_date->toDateString() === today()->toDateString()) {
                    $active->delete();
                } else {
                    $active->update(['until_date' => today()->subDay()->toDateString()]);
                }
            }

            $this->syncCache($user);
        });

        $this->logToggled($actor, $user, false, null, null, null);
    }

    /**
     * Refresh users.dtr_exempt* to whatever period (if any) covers today,
     * for one user - called after every write above. The bulk daily sync
     * (dtr:sync-exemption-cache) duplicates this same "covering today, pick
     * one" logic per user in a batched loop rather than calling this in a
     * per-row transaction, matching SyncShiftAssignmentCache's own
     * batch-over-DRY tradeoff.
     */
    public function syncCache(User $user): void
    {
        $active = DtrExemptionPeriod::where('user_id', $user->id)
            ->coveringDate(today()->toDateString())
            ->latest('id')
            ->first();

        $user->update([
            'dtr_exempt' => $active !== null,
            'shift_id' => $active !== null ? null : $user->shift_id,
            'dtr_exempt_reason' => $active?->reason,
            'dtr_exempt_effective_date' => $active?->effective_date,
            'dtr_exempt_until_date' => $active?->until_date,
        ]);
    }

    private function assertValidRange(string $effectiveDate, ?string $untilDate): void
    {
        if ($untilDate !== null && $untilDate < $effectiveDate) {
            throw ValidationException::withMessages([
                'until_date' => 'The until date field must be a date after or equal to Effective Date.',
            ]);
        }
    }

    private function assertNoOverlap(int $userId, string $effectiveDate, ?string $untilDate): void
    {
        $overlap = DtrExemptionPeriod::where('user_id', $userId)
            ->where('effective_date', '<=', $untilDate ?? '9999-12-31')
            ->where(function ($q) use ($effectiveDate) {
                $q->whereNull('until_date')->orWhere('until_date', '>=', $effectiveDate);
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_date' => 'This period overlaps an existing DTR exemption for this employee. Restore the existing exemption first.',
            ]);
        }
    }

    private function logToggled(?User $actor, User $employee, bool $exempt, ?string $reason, ?string $effectiveDate, ?string $untilDate): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor?->id,
                'module' => 'shift_management',
                'action' => 'dtr_exemption_toggled',
                'target_type' => 'user',
                'target_id' => $employee->id,
                'details' => ['exempt' => $exempt, 'reason' => $reason, 'effective_date' => $effectiveDate, 'until_date' => $untilDate],
            ]);
        } catch (\Exception) {
            // audit failure must not block the toggle
        }
    }
}
