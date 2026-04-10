<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeaveIntegrationController extends Controller
{
    public function index(Request $request): View
    {
        $query = LeaveRequest::with('user')
            ->whereIn('status', ['approved', 'Approved'])
            ->latest('start_date');

        if ($request->filled('employee_id')) {
            $query->where('user_id', $request->employee_id);
        }
        if ($request->filled('lwop_only')) {
            $query->where('lwop_days', '>', 0);
        }
        if ($request->filled('leave_type')) {
            $query->where('leave_type', $request->leave_type);
        }

        $records = $query->paginate(20);
        $employees = User::orderBy('last_name')->get();

        return view('payroll.leave-integration', compact('records', 'employees'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.leave-integration.index');
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('payroll.leave-integration.index')
            ->with('status', 'Leave records are synced automatically from Leave Manager.');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.leave-integration.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.leave-integration.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return redirect()->route('payroll.leave-integration.index');
    }

    public function destroy(int $id): RedirectResponse
    {
        return redirect()->route('payroll.leave-integration.index');
    }
}
