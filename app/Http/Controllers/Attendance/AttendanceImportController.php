<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Jobs\ImportAttendanceLogsJob;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\HRAuditTrail;
use App\Models\Setting;
use App\Models\User;
use App\Services\IntegrationApiService;
use App\Services\PersonnelLogImportService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        // unmatched_logs non-empty is the precise fingerprint of a punch that
        // was never placed into any DTR slot - see ShiftPunchGrouper's class
        // docblock for the bug class this catches. Deliberately NOT keyed off
        // status (missing_out/incomplete are frequently a genuine forgotten
        // punch, not evidence of a grouping bug) - that would bury real
        // problems in noise. dtrs carries no index on unmatched_logs/status
        // (only a unique employee_id+date composite), so this stays bounded
        // by the date range rather than scanning the whole (large, growing)
        // table.
        $unmatchedDtrs = Dtr::query()
            ->whereJsonLength('unmatched_logs', '>', 0)
            ->whereBetween('date', [$unmatchedFrom, $unmatchedTo])
            ->when($unmatchedDeptId, fn ($q) => $q->whereHas(
                'employee', fn ($u) => $u->where('Dept_id', $unmatchedDeptId)
            ))
            ->with([
                'employee:id,first_name,last_name,EmpNo,Dept_id',
                'employee.department:Dept_id,Dept_name',
            ])
            ->orderByDesc('date')
            ->limit(300)
            ->get();

        return view('hr-manager.attendance-import', compact(
            'departments', 'setting', 'empNoCount', 'recentImports',
            'unmatchedDtrs', 'unmatchedFrom', 'unmatchedTo', 'unmatchedDeptId'
        ));
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
