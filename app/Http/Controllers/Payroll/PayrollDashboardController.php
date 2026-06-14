<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollAuditLog;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PayrollDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $totalRuns = PayrollRun::count();
        $pendingRuns = PayrollRun::where('status', 'draft')->count();
        $lockedRuns = PayrollRun::whereNotNull('locked_at')->count();
        $unresolvedExceptions = PayrollException::where('resolved_flag', false)->count();

        $recentRuns = PayrollRun::with('creator')
            ->latest()
            ->take(5)
            ->get();

        $recentAudit = PayrollAuditLog::with('user')
            ->latest()
            ->take(10)
            ->get();

        return view('payroll.dashboard', compact(
            'totalRuns',
            'pendingRuns',
            'lockedRuns',
            'unresolvedExceptions',
            'recentRuns',
            'recentAudit',
        ));
    }
}
