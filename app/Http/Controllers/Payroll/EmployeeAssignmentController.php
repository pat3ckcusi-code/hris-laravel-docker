<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAssignment;
use App\Models\Plantilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeAssignmentController extends Controller
{
    public function store(Request $request, int $plantillaId): RedirectResponse
    {
        $plantilla = Plantilla::findOrFail($plantillaId);

        $request->validate([
            'employee_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Prevent duplicate active assignment for the same employee to the same plantilla
        $exists = EmployeeAssignment::where('employee_id', $request->employee_id)
            ->where('plantilla_id', $plantilla->id)
            ->whereNull('end_date')
            ->exists();

        if ($exists) {
            return redirect()->route('payroll.plantilla.show', $plantilla->id)
                ->with('error', 'Employee already has an active assignment to this position.');
        }

        EmployeeAssignment::create([
            'employee_id' => $request->employee_id,
            'plantilla_id' => $plantilla->id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', 'Employee assigned successfully.');
    }

    public function update(Request $request, int $plantillaId, int $id): RedirectResponse
    {
        $plantilla = Plantilla::findOrFail($plantillaId);
        $assignment = EmployeeAssignment::where('plantilla_id', $plantilla->id)->findOrFail($id);

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $assignment->update($request->only('start_date', 'end_date'));

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', 'Assignment updated.');
    }

    public function destroy(int $plantillaId, int $id): RedirectResponse
    {
        $plantilla = Plantilla::findOrFail($plantillaId);
        $assignment = EmployeeAssignment::where('plantilla_id', $plantilla->id)->findOrFail($id);

        $assignment->delete();

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', 'Assignment removed.');
    }
}
