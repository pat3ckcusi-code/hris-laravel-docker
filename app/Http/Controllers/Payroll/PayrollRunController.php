<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollAuditLog;
use App\Services\PayrollComputationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayrollRunController extends Controller
{
    public function index(Request $request): View
    {
        $runs = PayrollRun::with('creator', 'approver')
            ->latest()
            ->paginate(15);

        return view('payroll.runs', compact('runs'));
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

    public function show(int $id): View
    {
        $run = PayrollRun::with('details.employee', 'exceptions', 'approvalLogs.approver')
            ->findOrFail($id);

        return view('payroll.run-show', compact('run'));
    }

    public function compute(Request $request, int $id): RedirectResponse
    {
        $run = PayrollRun::findOrFail($id);

        if ($run->locked_at) {
            return back()->with('error', 'Cannot compute a locked payroll run.');
        }

        if (!$run->period_start || !$run->period_end) {
            return back()->with('error', 'Period start and end dates are required for computation.');
        }

        $service = new PayrollComputationService();
        $result = $service->compute($run, $request->user());

        if ($result['errors']) {
            return back()->with('error', 'Computation completed with exceptions: ' . implode('; ', $result['errors']));
        }

        return back()->with('status', "Payroll computed: {$result['employee_count']} employees processed.");
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
