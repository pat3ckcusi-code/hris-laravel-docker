<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\Pds;
use App\Models\User;
use App\Support\RoleNormalizer;
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
    public function __construct(private LeaveRequestService $leaveRequestService) {}

    // ── Summary cards ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function buildWorkforceCards(): array
    {
        $chartData = $this->buildChartData(null);

        $totalEmployees = array_sum($chartData['workforce_per_department']['values']);

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
            ->whereRaw(RoleNormalizer::rawExpression().' = ?', ['employee'])
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
    public function buildChartData(?int $departmentId): array
    {
        $employees = $this->employeeQuery($departmentId)->get([
            'id', 'Dept_id', 'Status', 'employee_type', 'created_at', 'date_hired',
        ]);

        $workforcePerDepartment = $this->workforcePerDepartment($departmentId);
        $totalWorkforce = $this->countByKey($employees, 'employee_type', 'Unspecified');
        $employmentStatus = $this->countByKey($employees, 'employee_type', 'Unknown');

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
    private function employeeQuery(?int $departmentId): Builder
    {
        $query = User::query()
            ->whereRaw(RoleNormalizer::rawExpression().' = ?', ['employee']);

        if ($departmentId !== null) {
            $query->where('Dept_id', $departmentId);
        }

        return $query;
    }

    /** @return array{labels: array<int, string>, values: array<int, int>} */
    private function workforcePerDepartment(?int $departmentId): array
    {
        $q = Department::query()
            ->select('departments.Dept_name')
            ->selectRaw('COUNT(users.id) as total')
            ->leftJoin('users', function ($join): void {
                $join->on('users.Dept_id', '=', 'departments.Dept_id')
                    ->whereRaw(RoleNormalizer::rawExpression('users.access_level')." = 'employee'");
            })
            ->groupBy('departments.Dept_id', 'departments.Dept_name')
            ->orderBy('departments.Dept_name');

        if ($departmentId !== null) {
            $q->where('departments.Dept_id', $departmentId);
        }

        $rows = $q->get();

        return [
            'labels' => $rows->pluck('Dept_name')->all(),
            'values' => $rows->pluck('total')->map(fn ($v) => (int) $v)->all(),
        ];
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
        $staleDays = 3;

        $staleLeave = Schema::hasTable('leave_requests')
            ? (int) DB::table('leave_requests')->whereRaw('LOWER(status) = ?', ['pending'])->where('created_at', '<', now()->subDays($staleDays))->count()
            : 0;

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

        $upcomingHolidays = [];
        if (Schema::hasTable('holidays')) {
            $upcomingHolidays = DB::table('holidays')
                ->whereBetween('holiday_date', [today(), today()->addDays(14)])
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

        $staleTravelOrders = Schema::hasTable('travel_orders')
            ? (int) DB::table('travel_orders')->whereRaw('LOWER(status) = ?', ['pending'])->where('created_at', '<', now()->subDays($staleDays))->count()
            : 0;

        $staleDocuments = Schema::hasTable('document_requests')
            ? (int) DB::table('document_requests')->whereRaw('LOWER(status) = ?', ['requested'])->where('requested_on', '<', now()->subDays($staleDays))->count()
            : 0;

        return [
            'stale_leave' => ['count' => $staleLeave, 'days' => $staleDays],
            'open_payroll' => $openPayroll,
            'unresolved_exceptions' => $unresolvedExceptions,
            'upcoming_holidays' => $upcomingHolidays,
            'stale_travel' => $staleTravelOrders,
            'stale_documents' => $staleDocuments,
            'total_alerts' => $staleLeave + $staleTravelOrders + $staleDocuments + $unresolvedExceptions + ($openPayroll ? 1 : 0),
        ];
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

        return [
            'balance_summary' => $balanceSummary,
            'critical_employees' => $criticalEmployees,
            'trend' => ['labels' => $trendLabels, 'submitted' => $trendSubmitted, 'approved' => $trendApproved],
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

                if ($daysUntil >= 0 && $daysUntil <= 90) {
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

        $hiredLast30 = (int) User::query()->where('date_hired', '>=', $now30Start)->count();
        $hiredPrev30 = (int) User::query()->where('date_hired', '>=', $prev30Start)->where('date_hired', '<', $prev30End)->count();
        $separatedLast30 = (int) User::query()->whereIn('Status', ['Separated', 'Inactive'])->where('updated_at', '>=', $now30Start)->count();
        $separatedPrev30 = (int) User::query()->whereIn('Status', ['Separated', 'Inactive'])->where('updated_at', '>=', $prev30Start)->where('updated_at', '<', $prev30End)->count();

        $hiredPctChange = $hiredPrev30 > 0 ? round((($hiredLast30 - $hiredPrev30) / $hiredPrev30) * 100, 1) : ($hiredLast30 > 0 ? 100.0 : 0.0);
        $separatedPctChange = $separatedPrev30 > 0 ? round((($separatedLast30 - $separatedPrev30) / $separatedPrev30) * 100, 1) : ($separatedLast30 > 0 ? 100.0 : 0.0);

        $trendLabels = $trendValues = [];
        for ($i = 11; $i >= 0; $i--) {
            $dt = now()->subMonths($i);
            $trendLabels[] = $dt->format('M');
            $trendValues[] = (int) User::query()->whereYear('date_hired', $dt->year)->whereMonth('date_hired', $dt->month)->count();
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
            'trend' => ['labels' => $trendLabels, 'values' => $trendValues],
        ];
    }

    // ── Attendance overview ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function buildAttendanceOverview(string $month, ?int $departmentId): array
    {
        if (! Schema::hasTable('dtrs')) {
            return ['summary' => [], 'trend' => [], 'dept_late' => [], 'drilldown' => []];
        }

        [$year, $mon] = explode('-', $month);
        $year = (int) $year;
        $mon = (int) $mon;

        $baseQuery = fn () => DB::table('dtrs')
            ->join('users', 'users.id', '=', 'dtrs.employee_id')
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->whereYear('dtrs.date', $year)
            ->whereMonth('dtrs.date', $mon)
            ->when($departmentId !== null, fn ($q) => $q->where('users.Dept_id', $departmentId));

        $summary = $baseQuery()->selectRaw('
            COUNT(DISTINCT dtrs.employee_id) as total_employees,
            AVG(CASE WHEN dtrs.late_minutes > 0 THEN dtrs.late_minutes END) as avg_late,
            AVG(CASE WHEN dtrs.undertime_minutes > 0 THEN dtrs.undertime_minutes END) as avg_undertime,
            SUM(dtrs.is_absent) as total_absences
        ')->first();

        $summaryCards = [
            'total_employees' => (int) ($summary->total_employees ?? 0),
            'avg_tardiness_minutes' => (int) round((float) ($summary->avg_late ?? 0)),
            'avg_undertime_minutes' => (int) round((float) ($summary->avg_undertime ?? 0)),
            'total_absences' => (int) ($summary->total_absences ?? 0),
        ];

        $trendFrom = Carbon::createFromDate($year, $mon, 1)->subMonths(2)->startOfMonth();
        $trendTo = Carbon::createFromDate($year, $mon, 1)->endOfMonth();

        $trend = DB::table('dtrs')
            ->join('users', 'users.id', '=', 'dtrs.employee_id')
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->whereBetween('dtrs.date', [$trendFrom->toDateString(), $trendTo->toDateString()])
            ->when($departmentId !== null, fn ($q) => $q->where('users.Dept_id', $departmentId))
            ->selectRaw("
                DATE_FORMAT(dtrs.date, '%Y-%m') as month,
                COUNT(CASE WHEN dtrs.late_minutes > 0 THEN 1 END) as tardiness_days,
                COUNT(CASE WHEN dtrs.undertime_minutes > 0 THEN 1 END) as undertime_days,
                SUM(dtrs.is_absent) as absent_days
            ")
            ->groupBy(DB::raw("DATE_FORMAT(dtrs.date, '%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->month,
                'tardiness_days' => (int) $r->tardiness_days,
                'undertime_days' => (int) $r->undertime_days,
                'absent_days' => (int) $r->absent_days,
            ])
            ->all();

        $deptLate = $baseQuery()
            ->selectRaw('departments.Dept_name, SUM(dtrs.late_minutes) as total_late')
            ->groupBy('departments.Dept_id', 'departments.Dept_name')
            ->orderByDesc('total_late')
            ->get()
            ->map(fn ($r) => ['department' => $r->Dept_name ?? 'Unknown', 'late_minutes' => (int) $r->total_late])
            ->all();

        $drilldown = $baseQuery()
            ->selectRaw('
                users.id,
                users.EmpNo,
                users.name,
                departments.Dept_name,
                COUNT(CASE WHEN dtrs.late_minutes > 0 THEN 1 END) as tardiness_count,
                COUNT(CASE WHEN dtrs.undertime_minutes > 0 THEN 1 END) as undertime_count
            ')
            ->groupBy('users.id', 'users.EmpNo', 'users.name', 'departments.Dept_name')
            ->havingRaw('tardiness_count > 10 OR undertime_count > 10')
            ->orderByDesc(DB::raw('tardiness_count + undertime_count'))
            ->get()
            ->map(fn ($r) => [
                'user_id' => $r->id,
                'emp_no' => $r->EmpNo ?? '—',
                'name' => $r->name,
                'department' => $r->Dept_name ?? 'Unknown',
                'tardiness_count' => (int) $r->tardiness_count,
                'undertime_count' => (int) $r->undertime_count,
            ])
            ->all();

        return ['summary' => $summaryCards, 'trend' => $trend, 'dept_late' => $deptLate, 'drilldown' => $drilldown];
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
                $rows = DB::table('payroll_details')
                    ->join('users', 'users.id', '=', 'payroll_details.employee_id')
                    ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
                    ->where('payroll_details.payroll_run_id', $lockedRun->id)
                    ->selectRaw('departments.Dept_name, SUM(payroll_details.net_pay) as total_net')
                    ->groupBy('departments.Dept_id', 'departments.Dept_name')
                    ->orderByDesc('total_net')
                    ->get();

                $deptNetPay = [
                    'labels' => $rows->pluck('Dept_name')->map(fn ($n) => $n ?? 'Unknown')->all(),
                    'values' => $rows->pluck('total_net')->map(fn ($v) => round((float) $v, 2))->all(),
                ];
            }
        }

        return ['runs' => $runs, 'exceptions' => $exceptions, 'dept_net_pay' => $deptNetPay];
    }
}
