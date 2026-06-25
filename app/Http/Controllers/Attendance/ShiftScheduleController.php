<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeShiftSchedule;
use App\Models\Shift;
use App\Models\User;
use App\Services\PersonnelLogImportService;
use App\Support\RoleNormalizer;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Time Keeper / HR Manager screen for setting per-date shift assignments.
 *
 * An employee's "shift schedule" overrides their default shift_id on specific
 * dates. A null shift_id in an assignment means the employee is scheduled off
 * (rest day) — the DTR pipeline skips them, and payroll does not count the
 * day as absent.
 */
class ShiftScheduleController extends Controller
{
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    public function __construct(
        private readonly PersonnelLogImportService $importService,
    ) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $departments = Department::orderBy('Dept_name')->get();
        $shifts = Shift::where('is_active', true)->orderBy('name')->get();

        $deptId = $request->integer('dept_id') ?: null;
        $employeeId = $request->integer('employee_id') ?: null;

        // Week start: Monday of the requested week, defaults to current week's Monday.
        $weekStart = $request->query('week_start')
            ? Carbon::parse($request->query('week_start'))->startOfWeek(Carbon::MONDAY)
            : Carbon::now()->startOfWeek(Carbon::MONDAY);

        $employees = User::query()
            ->where('dtr_exempt', false)
            ->when($deptId, fn ($q) => $q->where('Dept_id', $deptId))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'middle_name', 'Dept_id', 'shift_id']);

        // Build the 7 days of the selected week.
        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $weekDays[] = $weekStart->copy()->addDays($i);
        }

        // Load existing assignments for the selected employee + week.
        $selectedEmployee = $employeeId ? $employees->firstWhere('id', $employeeId) : null;
        $existingAssignments = collect();

        if ($selectedEmployee) {
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $existingAssignments = EmployeeShiftSchedule::where('user_id', $selectedEmployee->id)
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->with('shift')
                ->get()
                ->keyBy(fn ($a) => $a->date->toDateString());
        }

        return view('attendance.shift_schedule.index', compact(
            'departments', 'shifts', 'employees', 'deptId', 'employeeId',
            'weekStart', 'weekDays', 'selectedEmployee', 'existingAssignments'
        ));
    }

    /**
     * Save per-date shift assignments for one employee for a week.
     *
     * Each day's value is one of:
     *   - a numeric shift_id  → assign that shift
     *   - 'rest'              → mark as rest day (shift_id null, type 'rest')
     *   - 'field_work'        → mark as field work (shift_id null, type 'field_work')
     *   - 'default' or ''     → remove any assignment (fall back to user's default shift)
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager($request->user());

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'week_start' => ['required', 'date'],
            'assignments' => ['required', 'array'],
            'assignments.*' => ['nullable', 'string'],
        ]);

        $employee = User::findOrFail($validated['user_id']);
        $weekStart = Carbon::parse($validated['week_start'])->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        // Validate that all submitted dates fall within the selected week.
        $validDates = collect(range(0, 6))->map(fn ($i) => $weekStart->copy()->addDays($i)->toDateString());

        $recomputeNeeded = false;

        foreach ($validated['assignments'] as $dateStr => $value) {
            if (! $validDates->contains($dateStr)) {
                continue;
            }

            if ($value === 'default' || $value === '') {
                // Remove the override — employee reverts to their default shift.
                $deleted = EmployeeShiftSchedule::where('user_id', $employee->id)
                    ->where('date', $dateStr)
                    ->delete();
                if ($deleted) {
                    $recomputeNeeded = true;
                }
            } elseif ($value === 'rest') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'rest', 'created_by' => $request->user()->id]
                );
                $recomputeNeeded = true;
            } elseif ($value === 'field_work') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'field_work', 'created_by' => $request->user()->id]
                );
                $recomputeNeeded = true;
            } else {
                $shiftId = (int) $value;
                abort_unless(Shift::where('id', $shiftId)->exists(), 422, 'Invalid shift.');
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => $shiftId, 'created_by' => $request->user()->id]
                );
                $recomputeNeeded = true;
            }
        }

        // Recompute DTRs for the affected week so stored late/undertime update immediately.
        if ($recomputeNeeded) {
            $this->importService->recomputeDtr(
                $employee,
                $weekStart->toDateString(),
                $weekEnd->toDateString(),
            );
        }

        $name = trim("{$employee->first_name} {$employee->last_name}");

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
            'employee_id' => $employee->id,
            'week_start' => $weekStart->toDateString(),
        ])->with('schedule_status', "Shift schedule for {$name} saved.");
    }
}
