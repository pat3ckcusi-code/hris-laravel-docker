<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollAuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = PayrollAuditLog::with('user', 'payrollRun')->latest();

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $logs = $query->paginate(25);
        $actions = PayrollAuditLog::select('action')->distinct()->pluck('action');

        return view('payroll.audit-logs', compact('logs', 'actions'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.audit-logs.index');
    }

    public function store(Request $request)
    {
        return redirect()->route('payroll.audit-logs.index');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.audit-logs.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.audit-logs.index');
    }

    public function update(Request $request, int $id)
    {
        return redirect()->route('payroll.audit-logs.index');
    }

    public function destroy(int $id)
    {
        return redirect()->route('payroll.audit-logs.index');
    }
}
