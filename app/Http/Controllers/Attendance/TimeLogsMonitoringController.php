<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\AttendanceMonitoringExportService;
use App\Support\HabitualPatternRule;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Time Keeper / HR Manager company-wide screen for monitoring lateness and
 * undertime: which department has the most this month/year, and which
 * employees cross the CSC MC No. 04, s. 1991 "Habitual Tardiness" threshold
 * (late 10+ times in a month, in at least 2 months within a semester or 2
 * consecutive months). The same threshold is mirrored for undertime at the
 * user's request, labeled "Frequent Undertime" since CSC does not define a
 * separate official category for it.
 *
 * Also owns a Monitoring Matrix action (unlike Department Head/Administrative
 * Officer's own dept-scoped Monitoring Matrix, this one lets Time Keeper/HR
 * Manager browse any single department via a picker, since they aren't
 * scoped to a home department).
 */
class TimeLogsMonitoringController extends Controller
{
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    /** CSC MC No. 04, s. 1991: 10+ late instances in a calendar month is a violation month. */
    private const HABITUAL_MONTHLY_THRESHOLD = 10;

    public function __construct(private readonly AttendanceMonitoringExportService $monitoringExportService) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function monitoringMatrix(Request $request): View
    {
        $this->authorizeManager($request->user());

        $departments = Department::orderBy('Dept_name')->get();

        $departmentId = $request->integer('department_id') ?: ($departments->first()->Dept_id ?? null);
        $dept = $departments->firstWhere('Dept_id', $departmentId);

        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $rows = $dept
            ? $this->monitoringExportService->getRows(collect([$dept]), $month, $year)
            : collect();

        return view('attendance.monitoring-matrix', compact('departments', 'dept', 'departmentId', 'month', 'year', 'rows'));
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $deptId = $request->integer('dept_id') ?: null;
        $employeeType = $request->input('employee_type') ?: null;
        $month = (int) $request->query('month', (int) now()->month);
        $year = (int) $request->query('year', (int) now()->year);
        $deptSearch = trim((string) $request->query('dept_search', ''));
        $violationSearch = trim((string) $request->query('violation_search', ''));

        if ($month < 1 || $month > 12) {
            $month = (int) now()->month;
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) now()->year;
        }

        $departments = Department::orderBy('Dept_name')->get();

        $deptRanking = $this->buildDepartmentRanking($month, $year, $deptId, $employeeType);
        if ($deptSearch !== '') {
            $deptRanking = $deptRanking->filter(
                fn ($r) => stripos($r->dept_name, $deptSearch) !== false
            )->values();
        }
        $deptRanking = $this->paginateCollection($request, $deptRanking, 10, 'dept_page');

        $tardinessBreakdown = $this->buildBreakdown('late_minutes', $month, $year, $deptId, $employeeType);
        $undertimeBreakdown = $this->buildBreakdown('undertime_minutes', $month, $year, $deptId, $employeeType);

        $violations = $this->buildHabitualViolations($year, $deptId, $employeeType);
        if ($violationSearch !== '') {
            $violations = $violations->filter(function (array $v) use ($violationSearch) {
                $name = $v['employee'] ? trim("{$v['employee']->last_name}, {$v['employee']->first_name}") : '';

                return stripos($name, $violationSearch) !== false;
            })->values();
        }
        $violations = $this->paginateCollection($request, $violations, 15, 'violation_page');

