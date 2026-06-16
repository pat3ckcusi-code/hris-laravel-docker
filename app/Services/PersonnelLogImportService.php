<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PersonnelLogImportService
{
    public function __construct(
        private readonly IntegrationApiService $integrationApi,
        private readonly DtrPunchResolver $punchResolver,
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
        $users = $userQuery->get(['id', 'EmpNo', 'Dept_id']);

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

        // Users who received new punches — only their DTR rows need recomputing.
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

                // Normalize to HH:MM:SS — API returns HH:MM without seconds.
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
            $messages[] = count($unmatchedNames).' biometric personnelid(s) have no matching HRIS EmpNo — update the employee\'s EmpNo to import their records:';
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

    private function upsertDtrRecords(User $user, string $from, string $to): void
    {
        $logs = AttendanceLog::where('user_id', $user->id)
            ->whereBetween('logdate', [$from, $to])
            ->orderBy('logdate')
            ->orderBy('logtime')
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        foreach ($logs->groupBy(fn ($log) => $log->logdate->format('Y-m-d')) as $date => $dayLogs) {
            $resolved = $this->punchResolver->resolve(
                $dayLogs->pluck('logtime')->map(fn ($t) => (string) $t),
                $date
            );

            Dtr::updateOrCreate(
                ['employee_id' => $user->id, 'date' => $date],
                [
                    'time_in_am' => $resolved['am_in'],
                    'time_out_am' => $resolved['am_out'],
                    'time_in_pm' => $resolved['pm_in'],
                    'time_out_pm' => $resolved['pm_out'],
                    'late_minutes' => $resolved['late_minutes'],
                    'undertime_minutes' => $resolved['undertime_minutes'],
                    'is_absent' => false,
                    'status' => 'present',
                    'source' => 'biometric',
                ]
            );
        }
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

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
