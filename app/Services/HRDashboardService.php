<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Holiday;
use App\Models\HRAuditTrail;
use App\Models\LeaveRequest;
use App\Models\PayrollDetail;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\Pds;
use App\Models\User;
use App\Support\HrisConstants;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * All data-aggregation logic for the HR Manager dashboard.
 *
 * Extracted from HRManagerController to keep the controller thin and make
 * the aggregation logic independently testable.
 */
class HRDashboardService
{
    public function __construct(
        private LeaveRequestService $leaveRequestService,
        private LwopAggregationService $lwopAggregationService,
    ) {}

    // ── Summary cards ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function buildWorkforceCards(): array
    {
        $chartData = $this->buildChartData(null);

        $totalEmployees = User::query()->realEmployee()->count();

        $typeMap = array_combine(
            $chartData['employment_status']['labels'],
            $chartData['employment_status']['values']
        ) ?: [];
        arsort($typeMap);
        $topType = (string) (array_key_first($typeMap) ?? 'N/A');
        $topTypeCount = (int) ($typeMap[$topType] ?? 0);

        $milestoneYears = [10, 15, 20, 25, 30, 35, 40];
        $currentYear = now()->year;
        $hiredYears = array_map(fn ($m) => $currentYear - $m, $milestoneYears);

        $awardRecipientsCount = (int) User::query()
            ->realEmployee()
            ->whereNotNull('date_hired')
            ->whereIn(DB::raw('YEAR(date_hired)'), $hiredYears)
            ->count();

        return [
            'total_employees' => $totalEmployees,
            'award_recipients' => $awardRecipientsCount,
            'top_employee_type' => $topType,
            'top_employee_type_count' => $topTypeCount,
            'sixty_plus_count' => $chartData['sixty_plus_count'],
        ];
    }

    /** @return array<string, int> */
    public function buildSummaryCards(): array
    {
        return Cache::remember('hr_summary_cards', now()->addMinutes(5), function () {
            return [
                'total_requests' => $this->totalRequests(),
                'pending' => $this->countRequestsByBucket('pending'),
                'approved' => $this->countRequestsByBucket('approved'),
                'completed' => $this->countRequestsByBucket('completed'),
            ];
        });
    }

    public function totalRequests(): int
    {
        $total = 0;

        foreach (['leave_requests', 'document_requests', 'eta', 'locators'] as $table) {
            if (Schema::hasTable($table)) {
                $total += (int) DB::table($table)->count();
            }
        }

        return $total;
    }

