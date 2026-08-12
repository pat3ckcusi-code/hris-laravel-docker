<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\ScopesEmployeesByDepartment;
use App\Http\Controllers\Controller;
use App\Jobs\BulkShiftRecomputeJob;
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

    /**
     * Guards against PHP's max_input_vars silently truncating a large
     * user_ids[] submission before Laravel ever sees it (see the comment in
     * docker/php-upload.ini) - the "select all" checkbox JS sends how many
     * checkboxes it actually checked alongside the array itself, so a
     * mismatch here means some were silently dropped during request
     * parsing, not genuinely excluded by anything the user did.
     */
    private function assertBulkSelectionComplete(Request $request, array $userIds): void
    {
        $expectedCount = $request->input('expected_count');

        if ($expectedCount !== null && (int) $expectedCount !== count($userIds)) {
            abort(422, sprintf(
                '%d employee(s) were selected but only %d were received by the server - the selection was too large for one request. Try selecting fewer employees at once.',
                (int) $expectedCount,
                count($userIds)
            ));
        }
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
     *   - 'wfh'                → mark as work from home (shift_id null, type 'wfh')
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
            'no_break' => ['nullable', 'boolean'],
        ]);

        if ($accessibleIds !== null && ! in_array((int) $validated['user_id'], $accessibleIds, true)) {
            abort(403, 'You may only schedule employees in your own department.');
        }

        $employee = User::findOrFail($validated['user_id']);
        $weekStart = Carbon::parse($validated['week_start'])->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $validDates = collect(range(0, 6))->map(fn ($i) => $weekStart->copy()->addDays($i)->toDateString());
        $noBreak = (bool) ($validated['no_break'] ?? false);

        $recomputeNeeded = $this->applyWeekAssignments($employee, $validated['assignments'], $validDates, $actor, $noBreak);

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
                'no_break' => $noBreak,
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
     * Apply a Mon-Sun override pattern (see store()'s docblock for the value
     * vocabulary) repeated across every week in an arbitrary date-to-date
     * range for one employee - the same underlying write as store(), just
     * over N days picked by each date's actual day-of-week instead of one
     * fixed Monday-anchored week. Aligned by ISO weekday (Carbon::isoWeekday(),
     * 1=Mon..7=Sun), so a range that doesn't start on a Monday still matches
     * each date to its correct weekday slot in the pattern.
     */
    public function applyWeeklyPattern(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'pattern' => ['required', 'array', 'size:7'],
            'pattern.*' => ['nullable', 'string'],
            'no_break' => ['nullable', 'boolean'],
        ]);

        if ($accessibleIds !== null && ! in_array((int) $validated['user_id'], $accessibleIds, true)) {
            abort(403, 'You may only schedule employees in your own department.');
        }

        $employee = User::findOrFail($validated['user_id']);
        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();
        $pattern = $validated['pattern'];
        $noBreak = (bool) ($validated['no_break'] ?? false);

        $validDates = collect();
        $assignments = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateStr = $date->toDateString();
            $validDates->push($dateStr);
            $assignments[$dateStr] = $pattern[(string) $date->isoWeekday()] ?? 'default';
        }

        $recomputeNeeded = $this->applyWeekAssignments($employee, $assignments, $validDates, $actor, $noBreak);

        if ($recomputeNeeded) {
            $this->importService->recomputeDtr($employee, $start->toDateString(), $end->toDateString());

            $this->logScheduleAction($actor, $employee, 'shift_schedule_updated', [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'pattern' => $pattern,
                'days_changed' => $validDates->count(),
                'no_break' => $noBreak,
            ]);
        }

        $name = trim("{$employee->first_name} {$employee->last_name}");

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
            'employee_id' => $employee->id,
        ])->with('schedule_status', "Weekly schedule for {$name} applied from {$start->toDateString()} to {$end->toDateString()}.");
    }

    /**
     * Shared by store()/storeBulk()/applyWeeklyPattern(): writes/deletes the
     * EmployeeShiftSchedule rows for one employee's week per the day-by-day
     * $assignments values (see store()'s docblock for the value vocabulary).
     * $noBreak is only ever written on the shift-id branch below - it's
     * meaningless for a rest/field_work/standard/default row, since none of
     * those carry a shift_id for WorkSchedule::forUserOnDate() to apply it to.
     * Returns whether anything changed, so the caller knows whether a DTR
     * recompute is needed.
     */
    private function applyWeekAssignments(User $employee, array $assignments, Collection $validDates, User $actor, bool $noBreak = false): bool
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
                    ['shift_id' => null, 'type' => 'rest', 'created_by' => $actor->id, 'is_rotation_generated' => false]
                );
                $recomputeNeeded = true;
            } elseif ($value === 'field_work') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'field_work', 'created_by' => $actor->id, 'is_rotation_generated' => false]
                );
                $recomputeNeeded = true;
            } elseif ($value === 'wfh') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'wfh', 'created_by' => $actor->id, 'is_rotation_generated' => false]
                );
                $recomputeNeeded = true;
            } elseif ($value === 'standard') {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'standard', 'created_by' => $actor->id, 'is_rotation_generated' => false]
                );
                $recomputeNeeded = true;
            } else {
                $shiftId = (int) $value;
                $this->assertShiftAssignable($shiftId, $employee->Dept_id, $actor);
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => $shiftId, 'created_by' => $actor->id, 'is_rotation_generated' => false, 'no_break' => $noBreak]
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
            'no_break' => ['nullable', 'boolean'],
        ]);

        $userIds = array_map('intval', $validated['user_ids']);
        $noBreak = (bool) ($validated['no_break'] ?? false);

        $this->assertBulkSelectionComplete($request, $userIds);

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
            ->reject(fn ($value) => in_array($value, ['default', '', 'rest', 'field_work', 'wfh', 'standard'], true))
            ->map(fn ($value) => (int) $value)
            ->unique();

        foreach ($employees->pluck('Dept_id')->unique() as $deptId) {
            foreach ($shiftIds as $shiftId) {
                $this->assertShiftAssignable($shiftId, $deptId, $actor);
            }
        }

        $changedEmployeeIds = [];

        foreach ($employees as $employee) {
            $recomputeNeeded = $this->applyWeekAssignments($employee, $validated['assignments'], $validDates, $actor, $noBreak);

            if ($recomputeNeeded) {
                $this->importService->recomputeDtr($employee, $weekStart->toDateString(), $weekEnd->toDateString());
                $changedEmployeeIds[] = $employee->id;
            }
        }

        if (! empty($changedEmployeeIds)) {
            $this->logBulkScheduleAction($actor, $changedEmployeeIds, 'shift_schedule_updated', [
                'week_start' => $weekStart->toDateString(),
                'days_changed' => count($validated['assignments']),
                'no_break' => $noBreak,
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
     * Override exactly one date (e.g. a calamity-driven Work From Home day) for
     * a hand-picked set of employees, without touching any other date on their
     * schedule. Unlike storeBulk() - which broadcasts the entire currently
     * displayed week grid to every checked employee, and so would silently
     * overwrite each of their other 6 days too - this reuses
     * applyWeekAssignments() with a single-entry assignments map and a
     * single-date $validDates collection, guaranteeing only $date is written
     * for each selected employee.
     */
    public function storeSingleDay(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'value' => ['required', 'string'],
            'no_break' => ['nullable', 'boolean'],
        ]);

        $userIds = array_map('intval', $validated['user_ids']);
        $noBreak = (bool) ($validated['no_break'] ?? false);

        $this->assertBulkSelectionComplete($request, $userIds);

        if ($accessibleIds !== null) {
            $unauthorized = array_diff($userIds, $accessibleIds);
            if (! empty($unauthorized)) {
                abort(403, 'You may only schedule employees in your own department.');
            }
        }

        $employees = User::whereIn('id', $userIds)->get();
        $dateStr = Carbon::parse($validated['date'])->toDateString();
        $validDates = collect([$dateStr]);
        $value = $validated['value'];

        // Same up-front validation as storeBulk(): if $value resolves to a
        // shift_id, reject the whole request before writing anything when
        // that shift is out of scope for even one selected employee's
        // department.
        if (! in_array($value, ['default', '', 'rest', 'field_work', 'wfh', 'standard'], true)) {
            $shiftId = (int) $value;
            foreach ($employees->pluck('Dept_id')->unique() as $deptId) {
                $this->assertShiftAssignable($shiftId, $deptId, $actor);
            }
        }

        $changedEmployeeIds = [];

        foreach ($employees as $employee) {
            $recomputeNeeded = $this->applyWeekAssignments($employee, [$dateStr => $value], $validDates, $actor, $noBreak);

            if ($recomputeNeeded) {
                $this->importService->recomputeDtr($employee, $dateStr, $dateStr);
                $changedEmployeeIds[] = $employee->id;
            }
        }

        if (! empty($changedEmployeeIds)) {
            $this->logBulkScheduleAction($actor, $changedEmployeeIds, 'shift_schedule_updated', [
                'date' => $dateStr,
                'value' => $value,
                'no_break' => $noBreak,
            ]);
        }

        $count = count($changedEmployeeIds);

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
            'employee_id' => $request->input('employee_id'),
        ])->with('schedule_status', "Single-day override for {$dateStr} saved for {$count} employee(s).");
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
            'no_break' => ['nullable', 'boolean'],
        ]);

        if ($accessibleIds !== null && ! in_array((int) $validated['user_id'], $accessibleIds, true)) {
            abort(403, 'You may only schedule employees in your own department.');
        }

        $employee = User::findOrFail($validated['user_id']);
        $this->assertShiftAssignable($validated['shift_id'], $employee->Dept_id, $actor);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();
        $noBreak = (bool) ($validated['no_break'] ?? false);

        $this->writeRotationForEmployee(
            $employee, $validated['shift_id'], $validated['on_days'], $validated['off_days'], $start, $end, $actor, $noBreak
        );

        // The ShiftAssignment written above is open-ended (see writeRotationForEmployee()'s
        // docblock), so it can change which schedule resolves for dates well beyond $end -
        // a bounded recompute would leave any already-computed dtrs row past $end stale.
        // Full-history recompute mirrors EmployeeScheduleController::recomputeEmployee().
        $this->importService->recomputeFullRange($employee);

        $this->logScheduleAction($actor, $employee, 'rotation_generated', [
            'shift_id' => $validated['shift_id'],
            'on_days' => $validated['on_days'],
            'off_days' => $validated['off_days'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'no_break' => $noBreak,
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
     * time. Each employee's ShiftAssignment is open-ended (see
     * writeRotationForEmployee()'s docblock), so recompute must cover that
     * employee's full attendance history, not just the rotation's own date
     * range - exactly like EmployeeScheduleController::bulkAssign()'s
     * identical underlying mutation, recompute is deferred to
     * BulkShiftRecomputeJob rather than run synchronously per employee.
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
            'no_break' => ['nullable', 'boolean'],
        ]);

        $userIds = array_map('intval', $validated['user_ids']);

        $this->assertBulkSelectionComplete($request, $userIds);

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
        $noBreak = (bool) ($validated['no_break'] ?? false);

        foreach ($employees as $employee) {
            $this->writeRotationForEmployee(
                $employee, $validated['shift_id'], $validated['on_days'], $validated['off_days'], $start, $end, $actor, $noBreak
            );
        }

        $employeeIds = $employees->pluck('id')->all();

        BulkShiftRecomputeJob::dispatch($employeeIds);

        $this->logBulkScheduleAction($actor, $employeeIds, 'rotation_generated', [
            'shift_id' => $validated['shift_id'],
            'on_days' => $validated['on_days'],
            'off_days' => $validated['off_days'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'no_break' => $noBreak,
        ]);

        $count = $employees->count();

        return redirect()->route('attendance.shift-schedule.index', [
            'dept_id' => $request->input('dept_id'),
        ])->with('schedule_status', "Rotation pattern generated for {$count} employee(s) from {$start->toDateString()} to {$end->toDateString()}. Time records are being recomputed in the background.");
    }

    /**
     * Shared by generatePattern()/generatePatternBulk(): sets $shiftId as the
     * employee's ongoing default (so "on" days need no per-date row at all,
     * written through ShiftAssignmentService rather than a raw shift_id column
     * update - see generatePattern()'s docblock for why) and writes explicit
     * 'rest' rows only for the "off" days in the cycle. Write-only - DTR
     * recompute is each caller's own responsibility (synchronous full-range
     * for generatePattern()'s single employee, a queued
     * BulkShiftRecomputeJob for generatePatternBulk()'s batch), since the
     * ShiftAssignment written here is open-ended and can affect dtrs rows
     * well beyond $end.
     */
    private function writeRotationForEmployee(User $employee, int $shiftId, int $onDays, int $offDays, Carbon $start, Carbon $end, User $actor, bool $noBreak = false): void
    {
        $cycleLength = $onDays + $offDays;

        // Every day defaults to a workday here (not the usual Mon-Fri default) -
        // the cycle's off days are already fully carved out by the explicit
        // EmployeeShiftSchedule rows below, so an "on" day must never be
        // silently downgraded to non-workday just because it lands on a
        // weekend (which any cycle length not a multiple of 7 will do).
        $this->shiftAssignmentService->assign($employee, $shiftId, $start, null, $actor->id, null, [0, 1, 2, 3, 4, 5, 6], $noBreak);

        for ($date = $start->copy(), $i = 0; $date->lte($end); $date->addDay(), $i++) {
            $dateStr = $date->toDateString();
            $isOffDay = $offDays > 0 && ($i % $cycleLength) >= $onDays;

            if ($isOffDay) {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateStr],
                    ['shift_id' => null, 'type' => 'rest', 'created_by' => $actor->id, 'is_rotation_generated' => true]
                );
            } else {
                EmployeeShiftSchedule::where('user_id', $employee->id)
                    ->where('date', $dateStr)
                    ->delete();
            }
        }
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
