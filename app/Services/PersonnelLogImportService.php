<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Locator;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PersonnelLogImportService
{
    public function __construct(
        private readonly IntegrationApiService $integrationApi,
        private readonly DtrPunchResolver $punchResolver,
        private readonly ShiftPunchGrouper $punchGrouper,
    ) {}

    /**
     * @return array{imported: int, skipped: int, messages: array<int, string>, error: ?string}
     */
    public function importForDateRange(string $from, string $to, ?int $deptId = null, ?int $pageSize = null): array
    {
        $imported = 0;
        $skipped = 0;
        $messages = [];

        try {
            $token = $this->integrationApi->getToken();
        } catch (\RuntimeException $e) {
            return ['imported' => 0, 'skipped' => 0, 'messages' => [], 'error' => $e->getMessage()];
        }

        // Build two EmpNo → User lookup layers so O(1) match works even when the
        // biometric system uses non-padded personnelid ('2009') but HRIS stores the
        // EmpNo zero-padded ('02009'), or vice-versa.
        $userQuery = User::whereNotNull('EmpNo')->where('EmpNo', '!=', '');
        if ($deptId !== null) {
            $userQuery->where('Dept_id', $deptId);
        }
        $users = $userQuery->get(['id', 'EmpNo', 'Dept_id', 'dtr_exempt']);

        if ($users->isEmpty()) {
            $scope = $deptId ? "department #{$deptId}" : 'any department';

            return ['imported' => 0, 'skipped' => 0, 'messages' => [],
                'error' => "No HRIS users with EmpNo found for {$scope}. Set EmpNo on employee records before importing."];
        }

        $exactMap = $users->keyBy('EmpNo');          // primary: exact string match

        $strippedMap = [];                            // fallback: leading-zeros stripped
        foreach ($users as $user) {
            $stripped = ltrim((string) $user->EmpNo, '0') ?: '0';
            if (! $exactMap->has($stripped)) {
                $strippedMap[$stripped] = $user;
            }
        }

        // Users who received new punches - only their DTR rows need recomputing.
        $affectedUsers = [];
        // Track personnelids with no HRIS match so the audit log can name them.
        $unmatchedNames = [];   // personnelid → "FIRSTNAME LASTNAME"

        $pageSize = $pageSize ?? (int) config('integration.logs_page_size', 1000);
        $start = 0;

        // Bulk fetch: one API call per page for ALL employees instead of one call
        // per employee. Replaced 781 sequential calls with ≤ a handful of pages.
        do {
            try {
                [$logsData, $httpStatus] = $this->integrationApi->fetchBulkLogs($token, $from, $to, $start, $pageSize);
            } catch (\Throwable $e) {
                return ['imported' => $imported, 'skipped' => $skipped, 'messages' => $messages,
                    'error' => "Bulk API connection error at offset {$start}: {$e->getMessage()}"];
            }

            if ($httpStatus !== 200) {
                $error = "Bulk API call failed (HTTP {$httpStatus}) at offset {$start}";
                Log::error('Attendance bulk import failed', ['status' => $httpStatus, 'start' => $start]);

                return ['imported' => $imported, 'skipped' => $skipped, 'messages' => $messages, 'error' => $error];
            }

            foreach ($logsData as $item) {
                if (! is_array($item)) {
                    $skipped++;

                    continue;
                }

                $personnelId = $this->getKey($item, ['personnelid', 'EmpNo', 'empNo', 'emp_no']);
                $logdate = $this->getKey($item, ['logdate', 'LogDate', 'Date', 'date']);
                $logtime = $this->getKey($item, ['logtime', 'LogTime', 'Time', 'time']);

                // Normalize to HH:MM:SS - API returns HH:MM without seconds.
                if ($logtime !== null && substr_count((string) $logtime, ':') === 1) {
                    $logtime .= ':00';
                }

                // Fall back to a combined datetime field when individual fields are absent.
                if (empty($logdate) || empty($logtime)) {
                    $combined = $this->getKey($item, ['LogDateTime', 'DateTime', 'datetime', 'Timestamp', 'timestamp']);
                    if (! empty($combined) && ($ts = strtotime((string) $combined)) !== false) {
                        if (empty($logdate)) {
                            $logdate = date('Y-m-d', $ts);
                        }
                        if (empty($logtime)) {
                            $logtime = date('H:i:s', $ts);
                        }
                    }
                }

                if (empty($logdate) || empty($logtime)) {
                    $skipped++;

                    continue;
                }

                // Resolve to an HRIS user: exact EmpNo, then stripped-zeros fallback.
                $pidStr = (string) $personnelId;
                $resolvedUser = $exactMap->get($pidStr)
                    ?? ($strippedMap[$pidStr] ?? null)
                    ?? ($strippedMap[ltrim($pidStr, '0') ?: '0'] ?? null);

                if ($resolvedUser === null) {
                    // Collect name for audit reporting (only need one record per personnelid).
                    if (! isset($unmatchedNames[$pidStr])) {
                        $first = trim((string) ($item['personnelfirstname'] ?? $item['firstName'] ?? ''));
                        $last = trim((string) ($item['personnellastname'] ?? $item['lastName'] ?? ''));
                        $unmatchedNames[$pidStr] = trim("$first $last") ?: '(unknown)';
                    }
                    $skipped++;

                    continue;
                }

                // Exempt employees never receive biometric/DTR records - drop their punches.
                if ($resolvedUser->dtr_exempt) {
                    $skipped++;

                    continue;
                }

                // inout: API returns 'IN', 'OUT', or '255' (undefined direction from reader).
                $rawInOut = $this->getKey($item, ['inout', 'InOut', 'In_Out']);
                $inOut = match ((string) $rawInOut) {
                    '0', 'in',  'IN' => 'IN',
                    '1', 'out', 'OUT' => 'OUT',
                    default => null,
                };

                try {
                    $log = AttendanceLog::firstOrCreate(
                        [
                            'user_id' => $resolvedUser->id,
                            'logdate' => $logdate,
                            'logtime' => $logtime,
                        ],
                        [
                            'emp_no' => $pidStr,
                            'logtype' => $this->getKey($item, ['LogType', 'logtype', 'Type']) ?? 'SYSTEM',
                            'text' => $this->sanitizeLogText($this->getKey($item, ['Text', 'text', 'Remark', 'Notes'])),
                            'device_name' => $this->getKey($item, ['devicename', 'DeviceName', 'device_name', 'Device']),
                            'in_out' => $inOut,
                        ]
                    );

                    if ($log->wasRecentlyCreated) {
                        $imported++;
                        $affectedUsers[$resolvedUser->id] = $resolvedUser;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to insert attendance log', [
                        'user_id' => $resolvedUser->id,
                        'personnelid' => $pidStr,
                        'error' => $e->getMessage(),
                    ]);
                    $skipped++;
                }
            }

            $start += $pageSize;
        } while (count($logsData) >= $pageSize);

        if ($imported === 0 && $skipped === 0 && empty($unmatchedNames)) {
            $messages[] = "API returned no punch records for [{$from} to {$to}]. Verify the date range and that the biometric system has data for this period.";
        }

        // Upsert DTR rows only for users who actually received new punches.
        foreach ($affectedUsers as $user) {
            $this->upsertDtrRecords($user, $from, $to);
            $messages[] = "Updated DTR for EmpNo {$user->EmpNo}";
        }

        // Report biometric employees with no HRIS match so admin can fix EmpNo.
        if (! empty($unmatchedNames)) {
            $messages[] = count($unmatchedNames).' biometric personnelid(s) have no matching HRIS EmpNo - update the employee\'s EmpNo to import their records:';
            foreach ($unmatchedNames as $pid => $name) {
                $messages[] = "  personnelid={$pid} ({$name})";
            }
        }

        Log::info('Attendance bulk import complete', [
            'from' => $from,
            'to' => $to,
            'dept_id' => $deptId,
            'imported' => $imported,
            'skipped' => $skipped,
            'unmatched_ids' => array_keys($unmatchedNames),
        ]);

        return ['imported' => $imported, 'skipped' => $skipped, 'messages' => $messages, 'error' => null];
    }

    // ── DTR COMPUTATION ───────────────────────────────────────────────────────

    /**
     * Recompute DTR rows from already-imported attendance_logs (no API call).
     * Use after correcting punch-resolution logic to repair existing dtrs.
     */
    public function recomputeDtr(User $user, string $from, string $to): void
    {
        $this->upsertDtrRecords($user, $from, $to);
    }

    /**
     * Recompute DTR across the employee's full attendance-log history - used
     * after a shift assignment change, so stored late/undertime reflect the
     * new shift. A no-op if the employee has no imported punches yet.
     */
    public function recomputeFullRange(User $user): void
    {
        $range = AttendanceLog::where('user_id', $user->id)
            ->selectRaw('MIN(logdate) as min_d, MAX(logdate) as max_d')
            ->first();

        if ($range === null || $range->min_d === null) {
            return;
        }

        $this->recomputeDtr(
            $user,
            Carbon::parse($range->min_d)->toDateString(),
            Carbon::parse($range->max_d)->toDateString(),
        );
    }

    private function upsertDtrRecords(User $user, string $from, string $to): void
    {
        // Exempt employees keep no DTR rows regardless of imported punches.
        if ($user->dtr_exempt) {
            return;
        }

        // Always pad by 1 day: night shifts and per-date schedule variations can
        // place a punch from calendar day N onto shift date N-1, so we need one
        // extra day on each side to capture complete first/last shifts.
        $fetchFrom = Carbon::parse($from)->subDay()->toDateString();
        $fetchTo = Carbon::parse($to)->addDay()->toDateString();

        // Pre-load per-date shift assignments for the padded range so every
        // downstream call (grouper, resolver) is O(1) - no per-date DB queries.
        $assignments = EmployeeShiftSchedule::where('user_id', $user->id)
            ->whereBetween('date', [$fetchFrom, $fetchTo])
            ->with('shift')
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        // Same reasoning as $assignments above: warm the shift-assignment-history
        // memo once so the per-date WorkSchedule calls below stay O(1).
        WorkSchedule::preloadShiftAssignments([$user->id]);

        $logs = AttendanceLog::where('user_id', $user->id)
            ->whereBetween('logdate', [$fetchFrom, $fetchTo])
            ->orderBy('logdate')
            ->orderBy('logtime')
            ->get();

        if ($logs->isEmpty()) {
            $this->deleteOrphanedBiometricDtrRows($user, $from, $to, []);

            return;
        }

        // Excused slots have no real punch expected - the resolver uses this to
        // avoid mis-slotting a later punch into an excused slot.
        $excuseMap = DtrExcuse::where('user_id', $user->id)
            ->whereBetween('date', [$fetchFrom, $fetchTo])
            ->get()
            ->keyBy(fn (DtrExcuse $e) => Carbon::parse($e->date)->format('Y-m-d'));

        // Approved-locator-covered slots also have no real punch expected -
        // merge their coverage in the same way, unioning multiple locators
        // on the same date into a single [earliest departure, latest arrival]
        // exclusion window per slot.
        $locatorSlotMap = $this->buildLocatorSlotMap($user, $fetchFrom, $fetchTo, $assignments);

        // Declared work suspensions (typhoon/urgent-event dismissal) also have
        // no real punch expected past their cutoff - see WorkSchedule::applySuspension().
        $suspensionMap = WorkSuspension::whereBetween('suspension_date', [$fetchFrom, $fetchTo])
            ->get()
            ->keyBy(fn (WorkSuspension $s) => Carbon::parse($s->suspension_date)->format('Y-m-d'));

        $producedDates = [];

        foreach ($this->punchGrouper->group($user, $logs, $assignments) as $date => $punches) {
            // Only write shifts whose logical date falls inside the requested range.
            if ($date < $from || $date > $to) {
                continue;
            }

            $producedDates[] = $date;

            // Rest days with no punches won't appear here (grouper only produces
            // entries when there are actual punches). If someone punched in on a
            // rest day (voluntary OT), still record the DTR using their assigned
            // rest-day shift (which falls back to the default if shift_id is null).
            $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($date), $assignments);
            $suspensionSlots = [];
            if (($suspension = $suspensionMap->get($date)) !== null && ! $user->isFrontlineExempt()) {
                [$schedule, $suspensionSlots] = $schedule->applySuspension($suspension->suspension_time);
            }
            $excludedSlots = array_merge(
                array_fill_keys($excuseMap->get($date)?->excludedSlotKeys() ?? [], null),
                $locatorSlotMap[$date] ?? [],
                $suspensionSlots
            );

            $resolved = $this->punchResolver->resolve($punches, $date, $schedule, $excludedSlots);

            Dtr::updateOrCreate(
                ['employee_id' => $user->id, 'date' => $date],
                [
                    'time_in_am' => $resolved['am_in'],
                    'time_out_am' => $resolved['am_out'],
                    'time_in_pm' => $resolved['pm_in'],
                    'time_out_pm' => $resolved['pm_out'],
                    'late_minutes' => $resolved['late_minutes'],
                    'undertime_minutes' => $resolved['undertime_minutes'],
                    'hours_worked' => $resolved['hours_worked'],
                    'overtime_minutes' => $resolved['overtime_minutes'],
                    'time_in_ot' => $resolved['time_in_ot'],
                    'time_out_ot' => $resolved['time_out_ot'],
                    'unmatched_logs' => $resolved['unmatched'] ?: null,
                    // Never true from an automatic import: absence stays a
                    // read-time classification (no dtrs row = absent), and the
                    // status resolver can't return 'absent' either.
                    'is_absent' => false,
                    'status' => $resolved['status'],
                    'source' => 'biometric',
                ]
            );
        }

        $this->deleteOrphanedBiometricDtrRows($user, $from, $to, $producedDates);
    }

    /**
     * A schedule/shift change can reshuffle which shift-date an existing punch
     * groups onto (a 24-hour crossing shift's exact time_in==time_out boundary
     * does this constantly - see WorkSchedule::shiftDateFor()'s docblock).
     * Without this, the row at the date's OLD attribution is never cleaned up,
     * since the upsert loop above only ever touches dates the CURRENT grouping
     * pass actually produces. Scoped to source='biometric' so a manually
     * entered row (source='manual', or NULL from Payroll\AttendanceController)
     * is never touched.
     */
    private function deleteOrphanedBiometricDtrRows(User $user, string $from, string $to, array $producedDates): void
    {
        Dtr::where('employee_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->where('source', 'biometric')
            ->whereNotIn('date', $producedDates)
            ->delete();
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Day-string → slot-key → exclusion window map for a user's approved locators
     * in range. Multiple locators covering the same slot on the same date union
     * into a single [earliest departure, latest arrival] window, matching
     * Form48ExportService::buildLocatorMap()'s display-side union so the two
     * never disagree on how far a covered slot's exclusion window reaches.
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $assignments
     * @return array<string, array<string, array{0:string,1:string}>>
     */
    private function buildLocatorSlotMap(User $user, string $from, string $to, ?Collection $assignments): array
    {
        $map = [];

        Locator::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$from, $to])
            ->get(['travel_date', 'intended_departure_time', 'intended_arrival_time'])
            ->each(function (Locator $locator) use (&$map, $user, $assignments): void {
                $dateStr = Carbon::parse($locator->travel_date)->format('Y-m-d');
                $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($locator->travel_date), $assignments);
                $windows = Locator::coveredSlotWindows(
                    (string) $locator->intended_departure_time,
                    (string) $locator->intended_arrival_time,
                    $schedule
                );

                foreach ($windows as $slotKey => $window) {
                    $existing = $map[$dateStr][$slotKey] ?? null;
                    $map[$dateStr][$slotKey] = $existing === null
                        ? $window
                        : [min($existing[0], $window[0]), max($existing[1], $window[1])];
                }
            });

        return $map;
    }

    private function sanitizeLogText(mixed $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return mb_substr(strip_tags((string) $text), 0, 500) ?: null;
    }

    /**
     * @param  array<string, mixed>  $arr
     * @param  list<string>  $names
     */
    private function getKey(array $arr, array $names): mixed
    {
        foreach ($names as $n) {
            foreach ($arr as $k => $v) {
                if (strcasecmp((string) $k, $n) === 0 && $v !== null && $v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }
}
