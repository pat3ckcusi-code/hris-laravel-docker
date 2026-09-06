<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\DtrExemptionPeriod;
use App\Models\EmployeeShiftSchedule;
use App\Models\Locator;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
    /**
     * Acquires one per-date lock (see config('attendance.import_lock')) before
     * running the real import, so two calls for the same date - a manual pull
     * racing the scheduler, or two manual pulls - never race the DTR
     * upsert/orphan-cleanup step inside importForDateRangeInner(). Always
     * exactly one date in practice (both callers dispatch one job per single
     * calendar day), but loops over every date in [from, to] to stay correct
     * if that calling pattern ever changes. Locking happens before the token
     * fetch so a skipped run costs zero external API calls.
     */
    public function importForDateRange(string $from, string $to, ?int $deptId = null, ?int $pageSize = null): array
    {
        $ttl = (int) config('attendance.import_lock.ttl_seconds', 650);
        $wait = (int) config('attendance.import_lock.wait_seconds', 10);

        $locks = [];
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $locks[] = Cache::lock('attendance-import:'.$date->toDateString(), $ttl);
        }

        try {
            foreach ($locks as $lock) {
                $lock->block($wait);
            }
        } catch (LockTimeoutException) {
            foreach ($locks as $lock) {
                $lock->release();
            }

            return ['imported' => 0, 'skipped' => 0, 'messages' => [],
                'error' => "Another import for {$from}".($to !== $from ? " - {$to}" : '')." is already in progress. Please try again shortly."];
        }

        try {
            return $this->importForDateRangeInner($from, $to, $deptId, $pageSize);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    private function importForDateRangeInner(string $from, string $to, ?int $deptId = null, ?int $pageSize = null): array
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
        //
        // Deliberately NOT filtered by $deptId: the biometric API call below always
        // returns the whole company's punches for the date range regardless of
        // $deptId, so narrowing the candidate map here would silently drop (never
        // persist) every other department's punches for that pull instead of just
        // narrowing which department's DTRs get recomputed/reported. $deptId is
        // applied later, only to which users get their DTR recomputed and named
        // in this run's messages.
        [$exactMap, $strippedMap] = $this->buildEmpNoLookupMaps();

        if ($exactMap->isEmpty()) {
            return ['imported' => 0, 'skipped' => 0, 'messages' => [],
                'error' => 'No HRIS users with EmpNo found. Set EmpNo on employee records before importing.'];
        }

        // Track personnelids with no HRIS match so the audit log can name them.
        $unmatchedNames = [];   // personnelid → "FIRSTNAME LASTNAME"

        // The vendor's GetTimeLogsBulkData endpoint does not paginate its
        // result set reliably when 'start' > 0 across multiple calls -
        // confirmed 2026-09-02: a multi-page walk both silently dropped a
        // real employee's punches entirely (invisible everywhere else in
        // this method - no error, no unmatched-EmpNo report, nothing) and
        // duplicated other employees' records across page boundaries. A
        // record repeating across pages is the only detectable symptom of
        // that instability from our side, so it's tracked here purely as an
        // early-warning signal for a future day whose volume exceeds even a
        // generously-sized $pageSize (see config/integration.php) - raising
        // the page size high enough to avoid multi-page fetches on any
        // realistic day is the actual fix; this is the safety net in case
        // that assumption is ever wrong again.
        $seenRecordKeys = [];
        $duplicateRecordCount = 0;

        $pageSize = $pageSize ?? (int) config('integration.logs_page_size', 20000);
        $start = 0;

        // Bulk fetch: one API call per page for ALL employees instead of one call
        // per employee. Replaced 781 sequential calls with ≤ a handful of pages.
        //
        // A failure on a LATER page must not skip the DTR recompute below for
        // punches already persisted from EARLIER pages - firstOrCreate() below
        // is idempotent, so a retried import will always find an already-saved
        // punch without ever re-inserting it, and the recompute step below is
        // keyed off "who has punches in range" rather than "who got a punch
        // THIS run" specifically so that doesn't matter either way (see the
        // recompute step's own comment).
        $fatalError = null;
        $pageCount = 0;

        do {
            $pageCount++;

            try {
                [$logsData, $httpStatus] = $this->integrationApi->fetchBulkLogs($token, $from, $to, $start, $pageSize);
            } catch (\Throwable $e) {
                $fatalError = "Bulk API connection error at offset {$start}: {$e->getMessage()}";
                break;
            }

            if ($httpStatus !== 200) {
                $fatalError = "Bulk API call failed (HTTP {$httpStatus}) at offset {$start}";
                Log::error('Attendance bulk import failed', ['status' => $httpStatus, 'start' => $start]);
                break;
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

                $pidStr = (string) $personnelId;

                $recordKey = $pidStr.'|'.$logdate.'|'.$logtime;
                if (isset($seenRecordKeys[$recordKey])) {
                    $duplicateRecordCount++;
                } else {
                    $seenRecordKeys[$recordKey] = true;
                }

                $resolvedUser = $this->resolveUserForPersonnelId($pidStr, $exactMap, $strippedMap);

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

        // Recompute DTR for every user with ANY punch in the requested range -
        // not only users whose punches were newly inserted by THIS run.
        // $from/$to here is always a short, bounded, explicitly-requested
        // range (the manual "Pull Biometric Punch Logs" UI and the daily
        // auto-import both dispatch one job per single day), so this is
        // cheap and safe, unlike recomputeFullRange()'s unbounded history
        // scan. Scoping to newly-created punches only left no way to repair
        // a DTR row that fell behind for some other reason (a prior partial
        // import failure, a queue worker that crashed/restarted mid-job,
        // a future bug) - AttendanceLog::firstOrCreate() being idempotent
        // meant such a row could never self-heal, since re-running the
        // import would always find the punch already saved and never
        // re-trigger its recompute. Keying off "who has punches in range"
        // instead makes "re-run the import" a real fix for a stale/missing
        // DTR row regardless of cause.
        $usersToRecompute = User::whereIn('id', function ($query) use ($from, $to): void {
            $query->select('user_id')->from('attendance_logs')->whereBetween('logdate', [$from, $to]);
        })
            ->when($deptId !== null, fn ($q) => $q->where('Dept_id', $deptId))
            ->get(['id', 'EmpNo', 'Dept_id', 'dtr_exempt']);

        // Batch-fetch everything upsertDtrRecords() needs for the whole set of
        // users up front (one query each) instead of letting it re-query per
        // user below - with 1000+ employees that was ~6 queries x N every
        // single import cycle (this method runs every minute via
        // attendance:auto-import). recomputeDtr()/recomputeFullRange() still
        // call upsertDtrRecords() with no preloaded data for their single-user
        // case, so nothing here changes that path.
        $userIds = $usersToRecompute->pluck('id');
        $recomputeFetchFrom = Carbon::parse($from)->subDay()->toDateString();
        $recomputeFetchTo = Carbon::parse($to)->addDay()->toDateString();

        WorkSchedule::preloadShiftAssignments($userIds->all());

        $assignmentsByUser = EmployeeShiftSchedule::whereIn('user_id', $userIds)
            ->whereBetween('date', [$recomputeFetchFrom, $recomputeFetchTo])
            ->with('shift')
            ->get()
            ->groupBy('user_id');

        $logsByUser = AttendanceLog::whereIn('user_id', $userIds)
            ->whereBetween('logdate', [$recomputeFetchFrom, $recomputeFetchTo])
            ->orderBy('logdate')
            ->orderBy('logtime')
            ->get()
            ->groupBy('user_id');

        $excusesByUser = DtrExcuse::whereIn('user_id', $userIds)
            ->whereBetween('date', [$recomputeFetchFrom, $recomputeFetchTo])
            ->get()
            ->groupBy('user_id');

        // All of each user's exemption periods (not range-scoped) - see
        // upsertDtrRecords()'s own comment for why "all periods" rather than
        // just ones overlapping the fetch window.
        $exemptionsByUser = DtrExemptionPeriod::whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id');

        $locatorsByUser = Locator::whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$recomputeFetchFrom, $recomputeFetchTo])
            ->get(['user_id', 'travel_date', 'intended_departure_time', 'intended_arrival_time'])
            ->groupBy('user_id');

        // Suspensions were never user-scoped to begin with - one query, shared
        // by every user in this batch instead of re-fetched identically per user.
        $suspensionsForBatch = WorkSuspension::whereBetween('suspension_date', [$recomputeFetchFrom, $recomputeFetchTo])->get();

        // Report biometric employees with no HRIS match so admin can fix EmpNo.
        // Pushed BEFORE the per-user "Updated DTR" messages below (rather than
        // after, where they used to sit) so this diagnostic survives
        // ImportAttendanceLogsJob's cap on stored messages (array_slice to the
        // first 100) on a large run with 100+ recomputed employees - otherwise
        // the one message that would actually explain a matching failure could
        // get silently truncated out of the Recent Import Results panel.
        if (! empty($unmatchedNames)) {
            $messages[] = count($unmatchedNames).' biometric personnelid(s) have no matching HRIS EmpNo - update the employee\'s EmpNo to import their records:';
            foreach ($unmatchedNames as $pid => $name) {
                $messages[] = "  personnelid={$pid} ({$name})";
            }
        }

        // Confirmed 2026-09-02: the vendor's own dataset can contain duplicate
        // rows for the same punch even within a SINGLE page (harmless - the
        // full day's data was already captured in that one response, nothing
        // else to miss). A duplicate that crosses a PAGE BOUNDARY is a
        // different, much more serious signal: it's the only detectable
        // symptom of the vendor's pagination silently dropping OTHER records
        // elsewhere in the same multi-page walk the same way (confirmed
        // happening in practice - a real employee's entire day of punches
        // vanished with no other trace). Both are surfaced, but only the
        // multi-page case is treated as an actionable, urgent warning.
        if ($duplicateRecordCount > 0) {
            if ($pageCount > 1) {
                $messages[] = "WARNING: {$duplicateRecordCount} duplicate record(s) detected ACROSS PAGES for [{$from} to {$to}] ({$pageCount} pages fetched) - the biometric API's pagination may be unstable and some employees' punches for this range could be silently missing. Consider re-running this range with a larger page size, or check individual employees via the raw-feed diagnostic tool.";
                Log::error('Attendance bulk import: unstable multi-page pagination detected', [
                    'from' => $from,
                    'to' => $to,
                    'dept_id' => $deptId,
                    'duplicate_record_count' => $duplicateRecordCount,
                    'page_count' => $pageCount,
                    'page_size' => $pageSize,
                ]);
            } else {
                $messages[] = "{$duplicateRecordCount} duplicate record(s) noted in the biometric feed for [{$from} to {$to}] - harmless, the full day fetched in a single page so nothing else should be missing.";
                Log::info('Attendance bulk import: duplicate records within a single page (harmless)', [
                    'from' => $from,
                    'to' => $to,
                    'dept_id' => $deptId,
                    'duplicate_record_count' => $duplicateRecordCount,
                ]);
            }
        }

        foreach ($usersToRecompute as $user) {
            $this->upsertDtrRecords(
                $user,
                $from,
                $to,
                assignmentsPreloaded: true,
                preloadedAssignmentRows: $assignmentsByUser->get($user->id) ?? collect(),
                preloadedLogs: $logsByUser->get($user->id) ?? collect(),
                preloadedExcuses: $excusesByUser->get($user->id) ?? collect(),
                preloadedLocators: $locatorsByUser->get($user->id) ?? collect(),
                preloadedSuspensions: $suspensionsForBatch,
                preloadedExemptions: $exemptionsByUser->get($user->id) ?? collect(),
            );
            $messages[] = "Updated DTR for EmpNo {$user->EmpNo}";
        }

        // Recompute above already ran for whatever was imported before the
        // failure; still report the run itself as failed so the caller/audit
        // log surfaces the connection issue.
        if ($fatalError !== null) {
            return ['imported' => $imported, 'skipped' => $skipped, 'messages' => $messages, 'error' => $fatalError];
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

    /**
     * Build the two EmpNo → User lookup layers used to resolve a biometric
     * personnelid to an HRIS user, so O(1) match works even when the
     * biometric system uses non-padded personnelid ('2009') but HRIS stores
     * the EmpNo zero-padded ('02009'), or vice-versa.
     *
     * Exposed publicly (rather than kept private/inline) so diagnostic
     * tooling (see AttendanceImportController::checkEmployeeOnDate()) can
     * resolve a personnelid using this exact production logic - including
     * its collision-avoidance guard below - instead of a separate
     * reimplementation that could silently drift out of sync with it.
     *
     * @return array{0: Collection<string, User>, 1: array<string, User>}
     */
    public function buildEmpNoLookupMaps(): array
    {
        $users = User::whereNotNull('EmpNo')->where('EmpNo', '!=', '')
            ->get(['id', 'EmpNo', 'Dept_id', 'dtr_exempt']);

        $exactMap = $users->keyBy('EmpNo');          // primary: exact string match

        $strippedMap = [];                            // fallback: leading-zeros stripped
        foreach ($users as $user) {
            $stripped = ltrim((string) $user->EmpNo, '0') ?: '0';
            if (! $exactMap->has($stripped)) {
                $strippedMap[$stripped] = $user;
            }
        }

        return [$exactMap, $strippedMap];
    }

    /**
     * Resolve a single biometric personnelid to an HRIS user using the maps
     * from buildEmpNoLookupMaps(): exact EmpNo, then stripped-zeros fallback
     * in both directions (HRIS padded/device unpadded via $strippedMap, or
     * HRIS unpadded/device padded via the final exact-on-stripped-id check -
     * e.g. HRIS EmpNo '300858' vs a device personnelid '0300858').
     *
     * @param  Collection<string, User>  $exactMap
     * @param  array<string, User>  $strippedMap
     */
    public function resolveUserForPersonnelId(string $pidStr, Collection $exactMap, array $strippedMap): ?User
    {
        $strippedPid = ltrim($pidStr, '0');

        return $exactMap->get($pidStr)
            ?? ($strippedMap[$pidStr] ?? null)
            ?? ($strippedMap[$strippedPid ?: '0'] ?? null)
            ?? ($strippedPid !== '' ? $exactMap->get($strippedPid) : null);
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

    private function upsertDtrRecords(
        User $user,
        string $from,
        string $to,
        bool $assignmentsPreloaded = false,
        ?Collection $preloadedAssignmentRows = null,
        ?Collection $preloadedLogs = null,
        ?Collection $preloadedExcuses = null,
        ?Collection $preloadedLocators = null,
        ?Collection $preloadedSuspensions = null,
        ?Collection $preloadedExemptions = null,
    ): void {
        // All of this user's dtr_exemption_periods rows, not just ones
        // overlapping this call's own [from, to] - deliberately unbounded so
        // "has this user EVER had a real period" can be told apart from "no
        // period happens to cover this specific range" a few lines below.
        $exemptions = $preloadedExemptions ?? DtrExemptionPeriod::where('user_id', $user->id)->get();

        // Legacy fallback: a user with dtr_exempt=true but zero
        // dtr_exemption_periods rows ever (pre-migration data not yet run
        // through dtr:backfill-exemption-periods, or a manually-constructed
        // row) keeps no DTR rows at all, exactly like this method's original
        // unconditional guard - there's no period history to check dates
        // against, only the live flag. A user WITH real period history is
        // instead checked per-date below, since dtr_exempt only ever answers
        // "exempt today", not "exempt on this specific requested date".
        if ($exemptions->isEmpty() && $user->dtr_exempt) {
            return;
        }

        $isDateExempt = fn (string $date): bool => $exemptions->contains(fn (DtrExemptionPeriod $p) => $p->coversDate($date));

        // Always pad by 1 day: night shifts and per-date schedule variations can
        // place a punch from calendar day N onto shift date N-1, so we need one
        // extra day on each side to capture complete first/last shifts.
        $fetchFrom = Carbon::parse($from)->subDay()->toDateString();
        $fetchTo = Carbon::parse($to)->addDay()->toDateString();

        // Pre-load per-date shift assignments for the padded range so every
        // downstream call (grouper, resolver) is O(1) - no per-date DB queries.
        // A batch caller (see importForDateRange()) passes these in already
        // fetched for the whole run instead of one query per user.
        $assignments = ($preloadedAssignmentRows ?? EmployeeShiftSchedule::where('user_id', $user->id)
            ->whereBetween('date', [$fetchFrom, $fetchTo])
            ->with('shift')
            ->get())
            ->keyBy(fn ($a) => $a->date->toDateString());

        // Same reasoning as $assignments above: warm the shift-assignment-history
        // memo once so the per-date WorkSchedule calls below stay O(1). Skipped
        // when a batch caller already warmed it for the whole run - the memo is
        // a single static property that gets wholesale replaced on every call,
        // so calling this again here for just this one user would blow away
        // every other user's already-warmed data instead of adding to it.
        if (! $assignmentsPreloaded) {
            WorkSchedule::preloadShiftAssignments([$user->id]);
        }

        $logs = $preloadedLogs ?? AttendanceLog::where('user_id', $user->id)
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
        $excuseMap = ($preloadedExcuses ?? DtrExcuse::where('user_id', $user->id)
            ->whereBetween('date', [$fetchFrom, $fetchTo])
            ->get())
            ->keyBy(fn (DtrExcuse $e) => Carbon::parse($e->date)->format('Y-m-d'));

        // Approved-locator-covered slots also have no real punch expected -
        // merge their coverage in the same way, unioning multiple locators
        // on the same date into a single [earliest departure, latest arrival]
        // exclusion window per slot.
        $locatorSlotMap = $this->buildLocatorSlotMap($user, $fetchFrom, $fetchTo, $assignments, $preloadedLocators);

        // Declared work suspensions (typhoon/urgent-event dismissal) also have
        // no real punch expected past their cutoff - see WorkSchedule::applySuspension().
        $suspensionMap = ($preloadedSuspensions ?? WorkSuspension::whereBetween('suspension_date', [$fetchFrom, $fetchTo])
            ->get())
            ->keyBy(fn (WorkSuspension $s) => Carbon::parse($s->suspension_date)->format('Y-m-d'));

        $producedDates = [];

        foreach ($this->punchGrouper->group($user, $logs, $assignments) as $date => $punches) {
            // Only write shifts whose logical date falls inside the requested range.
            if ($date < $from || $date > $to) {
                continue;
            }

            // A date covered by a real exemption period keeps no DTR row -
            // deliberately NOT added to $producedDates, so deleteOrphanedBiometricDtrRows()
            // below clears out any row that already existed here from before
            // the exemption was recorded (this is what makes a backdated
            // exemption actually retroactive).
            if ($isDateExempt($date)) {
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
    private function buildLocatorSlotMap(User $user, string $from, string $to, ?Collection $assignments, ?Collection $preloadedLocators = null): array
    {
        $map = [];

        ($preloadedLocators ?? Locator::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$from, $to])
            ->get(['travel_date', 'intended_departure_time', 'intended_arrival_time']))
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
