<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAssignment;
use App\Models\HRAuditTrail;
use App\Models\Plantilla;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        // A plantilla item holds one incumbent at a time (historical,
        // already-ended assignments may still be recorded freely).
        if (! $request->filled('end_date') && $plantilla->activeAssignments()->exists()) {
            return redirect()->route('payroll.plantilla.show', $plantilla->id)
                ->with('error', 'This position already has an active incumbent. End that assignment first.');
        }

        // One active assignment per employee: end any other active assignment
        // the day before the new one starts, or payroll would pay them twice.
        $replaced = 0;

        if (! $request->filled('end_date')) {
            $replaced = EmployeeAssignment::where('employee_id', $request->employee_id)
                ->whereNull('end_date')
                ->update(['end_date' => Carbon::parse($request->start_date)->subDay()->toDateString()]);
        }

        EmployeeAssignment::create([
            'employee_id' => $request->employee_id,
            'plantilla_id' => $plantilla->id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        $this->syncUserSalary((int) $request->employee_id);
        $this->logAssignmentAction('assignment_created', (int) $request->employee_id, $plantilla, [
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'previous_assignment_ended' => (bool) $replaced,
        ]);

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', $replaced
                ? 'Employee assigned; their previous active assignment was ended automatically.'
                : 'Employee assigned successfully.');
    }

    /**
     * One-click promotion: move an employee to a vacant plantilla item,
     * ending their current assignment the day before the effectivity date.
     * Unlike a plain assignment, this also updates the official designation.
     */
    public function promote(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => 'required|exists:users,id',
            'plantilla_id' => 'required|exists:plantillas,id',
            'effective_date' => 'required|date',
        ]);

        $target = Plantilla::findOrFail($request->plantilla_id);
        $employee = User::findOrFail($request->employee_id);

        if ($target->activeAssignments()->exists()) {
            return redirect()->route('payroll.plantilla.index')
                ->with('error', 'The selected position already has an active incumbent.');
        }

        $current = EmployeeAssignment::where('employee_id', $employee->id)
            ->whereNull('end_date')
            ->with('plantilla')
            ->get();

        if ($current->contains('plantilla_id', $target->id)) {
            return redirect()->route('payroll.plantilla.index')
                ->with('error', 'The employee already holds this position.');
        }

        $effective = Carbon::parse($request->effective_date);
        $from = $current->first()?->plantilla;

        DB::transaction(function () use ($current, $target, $employee, $effective, $from) {
            foreach ($current as $assignment) {
                $assignment->update(['end_date' => $effective->copy()->subDay()->toDateString()]);
            }

            EmployeeAssignment::create([
                'employee_id' => $employee->id,
                'plantilla_id' => $target->id,
                'start_date' => $effective->toDateString(),
            ]);

            // Query-builder update: designation is intentionally not mass-assignable
            User::where('id', $employee->id)->update([
                'salary_grade' => $target->salary_grade,
                'salary_step' => $target->step,
                'designation' => $target->title,
                'date_of_last_promotion' => $effective->toDateString(),
            ]);

            HRAuditTrail::create([
                'actor_user_id' => auth()->id(),
                'module' => 'payroll',
                'action' => 'promotion',
                'target_type' => User::class,
                'target_id' => $employee->id,
                'details' => [
                    'from' => $from ? [
                        'item_number' => $from->item_number,
                        'title' => $from->title,
                        'salary_grade' => $from->salary_grade,
                        'step' => $from->step,
                    ] : null,
                    'to' => [
                        'item_number' => $target->item_number,
                        'title' => $target->title,
                        'salary_grade' => $target->salary_grade,
                        'step' => $target->step,
                    ],
                    'effective_date' => $effective->toDateString(),
                ],
            ]);
        });

        return redirect()->route('payroll.plantilla.show', $target->id)
            ->with('status', sprintf(
                '%s promoted to %s (SG %d Step %d) effective %s.',
                $employee->name,
                $target->title,
                $target->salary_grade,
                $target->step,
                $effective->format('M d, Y')
            ));
    }

    /**
     * Record a position an employee held before the FY2026 plantilla
     * baseline. Creates its own plantilla item (no CSC item number - it's
     * not part of the current roster) and an already-ended assignment, so
     * it fills in the Service Trail without touching the employee's current
     * active assignment or synced salary.
     */
    public function storeHistorical(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'salary_grade' => 'required|integer|min:1|max:33',
            'step' => 'required|integer|min:1|max:8',
            'employment_type' => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        DB::transaction(function () use ($request) {
            $plantilla = Plantilla::create($request->only('title', 'department', 'salary_grade', 'step', 'employment_type') + [
                'is_historical' => true,
            ]);

            EmployeeAssignment::create([
                'employee_id' => $request->employee_id,
                'plantilla_id' => $plantilla->id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ]);

            HRAuditTrail::create([
                'actor_user_id' => auth()->id(),
                'module' => 'payroll',
                'action' => 'historical_assignment_added',
                'target_type' => User::class,
                'target_id' => $request->employee_id,
                'details' => [
                    'title' => $plantilla->title,
                    'department' => $plantilla->department,
                    'salary_grade' => $plantilla->salary_grade,
                    'step' => $plantilla->step,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                ],
            ]);
        });

        return redirect()->route('payroll.plantilla.service-trail', ['employee_id' => $request->employee_id])
            ->with('status', 'Past position added to the service trail.');
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

        $this->syncUserSalary($assignment->employee_id);
        $this->logAssignmentAction('assignment_updated', $assignment->employee_id, $plantilla, [
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', 'Assignment updated.');
    }

    public function destroy(int $plantillaId, int $id): RedirectResponse
    {
        $plantilla = Plantilla::findOrFail($plantillaId);
        $assignment = EmployeeAssignment::where('plantilla_id', $plantilla->id)->findOrFail($id);

        $employeeId = $assignment->employee_id;
        $assignment->delete();

        $this->syncUserSalary($employeeId);
        $this->logAssignmentAction('assignment_removed', $employeeId, $plantilla, [
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
        ]);

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', 'Assignment removed.');
    }

    private function logAssignmentAction(string $action, int $employeeId, Plantilla $plantilla, array $details): void
    {
        HRAuditTrail::create([
            'actor_user_id' => auth()->id(),
            'module' => 'payroll',
            'action' => $action,
            'target_type' => User::class,
            'target_id' => $employeeId,
            'details' => $details + [
                'item_number' => $plantilla->item_number,
                'title' => $plantilla->title,
                'salary_grade' => $plantilla->salary_grade,
                'step' => $plantilla->step,
            ],
        ]);
    }

    /**
     * Keep the denormalized users.salary_grade/salary_step in step with the
     * employee's remaining active assignment (or clear them when none).
     */
    private function syncUserSalary(int $employeeId): void
    {
        $active = EmployeeAssignment::where('employee_id', $employeeId)
            ->whereNull('end_date')
            ->with('plantilla')
            ->orderByDesc('start_date')
            ->first();

        User::where('id', $employeeId)->update([
            'salary_grade' => $active?->plantilla?->salary_grade,
            'salary_step' => $active?->plantilla?->step ?? 1,
        ]);
    }
}
