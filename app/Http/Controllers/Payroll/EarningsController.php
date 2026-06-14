<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    public function index(): View
    {
        $earningTypes = Earning::withCount('employeeEarnings')->paginate(20);

        return view('payroll.earnings', compact('earningTypes'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.earnings.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'recurring' => 'boolean',
        ]);

        Earning::create($request->only('type', 'description', 'recurring'));

        return redirect()->route('payroll.earnings.index')
            ->with('status', 'Earning type created.');
    }

    public function show(int $id): View
    {
        $earning = Earning::with('employeeEarnings.employee')->findOrFail($id);

        return view('payroll.earning-show', compact('earning'));
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.earnings.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'recurring' => 'boolean',
        ]);

        Earning::findOrFail($id)->update($request->only('type', 'description', 'recurring'));

        return redirect()->route('payroll.earnings.index')
            ->with('status', 'Earning type updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Earning::findOrFail($id)->delete();

        return redirect()->route('payroll.earnings.index')
            ->with('status', 'Earning type deleted.');
    }
}
