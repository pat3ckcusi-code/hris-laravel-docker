<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollDetail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index(Request $request): View
    {
        $runs = PayrollRun::orderByDesc('id')->get();

        $selectedRun = null;
        $summary = null;

        if ($request->filled('payroll_run_id')) {
            $selectedRun = PayrollRun::with('details.employee')->findOrFail($request->payroll_run_id);
            $summary = [
                'total_basic' => $selectedRun->details->sum('basic_salary'),
                'total_earnings' => $selectedRun->details->sum('earnings'),
                'total_deductions' => $selectedRun->details->sum('deductions'),
                'total_net' => $selectedRun->details->sum('net_pay'),
                'employee_count' => $selectedRun->details->count(),
            ];
        }

        return view('payroll.reports', compact('runs', 'selectedRun', 'summary'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.reports.index');
    }

    public function store(Request $request)
    {
        return redirect()->route('payroll.reports.index')
            ->with('status', 'Report generation coming soon.');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.reports.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.reports.index');
    }

    public function update(Request $request, int $id)
    {
        return redirect()->route('payroll.reports.index');
    }

    public function destroy(int $id)
    {
        return redirect()->route('payroll.reports.index');
    }
}
