<?php

namespace App\Services;

use App\Models\Eta;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Blocks filing an ETA or Locator whose single relevant date (departure_date
 * for ETA, travel_date for Locator) collides with another already-active
 * absence record for the same employee. Checked in a fixed priority order per
 * type below; the first hit's message is returned. Mirrors the five absence
 * sources and query conventions WorkforceCalendarService already uses (Leave
 * via the leave_dates child table, Office/Travel Order via raw DB::table()
 * joins on User.EmpNo). Intentionally one-way: an existing Locator does NOT
 * block filing an ETA, only the reverse (a deliberate product decision, not
 * an oversight). Also deliberately checks only departure_date for an ETA
 * (never arrival_date) across every rule, for the same reason.
 */
class EtaLocatorConflictService
{
    public const TYPE_ETA = 'eta';

    public const TYPE_LOCATOR = 'locator';

    /**
     * @param  string  $type  self::TYPE_ETA or self::TYPE_LOCATOR
     * @param  string  $date  Y-m-d - departure_date for ETA, travel_date for Locator
     * @param  int|null  $excludeId  when editing an existing row of the same $type, its own id
     *                               (so it doesn't count as a conflict against itself)
     * @return string|null user-facing conflict message, or null if none
     */
    public function checkConflict(User $user, string $type, string $date, ?int $excludeId = null): ?string
    {
        if ($type === self::TYPE_ETA) {
            if ($this->hasEtaOnDate($user->id, $date, $excludeId)) {
                return 'You already have a pending or approved ETA for this date.';
            }
        } else {
            if ($this->hasLocatorOnDate($user->id, $date, $excludeId)) {
                return 'You already have a pending or approved Locator for this date.';
            }
            if ($this->hasEtaOnDate($user->id, $date)) {
                return 'You already have an active ETA for this date. Please cancel it before filing a Locator for the same day.';
            }
        }

        if ($this->hasLeaveOnDate($user->id, $date)) {
            return 'You already have a pending or approved leave request covering this date.';
        }

        if ($this->hasOrderOnDate($user->EmpNo, $date)) {
            return 'This date is already covered by an Office Order or Travel Order.';
        }

        return null;
    }

    private function hasEtaOnDate(int $userId, string $date, ?int $excludeId = null): bool
    {
        return Eta::where('user_id', $userId)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('departure_date', $date)
            ->exists();
    }

    private function hasLocatorOnDate(int $userId, string $date, ?int $excludeId = null): bool
    {
        return Locator::where('user_id', $userId)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('travel_date', $date)
            ->exists();
    }

    private function hasLeaveOnDate(int $userId, string $date): bool
    {
        return LeaveDate::where('leave_date', $date)
            ->where('is_cancelled', false)
            ->whereHas('leaveRequest', fn ($q) => $q->where('user_id', $userId)
                ->whereIn('status', ['pending', 'approved']))
            ->exists();
    }

    private function hasOrderOnDate(?string $empNo, string $date): bool
    {
        if (empty($empNo)) {
            return false;
        }

        $hasOfficeOrder = DB::table('office_orders')
            ->join('office_order_employees', 'office_orders.id', '=', 'office_order_employees.office_order_id')
            ->where('office_order_employees.emp_no', $empNo)
            ->where('office_orders.status', '!=', 'Cancelled')
            ->where('office_orders.issued_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->where('office_orders.effective_date', '>=', $date)
                    ->orWhere(function ($q2) use ($date) {
                        $q2->whereNull('office_orders.effective_date')
                            ->where('office_orders.issued_date', '>=', $date);
                    });
            })
            ->exists();

        if ($hasOfficeOrder) {
            return true;
        }

        return DB::table('travel_orders')
            ->join('travel_order_employees', 'travel_orders.id', '=', 'travel_order_employees.travel_order_id')
            ->where('travel_order_employees.emp_no', $empNo)
            ->where('travel_orders.status', 'Approved')
            ->where('travel_orders.start_date', '<=', $date)
            ->where('travel_orders.end_date', '>=', $date)
            ->exists();
    }
}
