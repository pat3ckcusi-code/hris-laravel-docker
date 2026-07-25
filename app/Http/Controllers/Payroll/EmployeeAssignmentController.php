<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAssignment;
use App\Models\HRAuditTrail;
use App\Models\Plantilla;
use App\Models\User;
use App\Services\EmployeeAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeAssignmentController extends Controller
{
    public function __construct(private EmployeeAssignmentService $employeeAssignmentService) {}

    public function store(Request $request, int $plantillaId): RedirectResponse
    {
        $plantilla = Plantilla::findOrFail($plantillaId);

        $request->validate([
            'employee_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($plantilla->is_abolished) {
            return redirect()->route('payroll.plantilla.show', $plantilla->id)
                ->with('error', 'This position has been abolished and cannot accept new assignments.');
        }

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
            $newStart = Carbon::parse($request->start_date);
            $priorOpen = EmployeeAssignment::where('employee_id', $request->employee_id)
                ->notEnded()
                ->get();
            $replaced = $priorOpen->count();

            foreach ($priorOpen as $prior) {
                if ($prior->start_date->equalTo($newStart)) {
                    // Being corrected before it ever took effect - delete outright
                    // rather than leave an inverted (end < start) range behind.
                    $prior->delete();
                } else {
                    // Otherwise truncate as usual - if this row's own start_date is
                    // still in the future, this intentionally produces an inverted
                    // range, kept for history (see EmployeeAssignment::isSuperseded()).
                    $prior->update(['end_date' => $newStart->copy()->subDay()->toDateString()]);
                }
            }
        }

        EmployeeAssignment::create([
            'employee_id' => $request->employee_id,
            'plantilla_id' => $plantilla->id,
            // A new incumbency always starts at step 1 - the plantilla's own
            // step is a fixed position attribute, not the new hire's earned step.
            'step' => 1,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        $this->employeeAssignmentService->syncUserSalary((int) $request->employee_id);

        $this->logAssignmentAction('assignment_created', (int) $request->employee_id, $plantilla, [
            'step' => 1,
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

        if ($target->is_abolished) {
            return redirect()->route('payroll.plantilla.index')
                ->with('error', 'The selected position has been abolished and is not available for assignment.');
        }

        if ($target->activeAssignments()->exists()) {
            return redirect()->route('payroll.plantilla.index')
                ->with('error', 'The selected position already has an active incumbent.');
        }

        $current = EmployeeAssignment::where('employee_id', $employee->id)
            ->notEnded()
            ->with('plantilla')
            ->get();

        if ($current->contains('plantilla_id', $target->id)) {
            return redirect()->route('payroll.plantilla.index')
                ->with('error', 'The employee already holds this position.');
        }

        $effective = Carbon::parse($request->effective_date);
        $from = $current->first()?->plantilla;
        // The prior assignment's own (personal, possibly step-incremented)
        // step - not the departing plantilla item's budgeted step.
        $fromStep = $current->first()?->step;

        DB::transaction(function () use ($current, $target, $employee, $effective, $from, $fromStep) {
            foreach ($current as $assignment) {
                if ($assignment->start_date->equalTo($effective)) {
                    // Being corrected before it ever took effect - delete outright
                    // rather than leave an inverted (end < start) range behind.
                    $assignment->delete();
                } else {
                    // Otherwise truncate as usual - if this row's own start_date is
                    // still in the future, this intentionally produces an inverted
                    // range, kept for history (see EmployeeAssignment::isSuperseded()).
                    $assignment->update(['end_date' => $effective->copy()->subDay()->toDateString()]);
                }
            }

            EmployeeAssignment::create([
                'employee_id' => $employee->id,
                'plantilla_id' => $target->id,
                'step' => 1,
                'start_date' => $effective->toDateString(),
            ]);

            // Query-builder update: designation is intentionally not mass-assignable.
            // salary_step always resets to 1 on promotion - it's the employee's own
            // earned step in the new position, not whatever the target item's
            // fixed step value happens to be.
            User::where('id', $employee->id)->update([
                'salary_grade' => $target->salary_grade,
                'salary_step' => 1,
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
                        'step' => $fromStep,
                    ] : null,
                    'to' => [
                        'item_number' => $target->item_number,
                        'title' => $target->title,
                        'salary_grade' => $target->salary_grade,
                        // A new assignment always starts at step 1 (see
                        // EmployeeAssignment::create() above) - never the
                        // target position's own budgeted step.
                        'step' => 1,
                    ],
                    'effective_date' => $effective->toDateString(),
                ],
            ]);
        });

        return redirect()->route('payroll.plantilla.show', $target->id)
            ->with('status', sprintf(
                '%s promoted to %s (SG %d, Step 1) effective %s.',
                $employee->name,
                $target->title,
                $target->salary_grade,
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
                'step' => $plantilla->step,
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

        $request->validateWithBag('editAssignment', [
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            // The employee's own step for this stint - e.g. granting a step
            // increment - distinct from the plantilla item's own catalog step.
            'step' => 'required|integer|min:1|max:8',
        ]);

        $assignment->update($request->only('start_date', 'end_date', 'step'));

        $this->employeeAssignmentService->syncUserSalary($assignment->employee_id);
        $this->logAssignmentAction('assignment_updated', $assignment->employee_id, $plantilla, [
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'step' => $request->step,
        ]);

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', 'Assignment updated.');
    }

    public function destroy(int $plantillaId, int $id): RedirectResponse
    {
        $plantilla = Plantilla::findOrFail($plantillaId);
        $assignment = EmployeeAssignment::where('plantilla_id', $plantilla->id)->findOrFail($id);

        $employeeId = $assignment->employee_id;
        $removedStep = $assignment->step;
        $assignment->delete();

        $this->employeeAssignmentService->syncUserSalary($employeeId);
        $this->logAssignmentAction('assignment_removed', $employeeId, $plantilla, [
            'step' => $removedStep,
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
        ]);

        return redirect()->route('payroll.plantilla.show', $plantilla->id)
            ->with('status', 'Assignment removed.');
    }

    private function logAssignmentAction(string $action, int $employeeId, Plantilla $plantilla, array $details): void
    {
        // No 'step' fallback here - the plantilla's own step is a budgeted
        // catalog value, not what actually happened to the employee's
        // assignment. Every caller must supply its own accurate 'step'.
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
            ],
        ]);
    }
}
