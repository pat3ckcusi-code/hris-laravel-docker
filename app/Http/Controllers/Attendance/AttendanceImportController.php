<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Jobs\ImportAttendanceLogsJob;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\HRAuditTrail;
use App\Models\Locator;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\Attendance\ExcludedSlotPunchRecovery;
use App\Services\IntegrationApiService;
use App\Services\PersonnelLogImportService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AttendanceImportController extends Controller
{
    public function index(Request $request): View
    {
        $departments = Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);
        $setting = Setting::first();

        $empNoCount = User::whereNotNull('EmpNo')->where('EmpNo', '!=', '')->count();

        $recentImports = HRAuditTrail::where('module', 'attendance')
            ->where('action', 'attendance_import')
            ->whereNotNull('actor_user_id')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['details', 'created_at', 'actor_user_id']);

        [$unmatchedFrom, $unmatchedTo] = $this->resolveUnmatchedDateRange($request);
        $unmatchedDeptId = $request->integer('unmatched_dept_id') ?: null;
        // Only used to pre-fill the input on a deep-linked page load - the
        // badge count query below stays a pure date/dept-scoped approximation
        // regardless, same as before this field existed.
        $unmatchedSearch = trim((string) $request->query('unmatched_search', ''));

        // The Diagnostics tab's real results (candidate fetch + per-employee
        // Locator/Excuse/Suspension explanation check) are loaded lazily via
        // AJAX (see unmatchedPunchesData()) rather than here - that pipeline
        // costs ~3.5s against real dev data, while everything else index()
        // needs costs ~0.01s, and the tabs are just client-side show/hide, so
        // every page visit (including one that only wants to Pull Logs) used
        // to pay the full cost unconditionally. This is a cheap, raw,
        // unfiltered upper-bound count (no dept scope, no explanation
        // filtering) purely to seed the tab badge before that data loads.
        $unmatchedBadgeCount = Dtr::query()
            ->whereJsonLength('unmatched_logs', '>', 0)
            ->whereBetween('date', [$unmatchedFrom, $unmatchedTo])
            ->count();

        return view('hr-manager.attendance-import', compact(
            'departments', 'setting', 'empNoCount', 'recentImports',
            'unmatchedFrom', 'unmatchedTo', 'unmatchedDeptId', 'unmatchedBadgeCount', 'unmatchedSearch'
        ));
    }

    /**
     * Lazily-loaded Diagnostics tab data - see index()'s docblock comment for
     * why this was split out. Runs the exact same candidate-fetch +
     * explanation-filtering pipeline that used to live in index(), unchanged,
     * just relocated behind a dedicated AJAX endpoint fired only when the
     * Diagnostics tab is actually opened.
     */
    public function unmatchedPunchesData(Request $request): JsonResponse
    {
        [$unmatchedFrom, $unmatchedTo] = $this->resolveUnmatchedDateRange($request);
        $unmatchedDeptId = $request->integer('unmatched_dept_id') ?: null;
        $unmatchedSearch = trim((string) $request->query('unmatched_search', ''));

        // unmatched_logs non-empty is the precise fingerprint of a punch that
        // was never placed into any DTR slot - see ShiftPunchGrouper's class
        // docblock for the bug class this catches. Deliberately NOT keyed off
        // status (missing_out/incomplete are frequently a genuine forgotten
        // punch, not evidence of a grouping bug) - that would bury real
        // problems in noise. dtrs carries no index on unmatched_logs/status
        // (only a unique employee_id+date composite), so this stays bounded
        // by the date range rather than scanning the whole (large, growing)
        // table.
        $baseQuery = Dtr::query()
            ->whereJsonLength('unmatched_logs', '>', 0)
            ->whereBetween('date', [$unmatchedFrom, $unmatchedTo])
            ->when($unmatchedDeptId, fn ($q) => $q->whereHas(
                'employee', fn ($u) => $u->where('Dept_id', $unmatchedDeptId)
            ))
            // Matches the "Check Raw Biometric Feed" search already on this
            // same page (name-or-EmpNo) rather than DtrExcuseController's
            // name-only convention - EmpNo is a visible column in this table.
            ->when($unmatchedSearch !== '', fn ($q) => $q->whereHas(
                'employee', fn ($u) => $u->where('last_name', 'like', "%{$unmatchedSearch}%")
                    ->orWhere('first_name', 'like', "%{$unmatchedSearch}%")
                    ->orWhere('EmpNo', 'like', "%{$unmatchedSearch}%")
            ));

        // The true total matching this filter, BEFORE the fetch cap below -
        // real dev data has shown this can run into the thousands (2,196 in a
        // single 30-day window at time of writing) while the fetch cap only
        // pulls the most recent slice. Without this, the "showing the first N"
        // messaging can silently under-report how much was actually cut off.
        $totalMatchingCount = (clone $baseQuery)->count();

        // Fetches more than the 300 actually displayed - see the filter step
        // below for why. Raised from 600 to 2000 now that this no longer runs
        // on the default page-load path (see index()) - the 200-call
        // recover() budget below still bounds the expensive part
        // independent of how many rows are fetched here.
        $unmatchedCandidates = (clone $baseQuery)
            ->with([
                'employee:id,first_name,last_name,EmpNo,Dept_id',
                'employee.department:Dept_id,Dept_name',
            ])
            ->orderByDesc('date')
            ->limit(2000)
            ->get();

        // A punch already explained by a Locator/Suspension/Excuse conflict
        // displays correctly on the DTR page and Form 48 via
        // ExcludedSlotPunchRecovery, but its raw unmatched_logs entry never
        // gets cleared - it isn't the "stranded on the wrong calendar day"
        // grouping bug this tool exists to catch, and no amount of Recompute
        // can ever resolve it. Filter those out so the list stays focused on
        // genuinely unexplained stray punches; a row where only SOME of
        // several unmatched punches are explained still surfaces, since the
        // unexplained one remains a real anomaly.
        $recoveredByEmployeeDate = $this->explainedRecoveredValuesByEmployeeAndDate(
            $unmatchedCandidates, $unmatchedFrom, $unmatchedTo
        );
        $unmatchedDtrsAll = $unmatchedCandidates
            ->reject(fn (Dtr $row) => $this->isFullyExplained($row, $recoveredByEmployeeDate))
            ->values();

        // Pages through the already-fetched, already-filtered in-memory set
        // rather than a SQL LIMIT/OFFSET - which slot in the pipeline a row
        // ends up in is only known AFTER the explanation check above runs, so
        // paginating any earlier (at the query level) would produce gaps
        // whenever an explained row sits ahead of a genuinely unresolved one.
        $perPage = 25;
        $totalFiltered = $unmatchedDtrsAll->count();
        $lastPage = max(1, (int) ceil($totalFiltered / $perPage));
        $page = min(max(1, $request->integer('page', 1)), $lastPage);
        $unmatchedDtrs = $unmatchedDtrsAll->forPage($page, $perPage)->values();

        // Reuses the app's existing <x-hris.table-pagination> component
        // (already used by Payroll's Plantilla Reports/Run Show pages) for a
        // consistent look, rather than hand-rolled controls - built manually
        // since the data being paged is an in-memory, already-filtered
        // Collection, not a query Eloquent can paginate() directly (see the
        // comment above on why pagination has to happen after filtering).
        // Its links still carry a real, working href back to this same
        // endpoint with the current filters preserved; the click is
        // intercepted client-side to avoid a full page reload (see the JS in
        // attendance-import.blade.php), but the link degrades gracefully to
        // a real navigation if JS ever fails to attach.
        $paginator = new LengthAwarePaginator(
            $unmatchedDtrs, $totalFiltered, $perPage, $page,
            [
                'path' => route('hr-manager.attendance.import.unmatched-data'),
                'query' => [
                    'unmatched_from' => $unmatchedFrom,
                    'unmatched_to' => $unmatchedTo,
                    'unmatched_dept_id' => $unmatchedDeptId,
                    'unmatched_search' => $unmatchedSearch,
                ],
            ]
        );

        $html = view('hr-manager.partials.unmatched-punches-results', [
            'unmatchedDtrs' => $unmatchedDtrs,
            'totalMatchingCount' => $totalMatchingCount,
            'candidatesFetchedCount' => $unmatchedCandidates->count(),
            'paginator' => $paginator,
        ])->render();

        return response()->json([
            'html' => $html,
            // The total genuinely-unresolved count across ALL pages, not just
            // this one - a per-page count on the tab badge would be
            // misleading (e.g. always "25" once paginated).
            'badge_count' => $totalFiltered,
            // True only when the raw 2000-row fetch cap itself cut off real
            // matching rows before filtering even ran - decoupled from
            // ordinary pagination/explanation-filtering, neither of which is
            // a "cap" in this sense.
            'badge_capped' => $totalMatchingCount > $unmatchedCandidates->count(),
        ]);
    }

    /**
     * For each distinct employee among $candidates, resolves what
     * ExcludedSlotPunchRecovery would recover for their flagged dates - the
     * same check DtrController::data()/Form48ExportService already rely on
     * to display a real punch instead of "EXCUSED"/"SUSPENDED"/"LOCATOR".
     * Built fresh here (one recover() call per employee, not per row) rather
     * than reusing DtrController's/Form48ExportService's own inline map
     * construction - those already build the equivalent map from locally-
     * scoped data needed for other purposes in much larger, already-tested
     * methods, so re-deriving it standalone here avoids touching either.
     *
     * Every query below is batched across all candidate employees up front
     * (whereIn / a single company-wide fetch), not issued per employee inside
     * the loop - confirmed via a real dev-data measurement that the naive
     * per-employee version (WorkSuspension re-queried identically per
     * employee, DtrExcuse/Locator queried one-by-one, WorkSchedule::
     * forUserOnDate() never memoized) took ~27s and 36,000+ queries against
     * 421 distinct employees in the default 30-day window - unusable on a
     * real dataset. WorkSchedule::preloadShiftAssignments() is what makes
     * every forUserOnDate() call below O(1) instead of its own round trip.
     *
     * @param  Collection<int, Dtr>  $candidates
     * @return array<int, array<string, array<string, string>>> employeeId => date('Y-m-d') => slot => 'H:i:s'
     */
    private function explainedRecoveredValuesByEmployeeAndDate(Collection $candidates, string $from, string $to): array
    {
        $employeesById = $candidates->pluck('employee', 'employee_id')->filter();
        if ($employeesById->isEmpty()) {
            return [];
        }
        $employeeIds = $employeesById->keys()->all();

        WorkSchedule::preloadShiftAssignments($employeeIds);

        // Company-wide, not employee-scoped - fetch once instead of an
        // identical re-query per employee.
        $suspensions = WorkSuspension::whereBetween('suspension_date', [$from, $to])->get();

        // Employee-scoped, but a single whereIn() + in-memory grouping beats
        // one query per employee.
        $excusesByEmployee = DtrExcuse::whereIn('user_id', $employeeIds)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->groupBy('user_id');
        $locatorsByEmployee = Locator::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$from, $to])
            ->get(['user_id', 'travel_date', 'intended_departure_time', 'intended_arrival_time'])
            ->groupBy('user_id');

        $recoveryService = app(ExcludedSlotPunchRecovery::class);
        $results = [];

        // recover() itself does real per-employee work (an AttendanceLog
        // fetch plus a fresh grouping/matching pass) that can't be batched
        // across employees without changing that shared class - a real
        // mass event (e.g. a company-wide Work Suspension explaining
        // hundreds of employees' punches at once, confirmed against real
        // dev data: 407 of 421 candidates) can still add up. Cap how many
        // calls this request will make so the page always returns in
        // bounded time regardless of how large a future event is - the
        // employees beyond the cap simply stay listed as unresolved rather
        // than being explained, the same safe "don't hide when uncertain"
        // fallback used throughout ExcludedSlotPunchRecovery itself.
        $recoverCallBudget = 200;

        foreach ($candidates->groupBy('employee_id') as $employeeId => $rows) {
            if ($recoverCallBudget <= 0) {
                break;
            }

            $employee = $employeesById->get($employeeId);
            if (! $employee) {
                // No employee relation to correlate coverage against (a
                // deleted/orphaned user) - can't determine an explanation,
                // so leave these rows visible rather than guessing.
                continue;
            }

            // Only the specific dates this employee actually has an
            // unmatched punch on - not the whole requested range - so an
            // excuse/suspension/locator elsewhere in the window never drags
            // an otherwise-unrelated employee into a recover() call. This is
            // what actually keeps this bounded: 421 employees each have a
            // flagged date, but only a handful of those dates genuinely
            // coincide with a real exclusion for that same employee.
            $flaggedDates = $rows->map(fn (Dtr $d) => $d->date->toDateString())->unique()->all();

            $excludedSlotsByDate = [];

            foreach ($excusesByEmployee->get($employeeId, []) as $excuse) {
                $dateStr = Carbon::parse($excuse->date)->format('Y-m-d');
                if (! in_array($dateStr, $flaggedDates, true)) {
                    continue;
                }
                if (($keys = $excuse->excludedSlotKeys()) !== []) {
                    $excludedSlotsByDate[$dateStr] = array_merge($excludedSlotsByDate[$dateStr] ?? [], array_fill_keys($keys, null));
                }
            }

            if (! $employee->isFrontlineExempt()) {
                foreach ($suspensions as $suspension) {
                    $dateStr = Carbon::parse($suspension->suspension_date)->format('Y-m-d');
                    if (! in_array($dateStr, $flaggedDates, true)) {
                        continue;
                    }
                    $schedule = WorkSchedule::forUserOnDate($employee, Carbon::parse($dateStr));
                    [, $slots] = $schedule->applySuspension($suspension->suspension_time);
                    if ($slots !== []) {
                        $excludedSlotsByDate[$dateStr] = array_merge($excludedSlotsByDate[$dateStr] ?? [], $slots);
                    }
                }
            }

            foreach ($locatorsByEmployee->get($employeeId, []) as $locator) {
                $dateStr = Carbon::parse($locator->travel_date)->format('Y-m-d');
                if (! in_array($dateStr, $flaggedDates, true)) {
                    continue;
                }
                $schedule = WorkSchedule::forUserOnDate($employee, Carbon::parse($dateStr));
                $keys = Locator::coveredSlotKeys(
                    (string) $locator->intended_departure_time,
                    (string) $locator->intended_arrival_time,
                    $schedule
                );
                if ($keys !== []) {
                    $excludedSlotsByDate[$dateStr] = array_merge($excludedSlotsByDate[$dateStr] ?? [], array_fill_keys($keys, null));
                }
            }

            if ($excludedSlotsByDate === []) {
                continue;
            }

            // Narrow the range recover() re-fetches AttendanceLog/Dtr over to
            // just around the flagged date(s) instead of the full requested
            // window - mirrors the 3-day-back/1-day-forward pad
            // recomputeUnmatched() already uses for the same reason
            // (ShiftPunchGrouper only ever folds a punch backward).
            $recoverFrom = Carbon::parse(min($flaggedDates))->subDays(3)->toDateString();
            $recoverTo = Carbon::parse(max($flaggedDates))->addDay()->toDateString();

            // $rows already IS this employee's own Dtr rows for exactly the
            // flagged dates recover() will look at - passing it through
            // skips recover()'s own equivalent internal query.
            $preloadedDtrRows = $rows->keyBy(fn (Dtr $d) => $d->date->toDateString());

            $results[$employeeId] = $recoveryService->recover(
                $employee, $recoverFrom, $recoverTo, $excludedSlotsByDate, $preloadedDtrRows
            );
            $recoverCallBudget--;
        }

        return $results;
    }

    /**
     * True only when EVERY entry in $row->unmatched_logs is accounted for by
     * a recovered value on that same date - a partially-explained day still
     * has a genuine unexplained straggler and must stay visible.
     *
     * @param  array<int, array<string, array<string, string>>>  $recoveredByEmployeeDate
     */
    private function isFullyExplained(Dtr $row, array $recoveredByEmployeeDate): bool
    {
        $recoveredValues = array_values($recoveredByEmployeeDate[$row->employee_id][$row->date->toDateString()] ?? []);

        if ($recoveredValues === [] || empty($row->unmatched_logs)) {
            return false;
        }

        foreach ($row->unmatched_logs as $time) {
            if (! in_array($time, $recoveredValues, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Defaults to the last 30 days - dtrs.unmatched_logs/status carry no
     * index (see the query above), so this stays a bounded scan by design.
     * Malformed query-string dates fall back to the default instead of
     * raising a validation error: this is a GET filter on a page that also
     * renders the unrelated Pull Logs form and Check Raw Biometric Feed tool
     * - one bad querystring value must not break the whole page.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveUnmatchedDateRange(Request $request): array
    {
        $to = $this->safeParseDate($request->query('unmatched_to')) ?? Carbon::today();
        $from = $this->safeParseDate($request->query('unmatched_from')) ?? $to->copy()->subDays(29);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function safeParseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date', 'before_or_equal:to_date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'dept_id' => ['nullable', 'integer', 'exists:departments,Dept_id'],
        ]);

        $deptId = isset($validated['dept_id']) ? (int) $validated['dept_id'] : null;

        foreach (CarbonPeriod::create($validated['from_date'], $validated['to_date']) as $date) {
            $day = $date->toDateString();
            ImportAttendanceLogsJob::dispatch($day, $day, $request->user()->id, $deptId);
        }

        $deptLabel = $deptId
            ? (Department::find($deptId)?->Dept_name ?? "Department #{$deptId}")
            : 'All Departments';

        $fromFormatted = Carbon::parse($validated['from_date'])->format('M j, Y');
        $toFormatted = Carbon::parse($validated['to_date'])->format('M j, Y');
        $dayCount = Carbon::parse($validated['from_date'])->diffInDays($validated['to_date']) + 1;

        return redirect()
            ->route('hr-manager.attendance.import')
            ->with('success', "Attendance import queued: {$fromFormatted} to {$toFormatted} - {$deptLabel}. {$dayCount} job(s) dispatched. Results will be recorded in the audit log.");
    }

    /**
     * Diagnostic tool: check whether the biometric integration API has any
     * record at all for one employee on one date, bypassing the import
     * pipeline entirely. Answers "is this a matching bug in our app, or does
     * the source system genuinely have nothing for this person that day?"
     * without needing a developer to script it. Read-only against both the
     * external API and our own database - never writes anything.
     */
    public function checkEmployeeOnDate(
        Request $request,
        IntegrationApiService $integrationApi,
        PersonnelLogImportService $importService,
    ): JsonResponse {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $date = $validated['date'];

        if (empty($user->EmpNo)) {
            return response()->json(['error' => 'This employee has no EmpNo set - the import can never match their punches.'], 422);
        }

        try {
            $token = $integrationApi->getToken();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Reuse the exact same lookup maps and resolution algorithm the real
        // import uses (including its collision-avoidance guard) rather than a
        // separate reimplementation here, so this tool's verdict can never
        // silently drift out of sync with what a real import would actually do.
        [$exactMap, $strippedMap] = $importService->buildEmpNoLookupMaps();

        $firstName = strtoupper(trim((string) $user->first_name));
        $lastName = strtoupper(trim((string) $user->last_name));

        $matchedById = [];
        $matchedByNameOnly = [];
        $totalRecords = 0;
        $pages = 0;
        $start = 0;
        $pageSize = (int) config('integration.logs_page_size', 1000);

        do {
            [$logsData, $httpStatus] = $integrationApi->fetchBulkLogs($token, $date, $date, $start, $pageSize);

            if ($httpStatus !== 200) {
                return response()->json(['error' => "Biometric API returned HTTP {$httpStatus} while checking this date."], 502);
            }

            $pages++;
            $totalRecords += count($logsData);

            foreach ($logsData as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemPid = (string) ($item['personnelid'] ?? '');
                $resolvedUser = $importService->resolveUserForPersonnelId($itemPid, $exactMap, $strippedMap);

                if ($resolvedUser !== null && $resolvedUser->id === $user->id) {
                    $matchedById[] = $item;

                    continue;
                }

                // Resolves to nobody, or to a DIFFERENT user than the one being
                // checked (e.g. this id genuinely belongs to someone else) -
                // either way, a name match here means a genuine EmpNo problem
                // worth surfacing, distinct from a true no-record gap.
                $itemFirst = strtoupper(trim((string) ($item['personnelfirstname'] ?? '')));
                $itemLast = strtoupper(trim((string) ($item['personnellastname'] ?? '')));
                $isNameMatch = ($lastName !== '' && $itemLast === $lastName)
                    || ($firstName !== '' && $itemFirst === $firstName);

                if ($isNameMatch) {
                    $matchedByNameOnly[] = $item;
                }
            }

            $start += $pageSize;
        } while (count($logsData) >= $pageSize && $pages < 10);

        $alreadyImported = AttendanceLog::where('user_id', $user->id)
            ->where('logdate', $date)
            ->orderBy('logtime')
            ->get(['logtime', 'in_out'])
            ->map(fn ($log) => ['logtime' => $log->logtime, 'in_out' => $log->in_out]);

        return response()->json([
            'employee_name' => trim("{$user->first_name} {$user->last_name}"),
            'emp_no' => $user->EmpNo,
            'date' => $date,
            'pages_checked' => $pages,
            'total_records_that_day' => $totalRecords,
            'matched_by_id' => array_values($matchedById),
            'matched_by_name_different_id' => array_values($matchedByNameOnly),
            'already_in_attendance_logs' => $alreadyImported,
        ]);
    }

    /**
     * Self-serve fix for a DTR row whose unmatched_logs holds a raw punch
     * that was never placed into any slot - see ShiftPunchGrouper's class
     * docblock for the bug class this catches. whereJsonLength() above is a
     * structural fingerprint, not a check tied to one specific bug, so this
     * also catches a future, differently-caused stranding.
     *
     * Recomputes only a bounded window around the flagged date, not the
     * employee's full history (recomputeFullRange()) - cheap enough for an
     * HTTP request, and correct for the common case: ShiftPunchGrouper's
     * shiftDateFor() only ever folds a punch BACK onto an earlier calendar
     * day, never forward, so a stranded punch's true home is always on or
     * before the date it landed on. The 3-day back-pad isn't just margin -
     * PersonnelLogImportService::upsertDtrRecords() only WRITES a corrected
     * dtrs row for a date inside the [from, to] this method passes it (it
     * already pads what it FETCHES by 1 day on each side regardless), so a
     * too-narrow window here would silently fail to persist a fix the
     * grouper actually found. A rare pathological case (a 24-hour shift
     * separated by several consecutive rest days) can still fold back
     * further than this window reaches - if recompute doesn't clear it, the
     * flash message below says so rather than claiming success.
     */
    public function recomputeUnmatched(Request $request, PersonnelLogImportService $importService): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
        ]);

        $employeeId = (int) $validated['employee_id'];
        $flaggedDate = Carbon::parse($validated['date']);

        // Re-derive the flag from live data rather than trusting the
        // submitted params blindly.
        $dtr = Dtr::where('employee_id', $employeeId)
            ->whereDate('date', $flaggedDate)
            ->first();

        if (! $dtr || empty($dtr->unmatched_logs)) {
            return back()->with('error', 'This DTR row no longer has any unmatched punches - nothing to recompute.');
        }

        $user = User::findOrFail($employeeId);
        $before = count($dtr->unmatched_logs);

        $from = $flaggedDate->copy()->subDays(3)->toDateString();
        $to = $flaggedDate->copy()->addDay()->toDateString();

        $importService->recomputeDtr($user, $from, $to);

        $fresh = Dtr::where('employee_id', $employeeId)->whereDate('date', $flaggedDate)->first();
        $after = $fresh ? count($fresh->unmatched_logs ?? []) : 0;
        $resolved = $after === 0;

        try {
            HRAuditTrail::create([
                'actor_user_id' => $request->user()->id,
                'module' => 'attendance',
                'action' => 'dtr_unmatched_recomputed',
                'target_type' => 'dtr',
                'target_id' => $dtr->id,
                'details' => [
                    'employee_id' => $employeeId,
                    'flagged_date' => $flaggedDate->toDateString(),
                    'recompute_from' => $from,
                    'recompute_to' => $to,
                    'unmatched_before' => $before,
                    'unmatched_after' => $after,
                    'resolved' => $resolved,
                ],
            ]);
        } catch (\Exception) {
            // Audit failure must not block the already-completed recompute.
        }

        $employeeName = trim("{$user->last_name}, {$user->first_name}");
        $dateLabel = $flaggedDate->format('M j, Y');

        if (! $resolved) {
            return back()->with('error', "Recomputed {$employeeName}'s DTR ({$from} to {$to}), but {$after} punch(es) are still unresolved on {$dateLabel} - may need manual review (see Check Raw Biometric Feed above, or a wider dtr:recompute).");
        }

        return back()->with('success', "Recomputed {$employeeName}'s DTR ({$from} to {$to}) - the unmatched punch on {$dateLabel} is now resolved.");
    }
}
