<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\Dtr;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeEarning;
use App\Models\EmployeeShiftSchedule;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\PayrollAuditLog;
use App\Models\PayrollDetail;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\SalaryMatrix;
use App\Models\Setting;
use App\Models\User;
use App\Models\WithholdingTax;
use App\Support\WorkSchedule;
use Illuminate\Support\Collection;

class PayrollComputationService
{
    /**
     * Compute payroll for all employees with active assignments.
     *
     * LGU Formula:
     *   Gross Pay   = Basic Salary + PERA + Hazard Pay + Subsistence + Laundry + Other
     *   Mandatory   = GSIS Premium + PhilHealth + Pag-IBIG + BIR Withholding Tax
     *   Net Pay     = Gross Pay − Mandatory − Loan Deductions − Other Deductions − LWOP Deduction
     *
     * @return array{employee_count: int, errors: string[]}
     */
    public function compute(PayrollRun $run, User $actor): array
    {
        if ($run->locked_at) {
            throw new \RuntimeException('Cannot compute a locked payroll run.');
        }

        $errors = [];

        $run->details()->delete();
        $run->exceptions()->whereIn('type', PayrollException::AUTO_TYPES)->delete();

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

        // The 4 system mandatory-deduction catalog rows are the single source
        // of truth for their computation_type/rate config (computeMandatoryDeductions())
        // and their payslip label (used when building $deductionBreakdown below) - see
        // "Unify mandatory-deduction rates into the Deductions page" and
        // "Make mandatory-deduction computation type itself configurable".
        $mandatoryTypes = Deduction::whereNotNull('mandatory_key')->get()->keyBy('mandatory_key');

        // "Other" category rows switched into Standing Rate mode - auto-computed
        // for every eligible employee exactly like the 4 mandatory rows above,
        // instead of relying on per-employee EmployeeDeduction rows. See
        // computeOtherDeductions() and "Let 'Other' deduction types use a
        // standing per-type rate, like Mandatory".
        $autoRateOtherTypes = Deduction::where('deduction_category', 'other')->whereNotNull('computation_type')->get();

        $processed = 0;

        foreach ($assignments as $assignment) {
            $employee = $assignment->employee;
            $plantilla = $assignment->plantilla;

            if (! $employee || ! $plantilla) {
                continue;
            }

            // 1. Basic salary from salary matrix. Step is the assignment's own
            // (personal to this stint), not the plantilla's shared, position-level step.
            $basicSalary = $this->getBasicSalary($plantilla->salary_grade, $assignment->step, $run, $errors);

            // 2. DTR analysis
            $dtrSummary = $this->analyzeDtr($employee->id, $run);

            // 3. Allowances (PERA, Hazard Pay, Subsistence, Laundry, Other)
            $allowances = $this->computeAllowances($employee->id, $basicSalary);
            $grossPay = $basicSalary + $allowances['total'];

            // 4. Mandatory deductions (GSIS, PhilHealth, Pag-IBIG)
            $mandatory = $this->computeMandatoryDeductions($basicSalary, $grossPay, $mandatoryTypes, $employee->employee_type);

            // 4b. Withholding tax - no longer bracket-computed; Accounting
            // computes it and it's uploaded monthly, looked up here and split
            // across however many runs fall in the same calendar month. See
            // computeWithholdingTax() and "Replace computed BIR withholding
            // tax with an Accounting-uploaded monthly table".
            $withholdingTax = $this->computeWithholdingTax($employee->id, $run);
            $mandatory['bir'] = $withholdingTax['amount'];
            $mandatory['total'] += $mandatory['bir'];

            // 5. Loan deductions (named, per-provider breakdown)
            $loanResult = $this->computeLoanDeductions($employee->id);

            // 6. Other recurring deductions (named, flat, non-loan — e.g. insurance, GSIS MP2, cellphone plan)
            $otherResult = $this->computeOtherDeductions($employee->id, $basicSalary, $autoRateOtherTypes, $employee->employee_type);

            // 7. LWOP deduction
            $lwopDeduction = $this->computeLwopDeduction($employee->id, $run, $basicSalary);

            // 8. Net pay
            $netPay = max(0, $grossPay - $mandatory['total'] - $loanResult['total'] - $otherResult['total'] - $lwopDeduction);

            // 9. Flat, ordered breakdown of every named deduction line for the payslip.
            // A deactivated mandatory row is omitted entirely rather than shown as ₱0.00.
            $mandatoryDefaultLabels = [
                'gsis' => 'Life & Retirement',
                'philhealth' => 'Medicare',
                'pagibig' => 'HDMF (Pag-ibig)',
                'bir' => 'Withholding Tax',
            ];
            $mandatoryLines = [];
            foreach ($mandatoryDefaultLabels as $key => $defaultLabel) {
                $row = $mandatoryTypes->get($key);
                // Withholding tax is authoritative as uploaded - never gated
                // by is_active/eligible_employee_types, unlike the other 3.
                if ($key !== 'bir' && ! $this->mandatoryAppliesToEmployee($row, $employee->employee_type)) {
                    continue;
                }
                $mandatoryLines[] = ['label' => $row?->type ?? $defaultLabel, 'amount' => $mandatory[$key], 'category' => 'mandatory'];
            }

            $deductionBreakdown = [
                ...$mandatoryLines,
                ...array_map(fn ($item) => [...$item, 'category' => 'loan'], $loanResult['items']),
                ...array_map(fn ($item) => [...$item, 'category' => 'other'], $otherResult['items']),
            ];

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

            if (! $withholdingTax['found']) {
                PayrollException::create([
                    'payroll_run_id' => $run->id,
                    'type' => 'missing_withholding_tax',
                    'description' => "{$employee->name}: no withholding tax uploaded for {$run->period_start->format('F Y')}.",
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
                'loan_deduction' => $loanResult['total'],
                'other_deductions' => $otherResult['total'],
                'deduction_breakdown' => $deductionBreakdown,
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

        // Pre-load per-date shift assignments so workday checks are O(1).
        $employee = User::with('shift')->find($employeeId);
        $assignments = EmployeeShiftSchedule::where('user_id', $employeeId)
            ->whereBetween('date', [$run->period_start->toDateString(), $run->period_end->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        // Warm the shift-assignment-history memo once so the per-date
        // WorkSchedule::isWorkday() calls in the cursor loop below stay O(1).
        WorkSchedule::preloadShiftAssignments([$employeeId]);

        $cursor = $run->period_start->copy();
        while ($cursor <= $run->period_end) {
            $isWorkday = $employee
                ? WorkSchedule::isWorkday($employee, $cursor, $assignments)
                : $cursor->isWeekday();

            if ($isWorkday) {
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
     * Compute mandatory government deductions. Each of GSIS/PhilHealth/Pag-IBIG
     * reads its own computation_type (flat/percentage/bracket) and mandatory_config
     * from its seeded Deduction catalog row - see computeMandatoryAmount() - so any
     * of the 3 can be reconfigured to a different formula shape entirely without a
     * code change. BIR withholding tax is *not* computed here at all - see
     * computeWithholdingTax() and "Replace computed BIR withholding tax with
     * an Accounting-uploaded monthly table"; the 'bir' key below is a
     * placeholder overwritten by the caller immediately after this returns.
     *
     * @param  Collection<string, Deduction>  $mandatoryTypes
     * @return array{gsis: float, philhealth: float, pagibig: float, bir: float, total: float}
     */
    protected function computeMandatoryDeductions(float $basicSalary, float $grossPay, Collection $mandatoryTypes, ?string $employeeType): array
    {
        $gsisRow = $mandatoryTypes->get('gsis');
        $philhealthRow = $mandatoryTypes->get('philhealth');
        $pagibigRow = $mandatoryTypes->get('pagibig');

        $gsis = $this->computeMandatoryAmount($gsisRow?->computation_type ?? 'percentage', $gsisRow?->mandatory_config ?? ['rate' => 0.09], $basicSalary, $this->mandatoryAppliesToEmployee($gsisRow, $employeeType));
        $philhealth = $this->computeMandatoryAmount($philhealthRow?->computation_type ?? 'percentage', $philhealthRow?->mandatory_config ?? ['rate' => 0.025, 'floor' => 400.00, 'ceiling' => 3750.00], $basicSalary, $this->mandatoryAppliesToEmployee($philhealthRow, $employeeType));
        $pagibig = $this->computeMandatoryAmount($pagibigRow?->computation_type ?? 'flat', $pagibigRow?->mandatory_config ?? ['amount' => 100.00], $basicSalary, $this->mandatoryAppliesToEmployee($pagibigRow, $employeeType));

        return [
            'gsis' => $gsis,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'bir' => 0.0,
            'total' => $gsis + $philhealth + $pagibig,
        ];
    }

    /**
     * Look up an employee's already-computed monthly withholding tax,
     * uploaded by the Payroll Manager from a figure Accounting computed
     * themselves - this LGU no longer runs BIR through a bracket engine at
     * all. Authoritative as uploaded: no is_active/eligible_employee_types
     * gate on top of it (see computeMandatoryDeductions()'s docblock).
     * Payroll runs are semi-monthly but withholding tax is uploaded once per
     * month, so the monthly figure is split evenly across however many
     * PayrollRuns share that same calendar month (by period_start) *at the
     * time this runs* - computing a run before its sibling exists gives it
     * the full amount; recompute after all of a month's runs are created to
     * correct the split. See "Replace computed BIR withholding tax with an
     * Accounting-uploaded monthly table".
     *
     * @return array{amount: float, found: bool}
     */
    protected function computeWithholdingTax(int $employeeId, PayrollRun $run): array
    {
        $record = WithholdingTax::where('employee_id', $employeeId)
            ->where('year', $run->period_start->year)
            ->where('month', $run->period_start->month)
            ->first();

        if (! $record) {
            return ['amount' => 0.0, 'found' => false];
        }

        $runsThisMonth = PayrollRun::whereYear('period_start', $run->period_start->year)
            ->whereMonth('period_start', $run->period_start->month)
            ->count();

        return ['amount' => round($record->amount / max(1, $runsThisMonth), 2), 'found' => true];
    }

    /**
     * Whether a mandatory row applies to a given employee - false if the row
     * is globally deactivated (see toggleActive()), or if eligible_employee_types
     * is set and doesn't include this employee's type (e.g. GSIS excluding
     * "Job Orders", who aren't civil-service/GSIS members). A missing row or
     * an unrestricted (null) eligible_employee_types both mean "applies to
     * everyone" - the same defensive/default-open behavior as before this
     * feature existed.
     */
    protected function mandatoryAppliesToEmployee(?Deduction $row, ?string $employeeType): bool
    {
        if (! $row) {
            return true;
        }

        if (! $row->is_active) {
            return false;
        }

        if (empty($row->eligible_employee_types)) {
            return true;
        }

        return in_array($employeeType, $row->eligible_employee_types, true);
    }

    /**
     * Generic mandatory-deduction evaluator, keyed on computation_type rather
     * than which government program the row represents - this is what lets
     * any of the 4 mandatory rows switch formula shape via the Deductions
     * page UI with no code change. A deactivated or type-ineligible
     * mandatory row always computes to 0 - unlike Loan/Other, there is no
     * per-employee assignment row to fall back on, so this takes effect
     * immediately on the next payroll run.
     */
    protected function computeMandatoryAmount(?string $computationType, array $config, float $base, bool $applies = true): float
    {
        if (! $applies) {
            return 0.0;
        }

        return match ($computationType) {
            'flat' => (float) ($config['amount'] ?? 0),
            'bracket' => $this->computeBracketAmount($base, $config['brackets'] ?? []),
            default => $this->clampAmount($base * (float) ($config['rate'] ?? 0), $config['floor'] ?? null, $config['ceiling'] ?? null),
        };
    }

    /**
     * Round then clamp to an optional floor/ceiling - either bound left null
     * means no clamp on that side (e.g. a plain percentage with no cap).
     */
    protected function clampAmount(float $amount, ?float $floor, ?float $ceiling): float
    {
        $amount = round($amount, 2);

        if ($floor !== null) {
            $amount = max($floor, $amount);
        }

        if ($ceiling !== null) {
            $amount = min($ceiling, $amount);
        }

        return (float) $amount;
    }

    /**
     * Apply a bracket/tiered table (e.g. BIR's progressive monthly tax
     * brackets) - reusable by any mandatory row set to computation_type
     * "bracket", not just BIR specifically.
     */
    protected function computeBracketAmount(float $base, array $brackets): float
    {
        foreach (array_reverse($brackets) as $bracket) {
            if ($base > $bracket['min']) {
                return round($bracket['base'] + ($base - $bracket['min']) * $bracket['rate'], 2);
            }
        }

        return 0.0;
    }

    /**
     * Sum active loan monthly payments, with a per-loan named breakdown for
     * payslip line items (label/provider come from the loan's own Deduction
     * catalog row, e.g. "Salary Loan" / "LBP").
     *
     * @return array{total: float, items: array<array{label: string, amount: float, provider: string|null}>}
     */
    protected function computeLoanDeductions(int $employeeId): array
    {
        $loans = Loan::with('deduction')
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->where('balance', '>', 0)
            ->get();

        $items = [];
        $total = 0.0;

        foreach ($loans as $loan) {
            $amount = (float) $loan->monthly_payment;
            $items[] = [
                'label' => $loan->deduction->type ?? 'Loan',
                'amount' => $amount,
                'provider' => $loan->deduction->provider ?? null,
            ];
            $total += $amount;
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Sum recurring, non-loan "other" deductions (e.g. insurance, GSIS MP2,
     * cellphone plan), with a per-item named breakdown for payslip line items.
     * Two independent sources, per-deduction-row mutually exclusive on
     * whether that row has been switched into Standing Rate mode:
     *
     * 1. Standing Rate rows ($autoRateTypes) - auto-computed for every
     *    eligible employee via the same generic evaluator Mandatory uses
     *    (computeMandatoryAmount()/mandatoryAppliesToEmployee()), no
     *    per-employee row involved. An ineligible employee_type is skipped
     *    entirely (₱0, omitted from the breakdown), identical to Mandatory.
     * 2. Individually Assigned rows - legacy per-employee EmployeeDeduction
     *    rows, but only for a deduction still in that mode (computation_type
     *    null); a deduction's old rows go dormant the moment it switches to
     *    Standing Rate, and resume automatically if switched back - see "Let
     *    'Other' deduction types use a standing per-type rate, like Mandatory".
     *
     * @param  Collection<int, Deduction>  $autoRateTypes
     * @return array{total: float, items: array<array{label: string, amount: float, provider: string|null}>}
     */
    protected function computeOtherDeductions(int $employeeId, float $basicSalary, Collection $autoRateTypes, ?string $employeeType): array
    {
        $items = [];
        $total = 0.0;

        foreach ($autoRateTypes as $row) {
            if (! $this->mandatoryAppliesToEmployee($row, $employeeType)) {
                continue;
            }

            $amount = $this->computeMandatoryAmount($row->computation_type, $row->mandatory_config ?? [], $basicSalary);
            $items[] = ['label' => $row->type, 'amount' => $amount, 'provider' => $row->provider ?? null];
            $total += $amount;
        }

        $rows = EmployeeDeduction::with('deduction')
            ->where('employee_id', $employeeId)
            ->where('recurring', true)
            ->whereHas('deduction', fn ($q) => $q->whereNull('computation_type'))
            ->get();

        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $items[] = [
                'label' => $row->deduction->type ?? 'Deduction',
                'amount' => $amount,
                'provider' => $row->deduction->provider ?? null,
            ];
            $total += $amount;
        }

        return ['total' => $total, 'items' => $items];
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
}