    public function countRequestsByBucket(string $bucket): int
    {
        $statusMap = [
            'pending' => ['pending', 'requested', 'for recommendation', 'pending recommendation', 'pending approval'],
            'approved' => ['approved', 'recommended'],
            'completed' => ['completed', 'released', 'final / archived'],
        ];

        $statuses = $statusMap[$bucket] ?? [];

        if ($statuses === []) {
            return 0;
        }

        $total = 0;

        if (Schema::hasTable('leave_requests')) {
            $total += (int) DB::table('leave_requests')
                ->where(function ($query) use ($statuses): void {
                    $query->whereIn(DB::raw('LOWER(status)'), $statuses)
                        ->orWhereIn(DB::raw('LOWER(detailed_status)'), $statuses);
                })
                ->count();
        }

        foreach (['document_requests', 'eta', 'locators'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $total += (int) DB::table($table)
                ->whereIn(DB::raw('LOWER(status)'), $statuses)
                ->count();
        }

        return $total;
    }

    // ── Workforce chart data ──────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    public function buildChartData(?int $departmentId, ?string $employeeType = null): array
    {
        $employees = $this->employeeQuery($departmentId, $employeeType)->get([
            'id', 'Dept_id', 'Status', 'employee_type', 'created_at', 'date_hired',
        ]);

        $workforcePerDepartment = $this->workforcePerDepartment($departmentId, $employeeType);
        $typedEmployees = $employees->filter(fn (User $e) => trim((string) ($e->employee_type ?? '')) !== '');
        $totalWorkforce = $this->countByKey($typedEmployees, 'employee_type', 'Unspecified');
        $employmentStatus = $this->countByKey($typedEmployees, 'employee_type', 'Unknown');

        $pdsByUser = $this->pdsByUserId($employees->pluck('id'));

        $genderCounts = ['Male' => 0, 'Female' => 0, 'Not Specified' => 0];
        $ageGroupCounts = ['18-25' => 0, '26-35' => 0, '36-45' => 0, '46-55' => 0, '56+' => 0, 'Unknown' => 0];
        $serviceCounts = [
            '< 10 years' => 0,
            '10-14 years' => 0,
            '15-19 years' => 0,
            '20-24 years' => 0,
            '25-29 years' => 0,
            '30-34 years' => 0,
            '35-39 years' => 0,
            '40+ years' => 0,
        ];
        $sixtyPlusCount = 0;

        foreach ($employees as $employee) {
            $pds = $pdsByUser->get($employee->id, []);

            $gender = $this->extractGender($pds);
            $genderCounts[$gender] = ($genderCounts[$gender] ?? 0) + 1;

            $ageBucket = $this->extractAgeBucket($pds);
            $ageGroupCounts[$ageBucket] = ($ageGroupCounts[$ageBucket] ?? 0) + 1;

            $empAge = $this->extractAge($pds);
            if ($empAge !== null && $empAge >= 60) {
                $sixtyPlusCount++;
            }

            if (! empty($employee->date_hired)) {
                try {
                    $yearsOfService = Carbon::parse($employee->date_hired)->diffInYears(now());
                } catch (\Throwable) {
                    $yearsOfService = $this->extractYearsOfService($employee->created_at, $pds);
                }
            } else {
                $yearsOfService = $this->extractYearsOfService($employee->created_at, $pds);
            }

            $serviceBucket = $this->serviceBucket($yearsOfService);
            $serviceCounts[$serviceBucket] = ($serviceCounts[$serviceBucket] ?? 0) + 1;
        }

        return [
            'workforce_per_department' => $workforcePerDepartment,
            'total_workforce' => $this->barChartFromAssoc($totalWorkforce),
            'gender_distribution' => $this->pieChartFromAssoc($genderCounts),
            'employment_status' => $this->pieChartFromAssoc($employmentStatus),
            'age_group_distribution' => $this->barChartFromAssoc($ageGroupCounts),
            'length_of_service' => $this->barChartFromAssoc($serviceCounts),
            'sixty_plus_count' => $sixtyPlusCount,
        ];
    }

    /** @return Builder<User> */
    private function employeeQuery(?int $departmentId, ?string $employeeType = null): Builder
    {
        $query = User::query()->realEmployee();

        if ($departmentId !== null) {
            $query->where('Dept_id', $departmentId);
        }

        if ($employeeType !== null) {
            $query->where('employee_type', $employeeType);
        }

        return $query;
    }

    /** @return array{labels: array<int, string>, datasets: array<int, array{label: string, data: array<int, int>}>} */
    private function workforcePerDepartment(?int $departmentId, ?string $employeeType = null): array
    {
        $q = Department::query()
            ->select('departments.Dept_name')
            ->selectRaw("TRIM(COALESCE(users.employee_type, '')) as employee_type")
            ->selectRaw('COUNT(users.id) as total')
            ->leftJoin('users', function ($join) use ($employeeType): void {
                $join->on('users.Dept_id', '=', 'departments.Dept_id')
                    ->where(function ($statusQuery): void {
                        $statusQuery->where('users.Status', User::STATUS_ACTIVE)
                            ->orWhereNull('users.Status')
                            ->orWhere('users.Status', '');
                    })
                    ->where('users.email', 'not like', '%@example.com');

                if ($employeeType !== null) {
                    $join->where('users.employee_type', $employeeType);
                }
            })
            ->groupBy('departments.Dept_id', 'departments.Dept_name', 'users.employee_type')
            ->orderBy('departments.Dept_name');

        if ($departmentId !== null) {
            $q->where('departments.Dept_id', $departmentId);
        }

        $rows = $q->get();

        // Employees whose Dept_id is null (or points at a deleted/invalid department) never
        // match the join above and would otherwise silently vanish from the chart and the
        // "Total Employees" card. Surface them under an explicit 'Unassigned' bucket.
        if ($departmentId === null) {
            $unassignedQuery = User::query()
                ->realEmployee()
                ->selectRaw("TRIM(COALESCE(employee_type, '')) as employee_type")
                ->selectRaw('COUNT(*) as total')
                ->where(function ($w): void {
                    $w->whereNull('Dept_id')->orWhereNotIn('Dept_id', Department::query()->select('Dept_id'));
                });

            if ($employeeType !== null) {
                $unassignedQuery->where('employee_type', $employeeType);
            }

            $unassigned = $unassignedQuery
                ->groupBy('employee_type')
                ->get()
                ->map(fn ($r) => (object) ['Dept_name' => 'Unassigned', 'employee_type' => $r->employee_type, 'total' => $r->total]);

            if ($unassigned->isNotEmpty()) {
                $rows = $rows->concat($unassigned);
            }
        }

        $deptNames = $rows->pluck('Dept_name')->unique()->values()->all();

        $isKnownType = fn (string $type): bool => in_array($type, HrisConstants::EMPLOYEE_TYPES, true);

        // Canonical order first so colors/legend stay stable across "all departments" and
        // single-department views, then bucket any non-blank, non-canonical value as
        // 'Unspecified' (a real data-quality signal). Blank employee_type — e.g. an
        // account with no type set, such as an internal dev/test account — is excluded
        // entirely rather than bucketed, so it's never counted here or in "Total Employees".
        $types = collect(HrisConstants::EMPLOYEE_TYPES)
            ->filter(fn ($t) => $rows->contains(fn ($r) => $r->employee_type === $t))
            ->values();

        $unspecifiedTotal = $rows
            ->filter(fn ($r) => $r->employee_type !== '' && ! $isKnownType($r->employee_type))
            ->sum('total');

        if ($unspecifiedTotal > 0) {
            $types->push('Unspecified');
        }

        $datasets = $types->map(function (string $type) use ($rows, $deptNames, $isKnownType) {
            $byDept = $rows
                ->filter(fn ($r) => $type === 'Unspecified'
                    ? ($r->employee_type !== '' && ! $isKnownType($r->employee_type))
                    : $r->employee_type === $type)
                ->groupBy('Dept_name')
                ->map(fn ($g) => (int) $g->sum('total'));

            return [
                'label' => $type,
                'data' => array_map(fn ($d) => $byDept[$d] ?? 0, $deptNames),
            ];
        })->values()->all();

        return ['labels' => $deptNames, 'datasets' => $datasets];
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, array<string, mixed>>
     */
    public function pdsByUserId(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return Pds::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->mapWithKeys(fn (Pds $pds): array => [$pds->user_id => $pds->getAllSectionData()]);
    }

    /** @param array<string, mixed> $pds */
    public function extractGender(array $pds): string
    {
        $sex = strtolower(trim((string) (((array) ($pds['pds-personal-info'] ?? []))['personal[sex]'] ?? '')));

        return match ($sex) {
            'male' => 'Male',
            'female' => 'Female',
            default => 'Not Specified',
        };
    }

    /** @param array<string, mixed> $pds */
    private function extractAge(array $pds): ?int
    {
        $birthDate = trim((string) (((array) ($pds['pds-personal-info'] ?? []))['personal[birth_date]'] ?? ''));
        if ($birthDate === '') {
            return null;
        }
        try {
            return Carbon::parse($birthDate)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $pds */
    public function extractAgeBucket(array $pds): string
    {
        $age = $this->extractAge($pds);

        if ($age === null) {
            return 'Unknown';
        }

        if ($age <= 25) {
            return '18-25';
        }
        if ($age <= 35) {
            return '26-35';
        }
        if ($age <= 45) {
            return '36-45';
        }
        if ($age <= 55) {
            return '46-55';
        }

        return '56+';
    }

    /** @param array<string, mixed> $pds */
    public function extractYearsOfService(mixed $createdAt, array $pds): int
    {
        $workSection = (array) ($pds['pds-work-experience'] ?? []);
        $earliestWorkDate = null;

        foreach ($workSection as $key => $value) {
            if (! preg_match('/^work\[\d+\]\[from\]$/', (string) $key)) {
                continue;
            }
            $dateValue = trim((string) $value);
            if ($dateValue === '') {
                continue;
            }
            try {
                $parsed = Carbon::parse($dateValue);
            } catch (\Throwable) {
                continue;
            }
            if ($earliestWorkDate === null || $parsed->lt($earliestWorkDate)) {
                $earliestWorkDate = $parsed;
            }
        }

        $startDate = $earliestWorkDate;

        if ($startDate === null && $createdAt !== null) {
            try {
                $startDate = $createdAt instanceof Carbon ? $createdAt : Carbon::parse((string) $createdAt);
            } catch (\Throwable) {
                $startDate = null;
            }
        }

        return $startDate ? max(0, $startDate->diffInYears(now())) : 0;
    }

    public function serviceBucket(int $years): string
    {
        if ($years < 10) {
            return '< 10 years';
        }
        if ($years < 15) {
            return '10-14 years';
        }
        if ($years < 20) {
            return '15-19 years';
        }
        if ($years < 25) {
            return '20-24 years';
        }
        if ($years < 30) {
            return '25-29 years';
        }
        if ($years < 35) {
            return '30-34 years';
        }
        if ($years < 40) {
            return '35-39 years';
        }

        return '40+ years';
    }

    /**
     * @param  Collection<int, User>  $employees
     * @return array<string, int>
     */
    private function countByKey(Collection $employees, string $key, string $fallback): array
    {
        return $employees
            ->map(fn (User $e): string => ($v = trim((string) ($e->{$key} ?? ''))) !== '' ? $v : $fallback)
            ->countBy()
            ->sortKeys()
            ->all();
    }

    /** @return array{labels: array<int, string>, values: array<int, int>} */
    private function barChartFromAssoc(array $assoc): array
    {
        return ['labels' => array_keys($assoc), 'values' => array_values($assoc)];
    }

    /** @return array{labels: array<int, string>, values: array<int, int>} */
    private function pieChartFromAssoc(array $assoc): array
    {
        return ['labels' => array_keys($assoc), 'values' => array_values($assoc)];
    }

    // ── Alerts ────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function buildHolidayLeaveAlerts(): array
    {
        if (! Schema::hasTable('holidays') || ! Schema::hasTable('leave_requests')) {
            return [];
        }

        $holidays = Holiday::query()
            ->whereBetween('holiday_date', [today(), today()->addDays(30)])
            ->orderBy('holiday_date')
            ->get(['title', 'holiday_date', 'type']);

        $alerts = [];
        foreach ($holidays as $holiday) {
            $date = $holiday->holiday_date->toDateString();
            $count = (int) DB::table('leave_requests')
                ->whereRaw('LOWER(status) = ?', ['pending'])
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->count();

            if ($count > 0) {
                $alerts[] = ['title' => $holiday->title, 'date' => $date, 'type' => $holiday->type, 'count' => $count];
            }
        }

        return $alerts;
    }

    /** @return array<string, mixed> */
    public function buildAlerts(): array
    {
        $openPayroll = null;
        if (Schema::hasTable('payroll_runs')) {
            $run = DB::table('payroll_runs')->where('status', 'draft')->whereNull('locked_at')->orderByDesc('id')->first(['id', 'period']);
            if ($run) {
                $openPayroll = ['period' => $run->period, 'run_id' => $run->id];
            }
        }

        $unresolvedExceptions = Schema::hasTable('payroll_exceptions')
            ? (int) DB::table('payroll_exceptions')->where('resolved_flag', false)->count()
            : 0;

        return [
            'open_payroll' => $openPayroll,
            'unresolved_exceptions' => $unresolvedExceptions,
            'upcoming_holidays' => $this->upcomingHolidayAlerts(),
            'total_alerts' => $unresolvedExceptions + ($openPayroll ? 1 : 0),
        ];
    }

    /** @return array<int, array{title: string, date: string, type: string, days_away: int}> */
    public function upcomingHolidayAlerts(int $days = 14): array
    {
        if (! Schema::hasTable('holidays')) {
            return [];
        }

        return DB::table('holidays')
            ->whereBetween('holiday_date', [today(), today()->addDays($days)])
            ->orderBy('holiday_date')
            ->get(['title', 'holiday_date', 'type'])
            ->map(fn ($h) => [
                'title' => $h->title,
                'date' => $h->holiday_date,
                'type' => $h->type,
                'days_away' => (int) today()->diffInDays($h->holiday_date),
            ])
            ->all();
    }

    // ── Leave analytics ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function buildLeaveAnalytics(?int $departmentId, string $month = ''): array
    {
        $types = ['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'];
        $balanceSummary = [];

        if (Schema::hasTable('leave_balances')) {
            $query = DB::table('leave_balances')->leftJoin('users', 'users.id', '=', 'leave_balances.user_id');
            if ($departmentId !== null) {
                $query->where('users.Dept_id', $departmentId);
            }
            $rows = $query->select('leave_balances.*')->get();

            $now = ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) ? Carbon::parse($month)->endOfMonth() : now();
            $prevMonth = $now->copy()->subMonth();

            $consumptionQuery = fn (int $year, int $mon) => DB::table('leave_dates')
                ->join('leave_requests', 'leave_requests.id', '=', 'leave_dates.leave_request_id')
                ->when($departmentId !== null, fn ($q) => $q->join('users', 'users.id', '=', 'leave_requests.user_id')->where('users.Dept_id', $departmentId))
                ->whereYear('leave_dates.leave_date', $year)
                ->whereMonth('leave_dates.leave_date', $mon)
                ->where('leave_dates.is_cancelled', false)
                ->select('leave_requests.leave_type', DB::raw('COUNT(*) as cnt'))
                ->groupBy('leave_requests.leave_type')
                ->get()
                ->pluck('cnt', 'leave_type');

            $thisMonthConsumption = Schema::hasTable('leave_dates') ? $consumptionQuery($now->year, $now->month) : collect();
            $lastMonthConsumption = Schema::hasTable('leave_dates') ? $consumptionQuery($prevMonth->year, $prevMonth->month) : collect();

            $typeMap = ['VL' => 'Vacation Leave', 'SL' => 'Sick Leave', 'WLNS' => 'Wellness', 'SPL' => 'Solo Parent', 'CTO' => 'CTO', 'SP' => 'Special Privilege'];

            foreach ($types as $type) {
                $col = $rows->pluck($type)->filter(fn ($v) => $v !== null);
                $avg = $col->count() > 0 ? round($col->avg(), 1) : 0;
                $lowCount = $col->filter(fn ($v) => (float) $v < 2)->count();
                $zeroCount = $col->filter(fn ($v) => (float) $v <= 0)->count();

                $thisMonth = (int) ($thisMonthConsumption[$typeMap[$type] ?? $type] ?? 0);
                $lastMonth = (int) ($lastMonthConsumption[$typeMap[$type] ?? $type] ?? 0);
                $trend = $thisMonth > $lastMonth ? 'down' : ($thisMonth < $lastMonth ? 'up' : 'stable');

                $balanceSummary[$type] = ['avg' => $avg, 'low_count' => $lowCount, 'zero_count' => $zeroCount, 'trend' => $trend];
            }
        }

        $criticalEmployees = $this->leaveRequestService
            ->criticalBalances($departmentId)
            ->map(fn ($r) => [
                'user_id' => $r->user_id,
                'name' => trim(($r->last_name ?? '').', '.($r->first_name ?? '')),
                'department' => $r->Dept_name,
                'vl' => round((float) $r->VL, 1),
                'sl' => round((float) $r->SL, 1),
            ])
            ->all();

        $refDate = ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) ? Carbon::parse($month)->endOfMonth() : now();
        $sixMonthsAgo = $refDate->copy()->subMonths(5)->startOfMonth();
        $trendLabels = $trendSubmitted = $trendApproved = [];

        $submittedTrend = LeaveRequest::selectRaw('MONTH(created_at) as m, YEAR(created_at) as y, COUNT(*) as cnt')
            ->when($departmentId !== null, fn ($q) => $q->leftJoin('users', 'users.id', '=', 'leave_requests.user_id')->where('users.Dept_id', $departmentId))
            ->where('created_at', '>=', $sixMonthsAgo)
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->get()
            ->keyBy(fn ($r) => $r->y.'-'.$r->m);

        $approvedTrend = LeaveRequest::selectRaw('MONTH(updated_at) as m, YEAR(updated_at) as y, COUNT(*) as cnt')
            ->when($departmentId !== null, fn ($q) => $q->leftJoin('users', 'users.id', '=', 'leave_requests.user_id')->where('users.Dept_id', $departmentId))
            ->where('status', 'approved')
            ->where('updated_at', '>=', $sixMonthsAgo)
            ->groupByRaw('YEAR(updated_at), MONTH(updated_at)')
            ->get()
            ->keyBy(fn ($r) => $r->y.'-'.$r->m);

        for ($i = 5; $i >= 0; $i--) {
            $dt = $refDate->copy()->subMonths($i);
            $trendLabels[] = $dt->format('M');
            $key = $dt->year.'-'.$dt->month;
            $trendSubmitted[] = (int) ($submittedTrend->get($key)?->cnt ?? 0);
            $trendApproved[] = (int) ($approvedTrend->get($key)?->cnt ?? 0);
        }

        $monthWindow = null;
        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $monthWindow = [Carbon::parse($month)->startOfMonth()->toDateString(), Carbon::parse($month)->endOfMonth()->toDateString()];
        }

        $departmentComparison = Department::query()
            ->orderBy('Dept_name')
            ->get(['Dept_id', 'Dept_name'])
            ->map(function ($department) use ($monthWindow) {
                $employeeCount = User::active()
                    ->whereIn('employee_type', User::LEAVE_ELIGIBLE_TYPES)
                    ->where('Dept_id', $department->Dept_id)
                    ->count();

                $daysUsedQuery = LeaveRequest::query()
                    ->join('users', 'users.id', '=', 'leave_requests.user_id')
                    ->where('users.Dept_id', $department->Dept_id)
                    ->where('leave_requests.status', 'approved');

                if ($monthWindow !== null) {
                    $daysUsedQuery->where(function ($q) use ($monthWindow): void {
                        $q->whereBetween('leave_requests.start_date', $monthWindow)
                            ->orWhereBetween('leave_requests.end_date', $monthWindow);
                    });
                }

                $balances = DB::table('leave_balances')
                    ->join('users', 'users.id', '=', 'leave_balances.user_id')
                    ->where('users.Dept_id', $department->Dept_id)
                    ->select('leave_balances.VL', 'leave_balances.SL')
                    ->get();

                return [
                    'department' => $department->Dept_name,
                    'employee_count' => $employeeCount,
                    'days_used' => round((float) $daysUsedQuery->sum('leave_requests.total_days'), 1),
                    'avg_vl' => $balances->count() > 0 ? round((float) $balances->avg('VL'), 1) : 0,
                    'avg_sl' => $balances->count() > 0 ? round((float) $balances->avg('SL'), 1) : 0,
                ];
            })
            ->sortByDesc('days_used')
            ->values()
            ->all();

        $departmentNames = Department::pluck('Dept_name', 'Dept_id')->toArray();
        $awolEmployees = User::active()
            ->whereIn('employee_type', User::LEAVE_ELIGIBLE_TYPES)
            ->whereHas('leaveBalance')
            ->when($departmentId !== null, fn ($q) => $q->where('Dept_id', $departmentId))
            ->get();

        $awolRisk = $awolEmployees
            ->map(function ($employee) use ($departmentNames) {
                $streak = $this->lwopAggregationService->computeCurrentAwolStreak($employee);

                if ($streak['streak'] < 5) {
                    return null;
                }

                return [
                    'emp_no' => $employee->EmpNo ?? '-',
                    'name' => trim(($employee->last_name ?? '').', '.($employee->first_name ?? '')),
                    'department' => $departmentNames[$employee->Dept_id] ?? '-',
                    'streak' => $streak['capped'] ? '60+' : (string) $streak['streak'],
                    'streak_sort' => $streak['streak'],
                    'episodes_this_semester' => $this->lwopAggregationService->countAwolEpisodesThisSemester($employee),
                    'status' => $this->lwopAggregationService->awolSeverityLabel($streak['streak']),
                ];
            })
            ->filter()
            ->sortByDesc('streak_sort')
            ->values()
            ->all();

        return [
            'balance_summary' => $balanceSummary,
            'critical_employees' => $criticalEmployees,
            'trend' => ['labels' => $trendLabels, 'submitted' => $trendSubmitted, 'approved' => $trendApproved],
            'department_comparison' => $departmentComparison,
            'awol_risk' => $awolRisk,
        ];
    }

    // ── Workforce planning ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function buildWorkforcePlanning(): array
    {
        $milestoneYears = [10, 15, 20, 25, 30];
        $milestones = [];

        $activeEmployees = User::query()
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select('users.id', 'users.name', 'users.date_hired', 'departments.Dept_name')
            ->where('users.Status', 'Active')
            ->whereNotNull('users.date_hired')
            ->get();

        foreach ($activeEmployees as $emp) {
            try {
                $hired = Carbon::parse($emp->date_hired);
            } catch (\Throwable) {
                continue;
            }

            foreach ($milestoneYears as $milestone) {
                $anniversary = $hired->copy()->addYears($milestone);
                $daysUntil = (int) now()->diffInDays($anniversary, false);

                if ($daysUntil >= 0 && $anniversary->year === now()->year) {
                    $milestones[] = [
                        'name' => $emp->name,
                        'department' => $emp->Dept_name ?? 'N/A',
                        'years' => $milestone,
                        'anniversary' => $anniversary->toDateString(),
                        'days_away' => $daysUntil,
                    ];
                }
            }
        }

        usort($milestones, fn ($a, $b) => $a['days_away'] <=> $b['days_away']);

        $now30Start = now()->subDays(30)->startOfDay();
        $prev30Start = now()->subDays(60)->startOfDay();
        $prev30End = now()->subDays(30)->startOfDay();

        $hiredLast30 = (int) User::query()->whereBetween('date_hired', [$now30Start, now()])->count();
        $hiredPrev30 = (int) User::query()->where('date_hired', '>=', $prev30Start)->where('date_hired', '<', $prev30End)->count();
        $separatedLast30 = (int) HRAuditTrail::query()
            ->where('module', 'records')
            ->where('action', 'status_changed')
            ->whereIn('details->new_status', [User::STATUS_SEPARATED, User::STATUS_INACTIVE])
            ->where('created_at', '>=', $now30Start)
            ->count();
        $separatedPrev30 = (int) HRAuditTrail::query()
            ->where('module', 'records')
            ->where('action', 'status_changed')
            ->whereIn('details->new_status', [User::STATUS_SEPARATED, User::STATUS_INACTIVE])
            ->where('created_at', '>=', $prev30Start)
            ->where('created_at', '<', $prev30End)
            ->count();

        $hiredPctChange = $hiredPrev30 > 0 ? round((($hiredLast30 - $hiredPrev30) / $hiredPrev30) * 100, 1) : ($hiredLast30 > 0 ? 100.0 : 0.0);
        $separatedPctChange = $separatedPrev30 > 0 ? round((($separatedLast30 - $separatedPrev30) / $separatedPrev30) * 100, 1) : ($separatedLast30 > 0 ? 100.0 : 0.0);

        $trendLabels = $trendFullLabels = $trendHired = $trendSeparated = $trendHiredDetails = $trendSeparatedDetails = [];
        for ($i = 11; $i >= 0; $i--) {
            $dt = now()->subMonths($i);
            $trendLabels[] = $dt->format('M');
            $trendFullLabels[] = $dt->format('F Y');

            $hiredRows = User::query()
                ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
                ->whereYear('users.date_hired', $dt->year)
                ->whereMonth('users.date_hired', $dt->month)
                ->where('users.date_hired', '<=', now())
                ->orderBy('users.date_hired')
                ->get(['users.name', 'users.date_hired', 'departments.Dept_name']);
            $trendHired[] = $hiredRows->count();
            $trendHiredDetails[] = $hiredRows->map(fn ($u) => [
                'name' => $u->name,
                'department' => $u->Dept_name ?? 'N/A',
                'date' => $u->date_hired ? Carbon::parse($u->date_hired)->toDateString() : null,
            ])->values()->all();

            $separatedAudit = HRAuditTrail::query()
                ->where('module', 'records')
                ->where('action', 'status_changed')
                ->whereIn('details->new_status', [User::STATUS_SEPARATED, User::STATUS_INACTIVE])
                ->whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->orderBy('created_at')
                ->get(['target_id', 'details', 'created_at']);
            $trendSeparated[] = $separatedAudit->count();

            $sepDeptByUserId = User::query()
                ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
                ->whereIn('users.id', $separatedAudit->pluck('target_id')->filter())
                ->pluck('departments.Dept_name', 'users.id');

            $trendSeparatedDetails[] = $separatedAudit->map(fn ($row) => [
                'name' => $row->details['employee_name'] ?? 'Unknown',
                'department' => $sepDeptByUserId->get($row->target_id) ?? 'N/A',
                'date' => optional($row->created_at)->toDateString(),
            ])->values()->all();
        }

        return [
            'milestones' => $milestones,
            'headcount' => [
                'hired_30d' => $hiredLast30,
                'hired_pct_change' => $hiredPctChange,
                'separated_30d' => $separatedLast30,
                'separated_pct_change' => $separatedPctChange,
                'net' => $hiredLast30 - $separatedLast30,
            ],
            'trend' => [
                'labels' => $trendLabels,
                'full_labels' => $trendFullLabels,
                'hired' => $trendHired,
                'separated' => $trendSeparated,
                'hired_details' => $trendHiredDetails,
                'separated_details' => $trendSeparatedDetails,
            ],
        ];
    }

    // ── Payroll overview ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function buildPayrollOverview(): array
    {
        if (! Schema::hasTable('payroll_runs')) {
            return ['runs' => [], 'exceptions' => [], 'dept_net_pay' => ['labels' => [], 'values' => []]];
        }

        $runs = PayrollRun::query()
            ->withCount(['exceptions as unresolved_count' => fn ($q) => $q->where('resolved_flag', false)])
            ->withCount('details as employee_count')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'period' => $r->period,
                'period_start' => $r->period_start?->toDateString(),
                'period_end' => $r->period_end?->toDateString(),
                'status' => $r->status,
                'locked_at' => $r->locked_at?->toDateTimeString(),
                'employee_count' => $r->employee_count,
                'unresolved_exceptions' => $r->unresolved_count,
            ])
            ->all();

