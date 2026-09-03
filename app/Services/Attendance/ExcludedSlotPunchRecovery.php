<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\EmployeeShiftSchedule;
use App\Models\User;
use App\Services\DtrPunchResolver;
use App\Services\ShiftPunchGrouper;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Recovers a real biometric punch that a DtrExcuse or WorkSuspension's
 * unconditional slot exclusion swallowed before it ever reached the display
 * layer - display-only, never writes to `dtrs`.
 *
 * When a slot is excluded (DtrExcuse::excludedSlotKeys() / WorkSchedule::
 * applySuspension()'s returned slots), AttendanceMatcher builds no expected
 * event for it, so a real punch that would have landed there instead falls
 * into the unmatched pool (dtrs.unmatched_logs) or, on a Standard Day
 * schedule, can get claimed by the independent overtime-matching path
 * (dtrs.time_in_ot/time_out_ot) instead - either way it never reaches
 * dtrs.time_in_am/time_out_am/time_in_pm/time_out_pm, so the existing
 * "$dtr->time_in_am ?: 'EXCUSED'"-style display fallback can never show it.
 *
 * The fix re-runs DtrPunchResolver::resolve() on the same day's punches
 * WITHOUT the exclusion, then only accepts an excluded slot's value from
 * that unconstrained re-resolve if the exact same 'H:i:s' string also
 * appears in the REAL (persisted) resolution's leftover pool (unmatched_logs
 * + time_in_ot/time_out_ot). A naive "sequentially fill excluded slots from
 * unmatched_logs" is unsafe: AttendanceMatcher::align() is a global,
 * order-preserving DP over the whole punch list vs. the whole event list,
 * not a per-slot independent decision - removing an excluded slot's event
 * can change how an adjacent, non-excluded slot resolves too (overlapping
 * eligibility windows). If a punch isn't in the leftover pool, the real
 * resolution already placed it somewhere real (a non-excluded slot, or a
 * genuine OT pair) - recovering it a second time would display the same
 * physical punch twice. This also makes over-recovery structurally
 * impossible with no extra capping logic: a genuinely unrelated stray scan
 * that the real resolution already absorbed elsewhere simply won't reappear
 * in the pool.
 *
 * Locator's exclusion is windowed (only excludes a punch actually falling
 * inside the locator's own travel window), not unconditional, and already
 * has its own correct recovery via Form48ExportService::resolveLocatorSlots()
 * - callers must never feed locator-derived slots into $excludedSlotsByDate.
 */
class ExcludedSlotPunchRecovery
{
    public function __construct(
        private readonly DtrPunchResolver $punchResolver,
        private readonly ShiftPunchGrouper $punchGrouper,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $excludedSlotsByDate  date('Y-m-d') => slot('am_in'|'am_out'|'pm_in'|'pm_out') => anything.
     *                                                                    Only the slot KEYS are read - build this from DtrExcuse::excludedSlotKeys()
     *                                                                    and WorkSchedule::applySuspension()'s returned slot array, never from a
     *                                                                    Locator's windowed exclusion.
     * @param  Collection<int, Dtr>|null  $preloadedDtrRows
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloadedAssignments  Same per-date override
     *                                                                                collection the caller already loaded for its own
     *                                                                                WorkSchedule::forUserOnDate() calls - passed through to
     *                                                                                ShiftPunchGrouper::group() and forUserOnDate() below so this
     *                                                                                re-resolution doesn't re-query employee_shift_schedules per
     *                                                                                punch/date.
     * @return array<string, array<string, string>> date('Y-m-d') => slot => 'H:i:s'
     */
    public function recover(User $user, string $from, string $to, array $excludedSlotsByDate, ?Collection $preloadedDtrRows = null, ?Collection $preloadedAssignments = null): array
    {
        $candidateDates = array_keys(array_filter($excludedSlotsByDate, fn (array $slots): bool => $slots !== []));
        if ($candidateDates === []) {
            return [];
        }

        $logs = AttendanceLog::where('user_id', $user->id)
            ->whereBetween('logdate', [
                Carbon::parse($from)->subDay()->toDateString(),
                Carbon::parse($to)->addDay()->toDateString(),
            ])
            ->orderBy('logdate')
            ->orderBy('logtime')
            ->get();

        $grouped = $this->punchGrouper->group($user, $logs, $preloadedAssignments);

        $dtrByDate = ($preloadedDtrRows ?? Dtr::where('employee_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->get(['date', 'unmatched_logs', 'time_in_ot', 'time_out_ot']))
            ->keyBy(fn (Dtr $d): string => Carbon::parse($d->date)->format('Y-m-d'));

        $recovered = [];

        foreach ($candidateDates as $date) {
            $punches = $grouped[$date] ?? null;
            if ($punches === null || $punches->isEmpty()) {
                continue;
            }

            $dtr = $dtrByDate->get($date);
            if ($dtr === null) {
                // No persisted resolution to cross-check against - safest default.
                continue;
            }

            $safePool = array_values(array_filter(array_merge(
                $dtr->unmatched_logs ?? [],
                [$dtr->time_in_ot, $dtr->time_out_ot]
            )));
            if ($safePool === []) {
                continue;
            }

            $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($date), $preloadedAssignments);
            $unconstrained = $this->punchResolver->resolve($punches, $date, $schedule, []);

            foreach (array_keys($excludedSlotsByDate[$date]) as $slot) {
                $candidate = $unconstrained[$slot] ?? null;
                if ($candidate !== null && in_array($candidate, $safePool, true)) {
                    $recovered[$date][$slot] = $candidate;
                }
            }
        }

        return $recovered;
    }
}
