<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\DepartmentService;
use App\Services\WorkforceCalendarService;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Who's away" planning calendar: per-date counts of employees on Leave, ETA,
 * Locator, Travel Order, or Office Order for one department/one month at a
 * time. Time Keeper/HR Manager can browse any department; Department Head/
 * Administrative Officer (real or covering via an active OicAssignment, per
 * DepartmentService::resolveEffectiveOfficerRole()) are auto-scoped to their
 * own - same shape as AdministrativeOfficerController::monitoringMatrix().
 */
class WorkforceCalendarController extends Controller
{
    public function __construct(
        private readonly DepartmentService $departmentService,
        private readonly WorkforceCalendarService $calendarService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));

        if (in_array($role, ['time keeper', 'hr manager'], true)) {
            $departments = Department::orderBy('Dept_name')->get();
        } else {
            $officerRole = $this->departmentService->resolveEffectiveOfficerRole($user);
            if ($officerRole === null) {
                abort(403);
            }

            $departments = $officerRole === 'administrative officer'
                ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
                : $this->departmentService->resolveAllDepartmentsForUser($user);
        }

        $requestedId = $request->integer('department_id') ?: ($departments->first()->Dept_id ?? null);
        // Fall back to the caller's own first accessible department rather than a
        // null $dept whenever the requested id isn't in $departments - this is what
        // keeps a Department Head/Administrative Officer from viewing another
        // department's roster by tampering with the query string.
        $dept = $departments->firstWhere('Dept_id', $requestedId) ?? $departments->first();
        $departmentId = $dept->Dept_id ?? null;

        [$month, $year] = $this->resolveMonthYear($request);
        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $employeeIds = $dept ? $this->departmentService->getEmployeeIdsForDepartment($dept) : [];
        $calendarData = $this->calendarService->buildMonthSummary($employeeIds, $monthStart, $monthEnd);

        return view('attendance.workforce-calendar.index', [
            'departments' => $departments,
            'dept' => $dept,
            'departmentId' => $departmentId,
            'month' => $month,
            'year' => $year,
            'monthStart' => $monthStart,
            'calendarData' => $calendarData,
            'categoryLabels' => WorkforceCalendarService::CATEGORY_LABELS,
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveMonthYear(Request $request): array
    {
        $month = (int) $request->query('month', (int) now()->month);
        $year = (int) $request->query('year', (int) now()->year);

        if ($month < 1 || $month > 12) {
            $month = (int) now()->month;
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) now()->year;
        }

        return [$month, $year];
    }
}