        return view('attendance.time-logs-monitoring.index', compact(
            'departments', 'deptId', 'employeeType', 'month', 'year', 'deptSearch', 'violationSearch',
            'deptRanking', 'tardinessBreakdown', 'undertimeBreakdown', 'violations'
        ));
    }

    private function paginateCollection(Request $request, Collection $items, int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $pageName]
        );
    }

    /**
     * Ranked from the Department table down (LEFT JOINs), so every department
     * shows up even with zero DTR activity this month, and "Employees" is the
     * department's actual headcount rather than just the employees who
     * happened to have a DTR row this month.
     *
     * @return Collection<int, object> departments ranked by combined tardiness
     *                                 + undertime day-counts for the month, worst first
     */
    private function buildDepartmentRanking(int $month, int $year, ?int $deptId, ?string $employeeType): Collection
    {
        $rows = DB::table('departments')
            ->when($deptId, fn ($q) => $q->where('departments.Dept_id', $deptId))
            ->leftJoin('users', function ($join) use ($employeeType) {
                $join->on('users.Dept_id', '=', 'departments.Dept_id');
                if ($employeeType) {
                    $join->where('users.employee_type', $employeeType);
                }
            })
            ->leftJoin('dtrs', function ($join) use ($month, $year) {
                $join->on('dtrs.employee_id', '=', 'users.id')
                    ->whereYear('dtrs.date', $year)
                    ->whereMonth('dtrs.date', $month);
            })
            ->selectRaw('departments.Dept_id as dept_id, departments.Dept_name as dept_name,
                COUNT(DISTINCT users.id) as employee_count,
                SUM(CASE WHEN dtrs.late_minutes > 0 THEN 1 ELSE 0 END) as tardiness_count,
                SUM(CASE WHEN dtrs.undertime_minutes > 0 THEN 1 ELSE 0 END) as undertime_count')
            ->groupBy('departments.Dept_id', 'departments.Dept_name')
            ->get();

        return $rows->sortByDesc(fn ($r) => $r->tardiness_count + $r->undertime_count)->values();
    }

    /**
     * @param  string  $column  'late_minutes' or 'undertime_minutes'
     * @return Collection<int, Collection<int, array>> employees with at least
     *                                                 one affected day this month, keyed by dept_id, worst first
     */
    private function buildBreakdown(string $column, int $month, int $year, ?int $deptId, ?string $employeeType): Collection
    {
        $rows = DB::table('dtrs')
            ->join('users', 'users.id', '=', 'dtrs.employee_id')
            ->whereYear('dtrs.date', $year)
            ->whereMonth('dtrs.date', $month)
            ->where("dtrs.{$column}", '>', 0)
            ->when($deptId, fn ($q) => $q->where('users.Dept_id', $deptId))
            ->when($employeeType, fn ($q) => $q->where('users.employee_type', $employeeType))
            ->selectRaw('users.Dept_id as dept_id, dtrs.employee_id,
                users.first_name, users.last_name,
                COUNT(*) as day_count')
            ->groupBy('users.Dept_id', 'dtrs.employee_id', 'users.first_name', 'users.last_name')
            ->get();

        return $rows->groupBy('dept_id')->map(fn ($deptRows) => $deptRows
            ->map(fn ($r) => [
                'name' => trim("{$r->last_name}, {$r->first_name}"),
                'days' => (int) $r->day_count,
            ])
            ->sortByDesc('days')
            ->values());
    }

    /**
     * @return Collection<int, array> employees who cross the habitual threshold this year
     */
    private function buildHabitualViolations(int $year, ?int $deptId, ?string $employeeType): Collection
    {
        $monthly = DB::table('dtrs')
            ->join('users', 'users.id', '=', 'dtrs.employee_id')
            ->whereYear('dtrs.date', $year)
            ->when($deptId, fn ($q) => $q->where('users.Dept_id', $deptId))
            ->when($employeeType, fn ($q) => $q->where('users.employee_type', $employeeType))
            ->selectRaw('dtrs.employee_id, MONTH(dtrs.date) as mo,
                SUM(CASE WHEN dtrs.late_minutes > 0 THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN dtrs.undertime_minutes > 0 THEN 1 ELSE 0 END) as undertime_days')
            ->groupBy('dtrs.employee_id', 'mo')
            ->get()
            ->groupBy('employee_id');

        $employees = User::whereIn('id', $monthly->keys())
            ->with('department')
            ->get(['id', 'first_name', 'last_name', 'Dept_id'])
            ->keyBy('id');

        $violations = collect();

        foreach ($monthly as $employeeId => $months) {
            $tardyMonths = $months->where('late_days', '>=', self::HABITUAL_MONTHLY_THRESHOLD)
                ->pluck('mo')->map(fn ($m) => (int) $m)->sort()->values();
            $undertimeMonths = $months->where('undertime_days', '>=', self::HABITUAL_MONTHLY_THRESHOLD)
                ->pluck('mo')->map(fn ($m) => (int) $m)->sort()->values();

            $habitualTardy = HabitualPatternRule::meets($tardyMonths);
            $frequentUndertime = HabitualPatternRule::meets($undertimeMonths);

            if (! $habitualTardy && ! $frequentUndertime) {
                continue;
            }

            $violations->push([
                'employee' => $employees->get($employeeId),
                'tardy_months' => $tardyMonths,
                'undertime_months' => $undertimeMonths,
                'habitual_tardy' => $habitualTardy,
                'frequent_undertime' => $frequentUndertime,
            ]);
        }

        return $violations->sortBy(fn ($v) => $v['employee']?->last_name)->values();
    }
}
