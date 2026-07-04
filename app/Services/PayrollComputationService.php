<?php

namespace App\Services;

use App\Models\Dtr;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEarning;
use App\Models\EmployeeShiftSchedule;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\PayrollAuditLog;
use App\Models\PayrollDetail;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\SalaryMatrix;
use App\Models\Setting;
use App\Models\User;
use App\Support\WorkSchedule;

class PayrollComputationService
{
    /**
     * Compute payroll for all employees with active assignments.
     *
     * LGU Formula:
     *   Gross Pay   = Basic Salary + PERA + Hazard Pay + Subsistence + Laundry + Other
     *   Mandatory   = GSIS Premium + PhilHealth + Pag-IBIG + BIR Withholding Tax
     *   Net Pay     = Gross Pay − Mandatory − Loan Deductions − LWOP Deduction
     *
     * @return array{employee_count: int, errors: string[]}
     */
    public function compute(PayrollRun $run, User $actor): array
    {
        $errors = [];

        $run->details()->delete();

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

        $settings = $this->loadSettings();
        $processed = 0;

        foreach ($assignments as $assignment) {
            $employee = $assignment->employee;
            $plantilla = $assignment->plantilla;

            if (! $employee || ! $plantilla) {
                continue;
            }

            // 1. Basic salary from salary matrix
            $basicSalary = $this->getBasicSalary($plantilla->salary_grade, $plantilla->step, $run, $errors);

            // 2. DTR analysis
            $dtrSummary = $this->analyzeDtr($employee->id, $run);

            // 3. Allowances (PERA, Hazard Pay, Subsistence, Laundry, Other)
            $allowances = $this->computeAllowances($employee->id, $basicSalary);
            $grossPay = $basicSalary + $allowances['total'];

            // 4. Mandatory deductions (GSIS, PhilHealth, Pag-IBIG, BIR)
            $mandatory = $this->computeMandatoryDeductions($basicSalary, $grossPay, $settings);

            // 5. Loan deductions
            $loanDeduction = $this->computeLoanDeductions($employee->id);

            // 6. LWOP deduction
            $lwopDeduction = $this->computeLwopDeduction($employee->id, $run, $basicSalary);

            // 7. Net pay
            $netPay = max(0, $grossPay - $mandatory['total'] - $loanDeduction - $lwopDeduction);

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
                    'description' => "{$employee->name}: ₱".number_format($lwopDeduction, 2).' LWOP deduction applied.',
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
                'gross_pay' => $grossPay,
                'earnings' => $allowances['total'],
                'gsis_deduction' => $mandatory['gsis'],
                'philhealth_deduction' => $mandatory['philhealth'],
                'pagibig_deduction' => $mandatory['pagibig'],
                'bir_deduction' => $mandatory['bir'],
                'deductions' => $mandatory['total'],
                'loan_deduction' => $loanDeduction,
                'lwop_deduction' => $lwopDeduction,
                'net_pay' => $netPay,
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
     * Load mandatory deduction settings from payroll_settings.
     * Falls back to standard government defaults if not configured.
     */
    protected function loadSettings(): array
    {
        $rows = PayrollSetting::all()->pluck('value', 'key')->toArray();

        $defaults = [
            'gsis_premium_rate' => 0.09,
            'philhealth_rate' => 0.05,
            'philhealth_floor' => 400.00,
            'philhealth_ceiling' => 3750.00,
            'pagibig_amount' => 100.00,
            'bir_tax_brackets' => $this->defaultBirBrackets(),
        ];

        $settings = [];
        foreach ($defaults as $key => $default) {
            $value = $rows[$key] ?? null;
            if ($value === null) {
                $settings[$key] = $default;
            } elseif ($key === 'bir_tax_brackets') {
                $settings[$key] = json_decode($value, true) ?: $default;
            } else {
                $settings[$key] = (float) $value;
            }
        }

        return $settings;
    }

    /**
     * Look up basic salary from the salary matrix.
     */
    protected function getBasicSalary(int $sg, int $step, PayrollRun $run, array &$errors): float
    {
        // The latest matrix version whose effective_date has been reached by
        // the run's period - this is what makes a mid-year ordinance apply
        // exactly when it takes effect, not just at the next calendar year.
        $entry = SalaryMatrix::where('sg', $sg)
            ->where('step', $step)
            ->where('effective_date', '<=', $run->period_start)
            ->orderByDesc('effective_date')
            ->first();

        if (! $entry) {
            // Period predates all known versions for this sg/step - fall
            // back to the earliest one on record rather than paying zero.
            $entry = SalaryMatrix::where('sg', $sg)
                ->where('step', $step)
                ->orderBy('effective_date')
                ->first();
        }

        if (! $entry) {
            $errors[] = "No salary matrix entry for SG-{$sg} Step {$step}.";

            return 0;
        }

        return (float) $entry->amount;
    }

    /**
     * Analyze DTR records for the payroll period.
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

        $dtrDates = $dtrs->pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->toArray();

        // Pre-load per-date shift assignments so rest-day checks are O(1).
        $employee = User::find($employeeId);
        $assignments = EmployeeShiftSchedule::where('user_id', $employeeId)
            ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        $cursor = $run->period_start->copy();
        while ($cursor <= $run->period_end) {
            if ($cursor->isWeekday()) {
                // A scheduled rest/off day is neither worked nor absent.
                if ($employee && WorkSchedule::isRestDay($employee, $cursor, $assignments)) {
                    $cursor->addDay();

                    continue;
                }

                if (in_array($cursor->format('Y-m-d'), $dtrDates)) {
                    $dtr = $dtrs->first(fn ($d) => $d->date->format('Y-m-d') === $cursor->format('Y-m-d'));
                    if ($dtr && ! $dtr->is_absent && $dtr->status !== 'absent') {
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
     * Sum recurring allowances, grouped by allowance_type.
     *
     * @return array{pera: float, hazard_pay: float, subsistence_allowance: float, laundry_allowance: float, other: float, total: float}
     */
    protected function computeAllowances(int $employeeId, float $basicSalary): array
    {
        $rows = EmployeeEarning::with('earning')
            ->where('employee_id', $employeeId)
            ->where('recurring', true)
            ->get();

        $result = [
            'pera' => 0.0,
            'hazard_pay' => 0.0,
            'subsistence_allowance' => 0.0,
            'laundry_allowance' => 0.0,
            'other' => 0.0,
            'total' => 0.0,
        ];

        foreach ($rows as $row) {
            $key = $row->earning->allowance_type ?? 'other';
            if (! array_key_exists($key, $result)) {
                $key = 'other';
            }

            if (($row->amount_type ?? 'fixed') === 'percentage' && $row->percentage !== null) {
                $value = round($basicSalary * ($row->percentage / 100), 2);
            } else {
                $value = (float) $row->amount;
            }

            $result[$key] += $value;
            $result['total'] += $value;
        }

        return $result;
    }

    /**
     * Compute mandatory government deductions using configured rates.
     * BIR taxable income = Gross Pay − GSIS − PhilHealth − Pag-IBIG.
     *
     * @return array{gsis: float, philhealth: float, pagibig: float, bir: float, total: float}
     */
    protected function computeMandatoryDeductions(float $basicSalary, float $grossPay, array $settings): array
    {
        // GSIS Premium: employee share = 9% of basic salary
        $gsis = round($basicSalary * $settings['gsis_premium_rate'], 2);

        // PhilHealth: employee share = 50% of (rate × basic), clamped to floor/ceiling
        $philhealth = round($basicSalary * $settings['philhealth_rate'] / 2, 2);
        $philhealth = (float) max($settings['philhealth_floor'], min($philhealth, $settings['philhealth_ceiling']));

        // Pag-IBIG: fixed monthly contribution
        $pagibig = (float) $settings['pagibig_amount'];

        // BIR withholding tax: applied on taxable income (gross less mandatory contributions)
        $taxable = max(0.0, $grossPay - $gsis - $philhealth - $pagibig);
        $bir = $this->computeBir($taxable, $settings['bir_tax_brackets']);

        return [
            'gsis' => $gsis,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'bir' => $bir,
            'total' => $gsis + $philhealth + $pagibig + $bir,
        ];
    }

    /**
     * Apply BIR monthly tax bracket table (TRAIN Law 2023).
     */
    protected function computeBir(float $taxable, array $brackets): float
    {
        foreach (array_reverse($brackets) as $bracket) {
            if ($taxable > $bracket['min']) {
                return round($bracket['base'] + ($taxable - $bracket['min']) * $bracket['rate'], 2);
            }
        }

        return 0.0;
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
     * Compute LWOP salary deduction.
     * Formula: (basic_salary / working_days_per_month) × lwop_days
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

        $workingDays = Setting::first()?->payroll_working_days_per_month ?? 22;

        return round(($basicSalary / $workingDays) * $lwopDays, 2);
    }

    /**
     * BIR monthly income tax brackets per TRAIN Law (RA 10963), effective 2023.
     */
    protected function defaultBirBrackets(): array
    {
        return [
            ['min' => 0,      'max' => 10417,  'base' => 0.00,      'rate' => 0.00],
            ['min' => 10417,  'max' => 16667,  'base' => 0.00,      'rate' => 0.15],
            ['min' => 16667,  'max' => 33333,  'base' => 937.50,    'rate' => 0.20],
            ['min' => 33333,  'max' => 83333,  'base' => 4270.83,   'rate' => 0.25],
            ['min' => 83333,  'max' => 333333, 'base' => 16770.83,  'rate' => 0.30],
            ['min' => 333333, 'max' => null,   'base' => 91770.83,  'rate' => 0.35],
        ];
    }
}
