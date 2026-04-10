<?php

namespace App\Services;

use App\Models\Dtr;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeEarning;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\PayrollAuditLog;
use App\Models\PayrollDetail;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\SalaryMatrix;
use App\Models\User;

class PayrollComputationService
{
    /**
     * Compute payroll for all employees with active assignments.
     *
     * @return array{employee_count: int, errors: string[]}
     */
    public function compute(PayrollRun $run, User $actor): array
    {
        $errors = [];

        // Delete previous details for recomputation
        $run->details()->delete();

        // Get all employees with active plantilla assignments during the period
        $assignments = EmployeeAssignment::with('plantilla', 'employee')
            ->where('start_date', '<=', $run->period_end)
            ->where(function ($q) use ($run) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $run->period_start);
            })
            ->get();

        if ($assignments->isEmpty()) {
            PayrollException::create([
                'payroll_run_id' => $run->id,
                'type' => 'no_assignments',
                'description' => 'No active employee assignments found for this period.',
            ]);
            $errors[] = 'No active employee assignments found.';
        }

        $processed = 0;

        foreach ($assignments as $assignment) {
            $employee = $assignment->employee;
            $plantilla = $assignment->plantilla;

            if (!$employee || !$plantilla) {
                continue;
            }

            // 1. Basic salary from salary matrix
            $basicSalary = $this->getBasicSalary($plantilla->salary_grade, $plantilla->step, $run, $errors);

            // 2. DTR analysis — count days, late, undertime, absences
            $dtrSummary = $this->analyzeDtr($employee->id, $run);

            // 3. Earnings (allowances)
            $totalEarnings = $this->computeEarnings($employee->id);

            // 4. Deductions (mandatory)
            $totalDeductions = $this->computeDeductions($employee->id);

            // 5. Loan deductions
            $loanDeduction = $this->computeLoanDeductions($employee->id);

            // 6. Leave integration — LWOP salary deduction
            $lwopDeduction = $this->computeLwopDeduction($employee->id, $run, $basicSalary);

            // 7. Net pay
            $netPay = $basicSalary + $totalEarnings - $totalDeductions - $loanDeduction - $lwopDeduction;

            // Log exceptions for anomalies
            if ($dtrSummary['absent_days'] > 0) {
                PayrollException::create([
                    'payroll_run_id' => $run->id,
                    'type' => 'absences_detected',
                    'description' => "{$employee->name}: {$dtrSummary['absent_days']} absent day(s) in period.",
                ]);
            }

            if ($lwopDeduction > 0) {
                PayrollException::create([
                    'payroll_run_id' => $run->id,
                    'type' => 'lwop_deduction',
                    'description' => "{$employee->name}: ₱" . number_format($lwopDeduction, 2) . " LWOP deduction applied.",
                ]);
            }

            PayrollDetail::create([
                'payroll_run_id' => $run->id,
                'employee_id' => $employee->id,
                'days_worked' => $dtrSummary['days_worked'],
                'late_minutes' => $dtrSummary['late_minutes'],
                'undertime_minutes' => $dtrSummary['undertime_minutes'],
                'absent_days' => $dtrSummary['absent_days'],
                'basic_salary' => $basicSalary,
                'earnings' => $totalEarnings,
                'deductions' => $totalDeductions,
                'lwop_deduction' => $lwopDeduction,
                'loan_deduction' => $loanDeduction,
                'net_pay' => max($netPay, 0),
            ]);

            $processed++;
        }

        $run->update(['status' => 'computed']);

        PayrollAuditLog::create([
            'action' => 'payroll_computed',
            'user_id' => $actor->id,
            'payroll_run_id' => $run->id,
            'details' => "Payroll computed for {$processed} employee(s). Period: {$run->period_start->format('M d')} – {$run->period_end->format('M d, Y')}.",
            'actioned_at' => now(),
        ]);

        return [
            'employee_count' => $processed,
            'errors' => $errors,
        ];
    }

    /**
     * Look up basic salary from the salary matrix.
     */
    protected function getBasicSalary(int $sg, int $step, PayrollRun $run, array &$errors): float
    {
        $year = $run->period_start->year;

        $entry = SalaryMatrix::where('sg', $sg)
            ->where('step', $step)
            ->where('year', $year)
            ->first();

        if (!$entry) {
            // Fallback: try latest available year
            $entry = SalaryMatrix::where('sg', $sg)
                ->where('step', $step)
                ->orderByDesc('year')
                ->first();
        }

        if (!$entry) {
            $errors[] = "No salary matrix entry for SG-{$sg} Step {$step}.";
            return 0;
        }

        return (float) $entry->amount;
    }

    /**
     * Analyze DTR records for the payroll period.
     * Government: 2 time-in/out per day (AM + PM).
     *
     * @return array{days_worked: int, late_minutes: int, undertime_minutes: int, absent_days: int}
     */
    protected function analyzeDtr(int $employeeId, PayrollRun $run): array
    {
        $dtrs = Dtr::where('employee_id', $employeeId)
            ->whereBetween('date', [$run->period_start, $run->period_end])
            ->get();

        $daysWorked = 0;
        $totalLate = 0;
        $totalUndertime = 0;
        $absentDays = 0;

        // Count working days in the period (Mon-Fri)
        $periodStart = $run->period_start->copy();
        $periodEnd = $run->period_end->copy();
        $workingDays = 0;
        $cursor = $periodStart->copy();
        while ($cursor <= $periodEnd) {
            if ($cursor->isWeekday()) {
                $workingDays++;
            }
            $cursor->addDay();
        }

        $dtrDates = $dtrs->pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->toArray();

        // Count present/absent
        $cursor = $periodStart->copy();
        while ($cursor <= $periodEnd) {
            if ($cursor->isWeekday()) {
                if (in_array($cursor->format('Y-m-d'), $dtrDates)) {
                    $dtr = $dtrs->first(fn ($d) => $d->date->format('Y-m-d') === $cursor->format('Y-m-d'));
                    if ($dtr && !$dtr->is_absent && $dtr->status !== 'absent') {
                        $daysWorked++;
                        $totalLate += $dtr->late_minutes ?? 0;
                        $totalUndertime += $dtr->undertime_minutes ?? 0;
                    } else {
                        $absentDays++;
                    }
                } else {
                    $absentDays++;
                }
            }
            $cursor->addDay();
        }

        return [
            'days_worked' => $daysWorked,
            'late_minutes' => $totalLate,
            'undertime_minutes' => $totalUndertime,
            'absent_days' => $absentDays,
        ];
    }

    /**
     * Sum all recurring employee earnings (allowances).
     */
    protected function computeEarnings(int $employeeId): float
    {
        return (float) EmployeeEarning::where('employee_id', $employeeId)
            ->where('recurring', true)
            ->sum('amount');
    }

    /**
     * Sum all recurring employee deductions (GSIS, PhilHealth, Pag-IBIG, etc.).
     */
    protected function computeDeductions(int $employeeId): float
    {
        return (float) EmployeeDeduction::where('employee_id', $employeeId)
            ->where('recurring', true)
            ->sum('amount');
    }

    /**
     * Sum active loan monthly payments.
     */
    protected function computeLoanDeductions(int $employeeId): float
    {
        return (float) Loan::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->where('balance', '>', 0)
            ->sum('monthly_payment');
    }

    /**
     * Compute LWOP salary deduction from approved leave_requests.
     * Formula: (basic_salary / 22) * lwop_days
     * 22 = standard government working days per month.
     */
    protected function computeLwopDeduction(int $employeeId, PayrollRun $run, float $basicSalary): float
    {
        if ($basicSalary <= 0) {
            return 0;
        }

        $lwopDays = LeaveRequest::where('user_id', $employeeId)
            ->whereIn('status', ['approved', 'Approved'])
            ->where('lwop_days', '>', 0)
            ->where(function ($q) use ($run) {
                $q->whereBetween('start_date', [$run->period_start, $run->period_end])
                  ->orWhereBetween('end_date', [$run->period_start, $run->period_end]);
            })
            ->sum('lwop_days');

        if ($lwopDays <= 0) {
            return 0;
        }

        $dailyRate = $basicSalary / 22;

        return round($dailyRate * $lwopDays, 2);
    }
}
