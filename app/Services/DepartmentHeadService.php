<?php

namespace App\Services;

use App\Models\Eta;
use App\Models\LeaveRequest;
use App\Models\Locator;
use App\Models\TravelOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DepartmentHeadService
{
    protected DepartmentService $departmentService;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    /**
     * Return basic KPI metrics and data used by the department head dashboard.
     *
     * @param  \App\Models\User  $user
     * @return array
     */
    public function dashboardMetrics($user): array
    {
        $depts = $this->departmentService->resolveAllDepartmentsForUser($user);
        if ($depts->isEmpty()) {
            return ['metrics' => ['employees' => 0, 'pending' => 0, 'approved_month' => 0, 'filed' => 0], 'trend' => [], 'distribution' => [], 'recent' => []];
        }

        $cacheKey = 'dh_metrics_' . implode('_', $depts->sortBy('Dept_id')->pluck('Dept_id')->toArray());
        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($depts) {
            return $this->computeDashboardMetrics($depts);
        });
    }

    private function computeDashboardMetrics(\Illuminate\Support\Collection $depts): array
    {
        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

        $employeesCount = count($employeeIds);

        // Exclude leave requests filed by Department Heads (Mayor handles those)
        $leavePendingCount = LeaveRequest::whereIn('user_id', $employeeIds)->where('status', 'pending')
            ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
            ->count();
        $etaPendingCount = Eta::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();
        $locatorPendingCount = Locator::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();
        $pendingCount = $leavePendingCount + $etaPendingCount + $locatorPendingCount;

        $now = Carbon::now();
        $approvedThisMonth = LeaveRequest::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereMonth('updated_at', $now->month)
            ->whereYear('updated_at', $now->year)
            ->count();

        $filedCount = TravelOrder::whereIn('created_by', $employeeIds)->count();

        // Trend: last 6 months
        $labels = [];
        $submitted = [];
        $approved = [];
        // Batch trend queries: 2 queries instead of 12
        $sixMonthsAgo = $now->copy()->subMonths(5)->startOfMonth();
        $submittedTrend = LeaveRequest::selectRaw('MONTH(created_at) as m, YEAR(created_at) as y, COUNT(*) as cnt')
            ->whereIn('user_id', $employeeIds)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . $row->m);

        $approvedTrend = LeaveRequest::selectRaw('MONTH(updated_at) as m, YEAR(updated_at) as y, COUNT(*) as cnt')
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->where('updated_at', '>=', $sixMonthsAgo)
            ->groupByRaw('YEAR(updated_at), MONTH(updated_at)')
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . $row->m);

        for ($i = 5; $i >= 0; $i--) {
            $dt = $now->copy()->subMonths($i);
            $labels[] = $dt->format('M');
            $key = $dt->year . '-' . $dt->month;
            $submitted[] = (int) ($submittedTrend->get($key)?->cnt ?? 0);
            $approved[] = (int) ($approvedTrend->get($key)?->cnt ?? 0);
        }

        $distRows = LeaveRequest::whereIn('user_id', $employeeIds)
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->get();
        $distLabels = $distRows->pluck('status')->map(fn ($s) => ucfirst($s))->toArray();
        $distValues = $distRows->pluck('cnt')->toArray();

        $recentRows = LeaveRequest::with('user')
            ->whereIn('user_id', $employeeIds)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'travel_order_num' => 'LR#' . $r->id,
                    'destination' => $r->leave_type,
                    'status' => ucfirst($r->status),
                    'created_at' => $r->created_at->toDateString(),
                    'user' => $r->user ? ($r->user->first_name . ' ' . $r->user->last_name) : '',
                ];
            })->values();

        return [
            'metrics' => [
                'employees' => $employeesCount,
                'pending' => $pendingCount,
                'approved_month' => $approvedThisMonth,
                'filed' => $filedCount,
                'workforce_today' => $employeesCount,
                // Additional friendly KPI keys for the frontend
                'present_today' => $employeesCount,
                'late_today' => 0,
                'leave_pending' => $leavePendingCount,
                'eta_pending' => $etaPendingCount,
                'locator_pending' => $locatorPendingCount,
                'overtime_hours' => 0,
            ],
            'trend' => ['labels' => $labels, 'submitted' => $submitted, 'approved' => $approved],
            'distribution' => ['labels' => $distLabels, 'values' => $distValues],
            'recent' => $recentRows,
        ];
    }

    /**
     * Return the first resolved department for user (kept for legacy callers).
     *
     * @param  \App\Models\User  $user
     * @return \App\Models\Department|null
     */
    public function resolveDepartment($user)
    {
        return $this->departmentService->resolveAllDepartmentsForUser($user)->first();
    }
}
