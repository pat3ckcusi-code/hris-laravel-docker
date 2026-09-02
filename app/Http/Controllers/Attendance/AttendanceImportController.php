<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Jobs\ImportAttendanceLogsJob;
use App\Models\AttendanceLog;
use App\Models\Department;
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
    public function index(): View
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

        return view('hr-manager.attendance-import',
            compact('departments', 'setting', 'empNoCount', 'recentImports'));
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
}
