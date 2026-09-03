<?php

namespace App\Services;

use App\Models\Eta;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the "who's away" data behind the Workforce Calendar: per-date counts
 * and employee lists across the five absence sources (Leave, ETA, Locator,
 * Travel Order, Office Order). Mirrors the status/date-range conventions
 * AttendanceMonitoringExportService already uses for the same five sources
 * (that service's coverage-building logic is private to one large per-employee
 * method, so this reimplements the same conventions per-date instead of
 * reaching into it). Office Order covers every day from issued_date through
 * effective_date (or just issued_date if effective_date isn't set), the same
 * range expansion as Travel Order. Office Order intentionally has no status
 * filter, matching that existing behavior - the office_orders.status column
 * has no observed approve/reject workflow.
 */
class WorkforceCalendarService
{
    public const CATEGORY_LABELS = [
        'leave' => 'Leave',
        'eta' => 'ETA',
        'locator' => 'Locator',
        'travel_order' => 'Travel Order',
        'office_order' => 'Office Order',
    ];

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<string, array{counts: array<string, int>, employees: array<int, array{user_id: int, name: string, type: string, label: string, order_id?: int}>}>
     */
    public function buildMonthSummary(array $employeeIds, Carbon $monthStart, Carbon $monthEnd): array
    {
        $calendar = [];
        for ($d = $monthStart->copy(); $d->lte($monthEnd); $d->addDay()) {
            $calendar[$d->toDateString()] = [
                'counts' => array_fill_keys(array_keys(self::CATEGORY_LABELS), 0),
                'employees' => [],
            ];
        }

        if (empty($employeeIds)) {
            return $calendar;
        }

        $users = User::whereIn('id', $employeeIds)->get(['id', 'EmpNo', 'name', 'first_name', 'last_name']);
        $namesById = $users->mapWithKeys(fn (User $u) => [$u->id => $this->displayName($u)])->all();
        $idsByEmpNo = $users->pluck('id', 'EmpNo')->filter()->all();

        $start = $monthStart->toDateString();
        $end = $monthEnd->toDateString();

        $this->addLeave($calendar, $employeeIds, $start, $end, $namesById);
        $this->addEta($calendar, $employeeIds, $monthStart, $monthEnd, $namesById);
        $this->addLocator($calendar, $employeeIds, $start, $end, $namesById);
        $this->addTravelOrders($calendar, $idsByEmpNo, $monthStart, $monthEnd, $namesById);
        $this->addOfficeOrders($calendar, $idsByEmpNo, $start, $end, $namesById);

        return $calendar;
    }

    private function addLeave(array &$calendar, array $employeeIds, string $start, string $end, array $namesById): void
    {
        $leaveDates = LeaveDate::where('is_cancelled', false)
            ->whereHas('leaveRequest', function ($q) use ($employeeIds) {
                $q->whereIn('user_id', $employeeIds)->where('status', 'approved');
            })
            ->whereBetween('leave_date', [$start, $end])
            ->with('leaveRequest:id,user_id,leave_type')
            ->get();

        foreach ($leaveDates as $ld) {
            $userId = $ld->leaveRequest->user_id ?? null;
            if (! $userId) {
                continue;
            }
            $dateStr = Carbon::parse($ld->leave_date)->toDateString();
            $label = trim((string) ($ld->leaveRequest->leave_type ?? '')) ?: 'Leave';
            $this->addEntry($calendar, $dateStr, 'leave', $userId, $namesById[$userId] ?? '', $label);
        }
    }

    private function addEta(array &$calendar, array $employeeIds, Carbon $monthStart, Carbon $monthEnd, array $namesById): void
    {
        $etas = Eta::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('departure_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('arrival_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            })
            ->get();

        foreach ($etas as $eta) {
            $label = trim((string) ($eta->destination ?? $eta->purpose ?? '')) ?: 'ETA';
            $cursor = Carbon::parse($eta->departure_date)->startOfDay();
            $until = Carbon::parse($eta->arrival_date ?? $eta->departure_date)->startOfDay();
            $cursor = $cursor->lt($monthStart) ? $monthStart->copy() : $cursor;
            $until = $until->gt($monthEnd) ? $monthEnd->copy() : $until;

            for (; $cursor->lte($until); $cursor->addDay()) {
                $this->addEntry($calendar, $cursor->toDateString(), 'eta', $eta->user_id, $namesById[$eta->user_id] ?? '', $label);
            }
        }
    }

    private function addLocator(array &$calendar, array $employeeIds, string $start, string $end, array $namesById): void
    {
        $locators = Locator::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$start, $end])
            ->get();

        foreach ($locators as $locator) {
            $dateStr = Carbon::parse($locator->travel_date)->toDateString();
            $label = trim((string) ($locator->detail ?? $locator->location ?? '')) ?: 'Locator';
            $this->addEntry($calendar, $dateStr, 'locator', $locator->user_id, $namesById[$locator->user_id] ?? '', $label);
        }
    }

    private function addTravelOrders(array &$calendar, array $idsByEmpNo, Carbon $monthStart, Carbon $monthEnd, array $namesById): void
    {
        if (empty($idsByEmpNo)) {
            return;
        }

        $rows = DB::table('travel_orders')
            ->join('travel_order_employees', 'travel_orders.id', '=', 'travel_order_employees.travel_order_id')
            ->whereIn('travel_order_employees.emp_no', array_keys($idsByEmpNo))
            ->where('travel_orders.status', 'Approved')
            ->where('travel_orders.start_date', '<=', $monthEnd->toDateString())
            ->where('travel_orders.end_date', '>=', $monthStart->toDateString())
            ->select('travel_order_employees.emp_no', 'travel_orders.id as order_id', 'travel_orders.travel_order_num', 'travel_orders.start_date', 'travel_orders.end_date')
            ->get();

        foreach ($rows as $row) {
            $userId = $idsByEmpNo[$row->emp_no] ?? null;
            if (! $userId || ! $row->start_date || ! $row->end_date) {
                continue;
            }
            $cursor = Carbon::parse($row->start_date)->startOfDay();
            $until = Carbon::parse($row->end_date)->startOfDay();
            $cursor = $cursor->lt($monthStart) ? $monthStart->copy() : $cursor;
            $until = $until->gt($monthEnd) ? $monthEnd->copy() : $until;
            $label = $row->travel_order_num;

            for (; $cursor->lte($until); $cursor->addDay()) {
                $this->addEntry($calendar, $cursor->toDateString(), 'travel_order', $userId, $namesById[$userId] ?? '', $label, ['order_id' => $row->order_id]);
            }
        }
    }

    private function addOfficeOrders(array &$calendar, array $idsByEmpNo, string $start, string $end, array $namesById): void
    {
        if (empty($idsByEmpNo)) {
            return;
        }

        $rows = DB::table('office_orders')
            ->join('office_order_employees', 'office_orders.id', '=', 'office_order_employees.office_order_id')
            ->whereIn('office_order_employees.emp_no', array_keys($idsByEmpNo))
            ->where('office_orders.status', '!=', 'Cancelled')
            ->where('office_orders.issued_date', '<=', $end)
            ->where(function ($q) use ($start): void {
                $q->where('office_orders.effective_date', '>=', $start)
                    ->orWhere(function ($q2) use ($start): void {
                        $q2->whereNull('office_orders.effective_date')
                            ->where('office_orders.issued_date', '>=', $start);
                    });
            })
            ->select('office_order_employees.emp_no', 'office_orders.id as order_id', 'office_orders.office_order_num', 'office_orders.effective_date', 'office_orders.issued_date')
            ->get();

        $rangeStart = Carbon::parse($start);
        $rangeEnd = Carbon::parse($end);

        foreach ($rows as $row) {
            $userId = $idsByEmpNo[$row->emp_no] ?? null;
            if (! $userId || ! $row->issued_date) {
                continue;
            }
            $cursor = Carbon::parse($row->issued_date)->startOfDay();
            $until = Carbon::parse($row->effective_date ?? $row->issued_date)->startOfDay();
            $cursor = $cursor->lt($rangeStart) ? $rangeStart->copy() : $cursor;
            $until = $until->gt($rangeEnd) ? $rangeEnd->copy() : $until;
            $label = $row->office_order_num;

            for (; $cursor->lte($until); $cursor->addDay()) {
                $this->addEntry($calendar, $cursor->toDateString(), 'office_order', $userId, $namesById[$userId] ?? '', $label, ['order_id' => $row->order_id]);
            }
        }
    }

    private function addEntry(array &$calendar, string $dateStr, string $type, int $userId, string $name, string $label, array $extra = []): void
    {
        if (! isset($calendar[$dateStr])) {
            return;
        }
        $calendar[$dateStr]['counts'][$type]++;
        $calendar[$dateStr]['employees'][] = array_merge([
            'user_id' => $userId,
            'name' => $name,
            'type' => $type,
            'label' => $label,
        ], $extra);
    }

    private function displayName(User $user): string
    {
        $name = trim(implode(', ', array_filter([
            $user->last_name ?? '',
            $user->first_name ?? '',
        ])));

        return $name !== '' ? $name : (string) ($user->name ?? '');
    }
}
