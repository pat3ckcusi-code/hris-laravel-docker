<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\ApprovalLog;
use App\Models\PayrollAuditLog;
use App\Models\PayrollRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApprovalsController extends Controller
{
    public function index(): View
    {
        $logs = ApprovalLog::with('payrollRun', 'approver')
            ->latest()
            ->paginate(20);

        $pendingRuns = PayrollRun::where('status', 'computed')
            ->whereNull('approved_by')
            ->get();

        return view('payroll.approvals', compact('logs', 'pendingRuns'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.approvals.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'payroll_run_id' => 'required|exists:payroll_runs,id',
            'status' => 'required|in:approved,rejected',
        ]);

        $run = PayrollRun::findOrFail($request->payroll_run_id);

        ApprovalLog::create([
            'payroll_run_id' => $run->id,
            'approver_id' => $request->user()->id,
            'status' => $request->status,
            'actioned_at' => now(),
        ]);

        if ($request->status === 'approved') {
            $run->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
            ]);
        } else {
            $run->update(['status' => 'rejected']);
        }

        PayrollAuditLog::create([
            'action' => "payroll_{$request->status}",
            'user_id' => $request->user()->id,
            'payroll_run_id' => $run->id,
            'details' => "Payroll run {$request->status} by approver.",
            'actioned_at' => now(),
        ]);

        return redirect()->route('payroll.approvals.index')
            ->with('status', "Payroll run {$request->status}.");
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.approvals.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.approvals.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return redirect()->route('payroll.approvals.index');
    }

    public function destroy(int $id): RedirectResponse
    {
        return redirect()->route('payroll.approvals.index');
    }
}
