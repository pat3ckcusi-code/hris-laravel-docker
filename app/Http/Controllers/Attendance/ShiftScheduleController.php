<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\ScopesEmployeesByDepartment;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeShiftSchedule;
use App\Models\HRAuditTrail;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use App\Support\RoleNormalizer;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Time Keeper / HR Manager screen for setting per-date shift assignments.
 * Department Head / Administrative Officer get the same tool once granted
 * shift management access, scoped to their own department's employees.
 *
 * An employee's "shift schedule" overrides their default shift_id on specific
 * dates. A null shift_id in an assignment means the employee is scheduled off
 * (rest day) - the DTR pipeline skips them, and payroll does not count the
 * day as absent.
 */
class ShiftScheduleController extends Controller
{
    use ScopesEmployeesByDepartment;

    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    public function __construct(
        private readonly PersonnelLogImportService $importService,
        private readonly DepartmentService $departmentService,
        private readonly ShiftAssignmentService $shiftAssignmentService,
    ) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($user);

        $shifts = $this->resolveVisibleShiftsQuery($user)->where('is_active', true)->orderBy('name')->get();

        $deptId = $request->integer('dept_id') ?: null;
        $employeeId = $request->integer('employee_id') ?: null;

        if ($accessibleIds === null) {
            $departments = Department::orderBy('Dept_name')->get();
            $lockedDepartments = null;
        } else {
            $departments = collect();
            $lockedDepartments = $this->resolveAccessibleDepartments($user);
            if ($deptId !== null && ! $lockedDepartments->pluck('Dept_id')->contains($deptId)) {
                $deptId = null;
            }
        }

        // Week start: Monday of the requested week, defaults to current week's Monday.
        $weekStart = $request->query('week_start')
            ? Carbon::parse($request->query('week_start'))->startOfWeek(Carbon::MONDAY)
            : Carbon::now()->startOfWeek(Carbon::MONDAY);

        $employees = User::active()
            ->where('dtr_exempt', false)
            ->when($accessibleIds !== null, fn ($q) => $q->whereIn('id', $accessibleIds))
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
        $activeAssignments = collect();

        if ($selectedEmployee) {
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $existingAssignments = EmployeeShiftSchedule::where('user_id', $selectedEmployee->id)
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->with('shift')
                ->get()
                ->keyBy(fn ($a) => $a->date->toDateString());

            // Every not-yet-expired shift_assignments row - currently active
            // AND future-dated ones, so a newly added shift shows up even
            // before its effective_from starts (or if it's the second half
            // of a day-scoped combo, e.g. an MWF shift + a TTH shift, that
            // "Default shift: X" alone can't represent). Only fully-expired
            // history is left out. Used solely for the "current shifts"
            // summary list at the top of the page - intentionally NOT for
            // resolving what a given day in the grid defaults to (see
            // $assignmentHistory below), since a past week can be fully
            // covered by a row that has since expired relative to today.
            $activeAssignments = ShiftAssignment::forUser($selectedEmployee->id)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', Carbon::today()->toDateString()))
                ->with('shift')
                ->orderBy('effective_from')
                ->get();

            // The employee's FULL shift-assignment history, unfiltered by
            // today's date - mirrors what WorkSchedule::isWorkday()/
            // resolveShiftAssignment() actually query, so resolving what a
            // given day in the grid defaults to stays correct even when
            // viewing a past week that a now-expired row covered.
            $assignmentHistory = ShiftAssignment::forUser($selectedEmployee->id)
                ->with('shift')
                ->orderBy('effective_from')
                ->get();
        }

        // What a day with no explicit EmployeeShiftSchedule override actually
        // resolves to, per the employee's Shift Assignment history - both a
        // display label and the dropdown value it should pre-select, so an
        // un-overridden day visibly shows (and has selected) the real shift
        // in effect rather than a generic "Default" placeholder.
        $resolvedDefaults = [];
        if ($selectedEmployee) {
            foreach ($weekDays as $day) {
                $resolvedDefaults[$day->toDateString()] = $this->resolveDefaultValue($selectedEmployee, $day, $assignmentHistory);
            }
        }

