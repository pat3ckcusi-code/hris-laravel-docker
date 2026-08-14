<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HabitualViolationNotice;
use App\Models\HRAuditTrail;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\AttendanceMonitoringExportService;
use App\Support\HabitualPatternRule;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    private const VIOLATION_LABELS = [
        HabitualViolationNotice::VIOLATION_TARDY => 'Habitual Tardiness',
        HabitualViolationNotice::VIOLATION_UNDERTIME => 'Frequent Undertime',
    ];

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
        $violationSort = $request->input('violation_sort', 'name_asc');
        if (! in_array($violationSort, ['name_asc', 'name_desc', 'count_desc', 'count_asc'], true)) {
            $violationSort = 'name_asc';
        }

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

        $violations = $this->buildHabitualViolations($year, $deptId, $employeeType, $violationSort);
        if ($violationSearch !== '') {
            $violations = $violations->filter(function (array $v) use ($violationSearch) {
                $name = $v['employee'] ? trim("{$v['employee']->last_name}, {$v['employee']->first_name}") : '';

                return stripos($name, $violationSearch) !== false;
            })->values();
        }
        $violations = $this->paginateCollection($request, $violations, 15, 'violation_page');

        return view('attendance.time-logs-monitoring.index', compact(
            'departments', 'deptId', 'employeeType', 'month', 'year', 'deptSearch', 'violationSearch', 'violationSort',
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
     * @param  string  $sort  'name_asc'|'name_desc'|'count_desc'|'count_asc'
     * @return Collection<int, array> employees who cross the habitual threshold this year
     */
    private function buildHabitualViolations(int $year, ?int $deptId, ?string $employeeType, string $sort = 'name_asc'): Collection
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

        // Bulk-load this year's notices for every candidate employee in one
        // query, keyed by "employeeId:violationType", so the per-employee
        // loop below does zero additional queries.
        $notices = HabitualViolationNotice::whereIn('employee_id', $monthly->keys())
            ->where('year', $year)
            ->with('issuer:id,first_name,last_name,name')
            ->get()
            ->keyBy(fn (HabitualViolationNotice $n) => "{$n->employee_id}:{$n->violation_type}");

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
                'tardy_notice' => $habitualTardy
                    ? $notices->get("{$employeeId}:".HabitualViolationNotice::VIOLATION_TARDY)
                    : null,
                'undertime_notice' => $frequentUndertime
                    ? $notices->get("{$employeeId}:".HabitualViolationNotice::VIOLATION_UNDERTIME)
                    : null,
            ]);
        }

        $violations = match ($sort) {
            'name_desc' => $violations->sortByDesc(fn ($v) => $v['employee']?->last_name),
            'count_desc' => $violations->sortByDesc(fn ($v) => $v['tardy_months']->count() + $v['undertime_months']->count()),
            'count_asc' => $violations->sortBy(fn ($v) => $v['tardy_months']->count() + $v['undertime_months']->count()),
            default => $violations->sortBy(fn ($v) => $v['employee']?->last_name), // name_asc
        };
        $violations = $violations->values();

        if ($violations->isEmpty()) {
            return $violations;
        }

        // Only the employees already identified as flagged need their raw
        // per-date rows loaded - month chips just need which specific days
        // behind each month's threshold, not a company-wide date scan.
        $flaggedIds = $violations->pluck('employee.id')->filter()->values();

        $violationDates = DB::table('dtrs')
            ->whereIn('employee_id', $flaggedIds)
            ->whereYear('date', $year)
            ->where(function ($q) {
                $q->where('late_minutes', '>', 0)->orWhere('undertime_minutes', '>', 0);
            })
            ->select('employee_id', 'date', 'late_minutes', 'undertime_minutes')
            ->orderBy('date')
            ->get()
            ->groupBy('employee_id');

        return $violations->map(function (array $v) use ($violationDates) {
            $rows = $v['employee'] ? $violationDates->get($v['employee']->id, collect()) : collect();

            $v['tardy_dates_by_month'] = $rows->where('late_minutes', '>', 0)
                ->groupBy(fn ($r) => (int) \Carbon\Carbon::parse($r->date)->format('n'))
                ->map(fn ($g) => $g->map(fn ($r) => [
                    'date' => \Carbon\Carbon::parse($r->date)->format('M j, Y'),
                    'minutes' => (int) $r->late_minutes,
                ])->values());

            $v['undertime_dates_by_month'] = $rows->where('undertime_minutes', '>', 0)
                ->groupBy(fn ($r) => (int) \Carbon\Carbon::parse($r->date)->format('n'))
                ->map(fn ($g) => $g->map(fn ($r) => [
                    'date' => \Carbon\Carbon::parse($r->date)->format('M j, Y'),
                    'minutes' => (int) $r->undertime_minutes,
                ])->values());

            return $v;
        });
    }

    /**
     * @return Collection<int, int> sorted month numbers where $column crossed
     *                               the habitual monthly threshold for one
     *                               employee/year - the single-employee
     *                               equivalent of buildHabitualViolations()'s
     *                               per-employee computation, used to
     *                               re-verify eligibility server-side before
     *                               issuing a notice (never trust a
     *                               client-submitted employee/type/year on
     *                               its own).
     */
    private function violationMonthsFor(int $employeeId, int $year, string $column): Collection
    {
        return DB::table('dtrs')
            ->where('employee_id', $employeeId)
            ->whereYear('date', $year)
            ->selectRaw("MONTH(date) as mo, SUM(CASE WHEN {$column} > 0 THEN 1 ELSE 0 END) as violation_days")
            ->groupBy('mo')
            ->havingRaw('violation_days >= ?', [self::HABITUAL_MONTHLY_THRESHOLD])
            ->pluck('mo')
            ->map(fn ($m) => (int) $m)
            ->sort()
            ->values();
    }

    private static function ordinal(int $n): string
    {
        return match ($n) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => "{$n}th", // unreachable given the mod-3 cycle; defensive only
        };
    }

    /**
     * Issue the next sequential CSC habitual-violation notice (1st -> 2nd ->
     * 3rd -> wraps back to 1st, a continuous lifetime cycle per employee +
     * violation type, never reset by calendar year) against an employee
     * currently flagged as habitual_tardy or frequent_undertime for the
     * given year. At most one notice per (employee, violation_type, year).
     */
    public function issueNotice(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'violation_type' => ['required', 'string', Rule::in(HabitualViolationNotice::VALID_VIOLATION_TYPES)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $employeeId = (int) $validated['employee_id'];
        $violationType = $validated['violation_type'];
        $year = (int) $validated['year'];

        // Re-derive the flag from live DTR data - buildHabitualViolations()'s
        // output is never persisted, and this endpoint must not trust a
        // client-supplied employee_id/violation_type/year on faith alone.
        $column = $violationType === HabitualViolationNotice::VIOLATION_TARDY ? 'late_minutes' : 'undertime_minutes';
        $months = $this->violationMonthsFor($employeeId, $year, $column);

        if (! HabitualPatternRule::meets($months)) {
            return back()->with('error', 'This employee does not meet the habitual violation threshold for the selected year - notice not issued.');
        }

        try {
            $notice = DB::transaction(function () use ($employeeId, $violationType, $year, $actor) {
                // Lock the employee's own users row as a synchronization
                // point - there's no natural per-(employee, violation_type)
                // row to lock before the first notice exists. This
                // serializes concurrent issuances for this employee so the
                // count() inside nextOffenseNumber() is never read from a
                // stale/racing state.
                User::where('id', $employeeId)->lockForUpdate()->firstOrFail();

                $offenseNumber = HabitualViolationNotice::nextOffenseNumber($employeeId, $violationType);

                return HabitualViolationNotice::create([
                    'employee_id' => $employeeId,
                    'violation_type' => $violationType,
                    'year' => $year,
                    'offense_number' => $offenseNumber,
                    'issued_by' => $actor->id,
                ]);
            });
        } catch (QueryException $e) {
            // Defense in depth: the lock above should already prevent this,
            // but fall back cleanly on the DB unique constraint (MySQL 1062)
            // rather than a raw 500 if it's ever hit anyway.
            if ((string) $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return back()->with('error', 'A notice for this employee and violation type has already been issued for this year.');
            }
            throw $e;
        }

        $sanction = HabitualViolationNotice::OFFENSE_SANCTIONS[$notice->offense_number];
        $legalBasis = HabitualViolationNotice::LEGAL_BASIS[$violationType];

        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'disciplinary_notice',
                'action' => 'notice_issued',
                'target_type' => 'habitual_violation_notice',
                'target_id' => $notice->id,
                'details' => [
                    'employee_id' => $employeeId,
                    'violation_type' => $violationType,
                    'year' => $year,
                    'offense_number' => $notice->offense_number,
                    'sanction' => $sanction,
                    'legal_basis' => $legalBasis,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block the already-recorded notice
        }

        $employee = User::find($employeeId);
        if ($employee) {
            try {
                $employee->notify(new HrisTransactionNotification(
                    requestType: self::VIOLATION_LABELS[$violationType],
                    status: self::ordinal($notice->offense_number).' Offense - '.$sanction,
                    details: [
                        'Violation Type' => self::VIOLATION_LABELS[$violationType],
                        'Offense' => self::ordinal($notice->offense_number).' Offense',
                        'Sanction' => $sanction,
                        'Year' => (string) $year,
                        'Legal Basis' => $legalBasis,
                    ],
                    actor: $actor->name,
                ));
            } catch (\Exception) {
                // notification failure must not block the already-recorded notice
            }
        }

        return back()->with('success', self::VIOLATION_LABELS[$violationType].' notice issued - '.self::ordinal($notice->offense_number).' offense ('.$sanction.') recorded.');
    }
}