        $exceptions = [];
        if (Schema::hasTable('payroll_exceptions')) {
            $latestRunId = PayrollRun::query()->orderByDesc('id')->value('id');
            if ($latestRunId) {
                $exceptions = PayrollException::query()
                    ->with('payrollRun:id,period')
                    ->where('payroll_run_id', $latestRunId)
                    ->where('resolved_flag', false)
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get()
                    ->map(fn ($e) => ['id' => $e->id, 'period' => $e->payrollRun?->period ?? 'N/A', 'type' => $e->type, 'description' => $e->description])
                    ->all();
            }
        }

        $deptNetPay = ['labels' => [], 'values' => []];
        if (Schema::hasTable('payroll_details')) {
            $lockedRun = PayrollRun::query()->whereNotNull('locked_at')->orderByDesc('id')->first(['id']);
            if ($lockedRun) {
                $details = PayrollDetail::with('employee.department')
                    ->where('payroll_run_id', $lockedRun->id)
                    ->get();

                $grouped = $details
                    ->groupBy(fn ($d) => $d->employee?->department?->Dept_name ?? 'Unknown')
                    ->map(fn ($g) => round($g->sum('net_pay'), 2))
                    ->sortDesc();

                $deptNetPay = [
                    'labels' => $grouped->keys()->all(),
                    'values' => $grouped->values()->all(),
                ];
            }
        }

        return ['runs' => $runs, 'exceptions' => $exceptions, 'dept_net_pay' => $deptNetPay];
    }
}