        return view('attendance.shift_schedule.index', compact(
            'departments', 'lockedDepartments', 'shifts', 'employees', 'deptId', 'employeeId',
            'weekStart', 'weekDays', 'selectedEmployee', 'existingAssignments', 'activeAssignments', 'resolvedDefaults'
        ));
    }

    /**
     * The shift (or rest-day/standard-day state) that WorkSchedule would
     * resolve to for $employee on $date, in the absence of an
     * EmployeeShiftSchedule override. Mirrors WorkSchedule::isWorkday()/
     * forUserOnDate()'s own precedence (day-of-week-scoped shift_assignments
     * history, then the employee's cached shift_id).
     *
     * $assignmentHistory must be the employee's FULL, unfiltered
     * shift_assignments history (not a "not-yet-expired as of today"
     * collection) - $date can be in the past relative to today, and a row
     * that has since expired can still be the one that correctly covered it.
     *
     * Returns both a display label and the dropdown 'value' the week grid
     * should pre-select for an un-overridden date, so the select shows the
     * real shift actually in effect on that day (not a generic "Default"
     * option) - saving the week unchanged then writes that as an explicit
     * per-date row, same as picking it manually would.
     *
     * @return array{label: string, value: string}
     */
    private function resolveDefaultValue(User $employee, Carbon $date, Collection $assignmentHistory): array
    {
        if (! WorkSchedule::isWorkday($employee, $date)) {
            return ['label' => 'Rest day', 'value' => 'rest'];
        }

        $covering = $assignmentHistory->first(fn (ShiftAssignment $row) => $row->effective_from->lte($date)
            && ($row->effective_until === null || $row->effective_until->gte($date))
            && $row->appliesOnDate($date));

        if ($covering !== null) {
            return $covering->shift_id !== null
                ? ['label' => $covering->shift->name, 'value' => (string) $covering->shift_id]
                : ['label' => 'Standard Day', 'value' => 'standard'];
        }

        return $employee->shift_id !== null
            ? ['label' => $employee->shift?->name ?? 'Standard Day', 'value' => (string) $employee->shift_id]
            : ['label' => 'Standard Day', 'value' => 'standard'];
    }

    /**
     * Save per-date shift assignments for one employee for a week.
     *
     * Each day's value is one of:
     *   - a numeric shift_id  → assign that shift
     *   - 'rest'              → mark as rest day (shift_id null, type 'rest')
     *   - 'field_work'        → mark as field work (shift_id null, type 'field_work')
     *   - 'standard'          → force Standard Day for this date (shift_id null, type
     *                           'standard'), overriding whatever the Shift Assignment
     *                           history would otherwise resolve to for that date
     *   - 'default' or ''     → remove any assignment (fall back to user's default shift)
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'week_start' => ['required', 'date'],
            'assignments' => ['required', 'array'],
            'assignments.*' => ['nullable', 'string'],
        ]);

        if ($accessibleIds !== null && ! in_array((int) $validated['user_id'], $accessibleIds, true)) {
            abort(403, 'You may only schedule employees in your own department.');
        }

        $employee = User::findOrFail($validated['user_id']);
        $weekStart = Carbon::parse($validated['week_start'])->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $validDates = collect(range(0, 6))->map(fn ($i) => $weekStart->copy()->addDays($i)->toDateString());

        $recomputeNeeded = $this->applyWeekAssignments($employee, $validated['assignments'], $validDates, $actor);

        // Recompute DTRs for the affected week so stored late/undertime update immediately.
        if ($recomputeNeeded) {
            $this->importService->recomputeDtr(
                $employee,
                $weekStart->toDateString(),
                $weekEnd->toDateString(),
            );

            $this->logScheduleAction($actor, $employee, 'shift_schedule_updated', [
                'week_start' => $weekStart->toDateString(),
                'days_changed' => count($validated['assignments']),
            ]);
        }

        $name = trim("{$employee->first_name} {$employee->last_name}");

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
            'employee_id' => $employee->id,
            'week_start' => $weekStart->toDateString(),
        ])->with('schedule_status', "Shift schedule for {$name} saved.");
    }

    /**
     * Shared by store()/storeBulk(): writes/deletes the EmployeeShiftSchedule
     * rows for one employee's week per the day-by-day $assignments values (see
     * store()'s docblock for the value vocabulary). Returns whether anything
     * changed, so the caller knows whether a DTR recompute is needed.
     */
    private function applyWeekAssignments(User $employee, array $assignments, Collection $validDates, User $actor): bool
    {
        $recomputeNeeded = false;

        foreach ($assignments as $dateStr => $value) {
            if (! $validDates->contains($dateStr)) {
                continue;
            }

            if ($value === 'default' || $value === '') {
                // Remove the override - employee reverts to their default shift.
                $deleted = EmployeeShiftSchedule::where('user_id', $employee->id)
                    ->where('date', $dateStr)
                    ->delete();
                if ($deleted) {
                    $recomputeNeeded = true;
                }
            } elseif ($value === 'rest') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'rest', 'created_by' => $actor->id]
                );
                $recomputeNeeded = true;
            } elseif ($value === 'field_work') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'field_work', 'created_by' => $actor->id]
                );
                $recomputeNeeded = true;
            } elseif ($value === 'standard') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'standard', 'created_by' => $actor->id]
                );
                $recomputeNeeded = true;
            } else {
                $shiftId = (int) $value;
                $this->assertShiftAssignable($shiftId, $employee->Dept_id, $actor);
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => $shiftId, 'created_by' => $actor->id]
                );
                $recomputeNeeded = true;
            }
        }

        return $recomputeNeeded;
    }

    /**
     * Apply the same day-by-day week schedule (from the week grid) to a
     * hand-picked set of employees (checked on the Shift Schedule screen), so
     * a schedule dialed in for one employee can be broadcast to the rest of a
     * rotating/24-7 crew without repeating it one employee at a time. Mirrors
     * generatePatternBulk()'s reasoning: recompute stays synchronous per
     * employee since it's bounded to one week.
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'week_start' => ['required', 'date'],
            'assignments' => ['required', 'array'],
            'assignments.*' => ['nullable', 'string'],
        ]);

        $userIds = array_map('intval', $validated['user_ids']);

        if ($accessibleIds !== null) {
            $unauthorized = array_diff($userIds, $accessibleIds);
            if (! empty($unauthorized)) {
                abort(403, 'You may only schedule employees in your own department.');
            }
        }

        $employees = User::whereIn('id', $userIds)->get();
        $weekStart = Carbon::parse($validated['week_start'])->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $validDates = collect(range(0, 6))->map(fn ($i) => $weekStart->copy()->addDays($i)->toDateString());

        // Validate every distinct (department, assigned shift) pair up front,
        // before writing anything - same reasoning as generatePatternBulk():
        // a shift that's out of scope for even one employee must reject the
        // whole request rather than leaving a partial bulk write behind.
        $shiftIds = collect($validated['assignments'])
            ->reject(fn ($value) => in_array($value, ['default', '', 'rest', 'field_work', 'standard'], true))
            ->map(fn ($value) => (int) $value)
            ->unique();

        foreach ($employees->pluck('Dept_id')->unique() as $deptId) {
            foreach ($shiftIds as $shiftId) {
                $this->assertShiftAssignable($shiftId, $deptId, $actor);
            }
        }

        $changedEmployeeIds = [];

        foreach ($employees as $employee) {
            $recomputeNeeded = $this->applyWeekAssignments($employee, $validated['assignments'], $validDates, $actor);

            if ($recomputeNeeded) {
                $this->importService->recomputeDtr($employee, $weekStart->toDateString(), $weekEnd->toDateString());
                $changedEmployeeIds[] = $employee->id;
            }
        }

        if (! empty($changedEmployeeIds)) {
            $this->logBulkScheduleAction($actor, $changedEmployeeIds, 'shift_schedule_updated', [
                'week_start' => $weekStart->toDateString(),
                'days_changed' => count($validated['assignments']),
            ]);
        }

        $count = count($changedEmployeeIds);

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
            'employee_id' => $request->input('employee_id'),
            'week_start' => $weekStart->toDateString(),
        ])->with('schedule_status', "Shift schedule saved for {$count} employee(s).");
    }

    /**
     * Generate a recurring on/off rotation (e.g. 24-on/24-off guard duty) over a
     * date range, instead of assigning day-by-day through the week grid.
     *
     * Sets $shift_id as the employee's ongoing default (so "on" days need no
     * per-date row at all) and writes explicit 'rest' rows only for the "off"
     * days in the cycle. This - rather than an explicit row per day - is what
     * keeps WorkSchedule::forUserOnDate()'s rest-day fallback resolving back to
     * this same shift, which a crossing shift's post-midnight punch grouping
     * depends on. Not intended for a short rotation layered on top of a
     * different permanent shift.
     *
     * The "ongoing default" is written through ShiftAssignmentService (an
     * open-ended shift_assignments row effective from $start), not a raw
     * shift_id column update - otherwise a pre-existing ShiftAssignment row
     * covering these dates (from the Shift Assignment screen) would keep
     * winning over the rotation's chosen shift.
     */
    public function generatePattern(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'on_days' => ['required', 'integer', 'min:1'],
            'off_days' => ['required', 'integer', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        if ($accessibleIds !== null && ! in_array((int) $validated['user_id'], $accessibleIds, true)) {
            abort(403, 'You may only schedule employees in your own department.');
        }

        $employee = User::findOrFail($validated['user_id']);
        $this->assertShiftAssignable($validated['shift_id'], $employee->Dept_id, $actor);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();

        $this->writeRotationForEmployee(
            $employee, $validated['shift_id'], $validated['on_days'], $validated['off_days'], $start, $end, $actor
        );

        $this->logScheduleAction($actor, $employee, 'rotation_generated', [
            'shift_id' => $validated['shift_id'],
            'on_days' => $validated['on_days'],
            'off_days' => $validated['off_days'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $name = trim("{$employee->first_name} {$employee->last_name}");

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
            'employee_id' => $employee->id,
        ])->with('schedule_status', "Rotation pattern for {$name} generated from {$start->toDateString()} to {$end->toDateString()}.");
    }

    /**
     * Generate the same on/off rotation for a hand-picked set of employees
     * (checked on the Shift Schedule screen), so a whole rotating/24-7
     * department crew doesn't need the same cycle entered one employee at a
     * time. Recompute stays synchronous (not queued): unlike
     * EmployeeScheduleController::bulkAssign()'s full-history recompute, this
     * is already bounded to the rotation's own date range per employee, which
     * stays cheap for the department-crew-sized groups this targets.
     */
    public function generatePatternBulk(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'on_days' => ['required', 'integer', 'min:1'],
            'off_days' => ['required', 'integer', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $userIds = array_map('intval', $validated['user_ids']);

        if ($accessibleIds !== null) {
            $unauthorized = array_diff($userIds, $accessibleIds);
            if (! empty($unauthorized)) {
                abort(403, 'You may only schedule employees in your own department.');
            }
        }

        $employees = User::whereIn('id', $userIds)->get();

        // Validate every distinct department up front, before writing anything,
        // so a shift that's out of scope for even one employee rejects the
        // whole request rather than leaving a partial bulk write behind.
        foreach ($employees->pluck('Dept_id')->unique() as $deptId) {
            $this->assertShiftAssignable($validated['shift_id'], $deptId, $actor);
        }

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();

        foreach ($employees as $employee) {
            $this->writeRotationForEmployee(
                $employee, $validated['shift_id'], $validated['on_days'], $validated['off_days'], $start, $end, $actor
            );
        }

        $this->logBulkScheduleAction($actor, $employees->pluck('id')->all(), 'rotation_generated', [
            'shift_id' => $validated['shift_id'],
            'on_days' => $validated['on_days'],
            'off_days' => $validated['off_days'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $count = $employees->count();

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
        ])->with('schedule_status', "Rotation pattern generated for {$count} employee(s) from {$start->toDateString()} to {$end->toDateString()}.");
    }

    /**
     * Shared by generatePattern()/generatePatternBulk(): sets $shiftId as the
     * employee's ongoing default (so "on" days need no per-date row at all,
     * written through ShiftAssignmentService rather than a raw shift_id column
     * update - see generatePattern()'s docblock for why) and writes explicit
     * 'rest' rows only for the "off" days in the cycle.
     */
    private function writeRotationForEmployee(User $employee, int $shiftId, int $onDays, int $offDays, Carbon $start, Carbon $end, User $actor): void
    {
        $cycleLength = $onDays + $offDays;

        $this->shiftAssignmentService->assign($employee, $shiftId, $start, null, $actor->id);

        for ($date = $start->copy(), $i = 0; $date->lte($end); $date->addDay(), $i++) {
            $dateStr = $date->toDateString();
            $isOffDay = $offDays > 0 && ($i % $cycleLength) >= $onDays;

            if ($isOffDay) {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'rest', 'created_by' => $actor->id]
                );
            } else {
                EmployeeShiftSchedule::where('user_id', $employee->id)
                    ->where('date', $dateStr)
                    ->delete();
            }
        }

        $this->importService->recomputeDtr($employee, $start->toDateString(), $end->toDateString());
    }

    private function logScheduleAction(User $actor, User $employee, string $action, array $details): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => $action,
                'target_type' => 'user',
                'target_id' => $employee->id,
                'details' => $details,
            ]);
        } catch (\Exception) {
            // audit failure must not block the schedule change
        }
    }

    /**
     * One hr_audit_trails row per affected employee (same shape as
     * logScheduleAction), bulk-inserted in chunks so ShiftLogController's
     * existing department-filtered log view needs no changes to display a
     * bulk rotation the same way it already does bulk shift assignment.
     *
     * @param  int[]  $employeeIds
     */
    private function logBulkScheduleAction(User $actor, array $employeeIds, string $action, array $details): void
    {
        $now = now();

        $rows = array_map(fn (int $employeeId) => [
            'actor_user_id' => $actor->id,
            'module' => 'shift_management',
            'action' => $action,
            'target_type' => 'user',
            'target_id' => $employeeId,
            'details' => json_encode($details + ['bulk' => true]),
            'created_at' => $now,
            'updated_at' => $now,
        ], $employeeIds);

        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                HRAuditTrail::insert($chunk);
            }
        } catch (\Exception) {
            // audit failure must not block the schedule change
        }
    }
}
