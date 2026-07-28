<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Deduction;
use App\Models\PayrollAuditLog;
use App\Models\PayrollRun;
use App\Services\PayrollComputationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PayrollRunController extends Controller
{
    public function index(Request $request): View
    {
        $runs = PayrollRun::with('creator')
            ->latest()
            ->paginate(15);

        $stats = [
            'total' => PayrollRun::count(),
            'draft' => PayrollRun::where('status', 'draft')->count(),
            'computed' => PayrollRun::where('status', 'computed')->count(),
            'locked' => PayrollRun::where('status', 'locked')->count(),
        ];

        return view('payroll.runs', compact('runs', 'stats'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.runs.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'period' => 'required|string|max:100',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        $run = PayrollRun::create([
            'period' => $request->period,
            'period_start' => $request->period_start,
            'period_end' => $request->period_end,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        PayrollAuditLog::create([
            'action' => 'payroll_run_created',
            'user_id' => $request->user()->id,
            'payroll_run_id' => $run->id,
            'details' => "Payroll run created for period: {$run->period}",
            'actioned_at' => now(),
        ]);

        return redirect()->route('payroll.runs.show', $run->id)
            ->with('status', 'Payroll run created successfully.');
    }

    public function show(Request $request, int $id): View
    {
        $run = PayrollRun::with('details.employee.department', 'exceptions')
            ->findOrFail($id);

        $search = trim((string) $request->query('search', ''));
        $selectedDepartment = (string) $request->query('department', '');

        $filteredDetails = $run->details->filter(function ($detail) use ($search, $selectedDepartment) {
            $employee = $detail->employee;

            if ($search !== '') {
                if (! $employee) {
                    return false;
                }
                $haystack = strtolower(($employee->name ?? '').' '.($employee->EmpNo ?? ''));
                if (! str_contains($haystack, strtolower($search))) {
                    return false;
                }
            }

            if ($selectedDepartment !== '' && (string) ($employee->Dept_id ?? '') !== $selectedDepartment) {
                return false;
            }

            return true;
        })->values();

        $perPage = 20;
        $page = max((int) $request->query('page', 1), 1);

        $details = new LengthAwarePaginator(
            $filteredDetails->forPage($page, $perPage)->values(),
            $filteredDetails->count(),
            $perPage,
            $page
        );
        $details->withQueryString();

        $departments = $run->details
            ->pluck('employee.department')
            ->filter()
            ->unique('Dept_id')
            ->sortBy('Dept_name')
            ->values();

        // "Other" category deductions each get their own named column in the
        // Payroll Details table, exactly like GSIS/PhilHealth/Pag-IBIG/BIR -
        // not one generic "Other" total. Every such type in the catalog gets
        // a column regardless of whether it applies to any employee shown
        // here, matching how the 4 mandatory columns always appear too.
        $otherDeductionTypes = Deduction::where('deduction_category', 'other')->orderBy('type')->get(['id', 'type']);

        return view('payroll.run-show', compact('run', 'details', 'search', 'selectedDepartment', 'departments', 'otherDeductionTypes'));
    }

    public function compute(Request $request, int $id): RedirectResponse
    {
        $run = PayrollRun::findOrFail($id);

        if ($run->locked_at) {
            return back()->with('error', 'Cannot compute a locked payroll run.');
        }

        if (! $run->period_start || ! $run->period_end) {
            return back()->with('error', 'Period start and end dates are required for computation.');
        }

        $service = new PayrollComputationService;
        $result = $service->compute($run, $request->user());

        if ($result['errors']) {
            return back()->with('error', 'Computation completed with exceptions: '.implode('; ', $result['errors']));
        }

        return back()->with('computed_summary', [
            'count' => $result['employee_count'],
            'period' => $run->period,
        ]);
    }

    public function lock(Request $request, int $id): RedirectResponse
    {
        $run = PayrollRun::findOrFail($id);

        $run->update([
            'status' => 'locked',
            'locked_at' => now(),
        ]);

        PayrollAuditLog::create([
            'action' => 'payroll_locked',
            'user_id' => $request->user()->id,
            'payroll_run_id' => $run->id,
            'details' => 'Payroll run locked.',
            'actioned_at' => now(),
        ]);

        return back()->with('status', 'Payroll run has been locked.');
    }

    public function export(int $id)
    {
        $run = PayrollRun::with('details.employee')->findOrFail($id);

        // Placeholder: export logic (CSV/Excel)
        return back()->with('status', 'Export feature coming soon.');
    }
}
