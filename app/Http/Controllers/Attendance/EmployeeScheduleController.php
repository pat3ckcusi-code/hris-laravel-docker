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
use App\Services\ResolvedScheduleService;
use App\Services\ShiftAssignmentService;
use App\Support\RoleNormalizer;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Time Keeper screen for assigning a work-shift template to each employee.
 * Department Head / Administrative Officer get the same tool once granted
 * shift management access, scoped to their own department's employees.
 *
 * An employee with no shift assigned (shift_id = null) follows the global
 * standard-day shift from the settings table. Assigning or changing a shift
 * recomputes that employee's existing DTRs so stored penalties reflect the new
 * shift.
 */
class EmployeeScheduleController extends Controller
{
    use ScopesEmployeesByDepartment;

    /** Roles allowed to assign shifts. */
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    public function __construct(
        private readonly PersonnelLogImportService $importService,
        private readonly DepartmentService $departmentService,
        private readonly ShiftAssignmentService $shiftAssignmentService,
        private readonly ResolvedScheduleService $resolvedScheduleService,
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

        $deptId = $request->integer('dept_id') ?: null;
        $shiftId = $request->integer('shift_id') ?: null;
        $employeeType = $request->input('employee_type') ?: null;
        $search = trim((string) $request->query('search', ''));
        $showExempt = $request->boolean('show_exempt');

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

        $shifts = $this->resolveVisibleShiftsQuery($user)->where('is_active', true)->orderBy('name')->get();

        $employees = $this->buildEmployeeQuery($accessibleIds, $deptId, $shiftId, $employeeType, $search, $showExempt)
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(25, ['id', 'first_name', 'last_name', 'Dept_id', 'shift_id', 'dtr_exempt', 'employee_type'])
            ->withQueryString();

        // DTR exemption is Time Keeper/HR Manager-only, even for a granted DH/AO.
        $canManageExemption = $this->isUnscopedManager($user);

        // Every not-yet-expired shift_assignments row per employee on this
        // page - currently active AND future-dated ones, so a newly added
        // shift shows up immediately even if its effective_from hasn't
        // started yet (or if it's the second half of a day-scoped combo,
        // e.g. an MWF shift + a TTH shift, which a single dropdown can't
        // represent). Only fully-expired history is left out - that's a
        // separate, capped fetch below.
        $activeAssignments = ShiftAssignment::whereIn('user_id', $employees->pluck('id'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', Carbon::today()->toDateString()))
            ->with('shift')
            ->orderBy('effective_from')
            ->get()
            ->groupBy('user_id');

        // Expired history, capped per employee - an employee can accumulate
        // hundreds/thousands of superseded rows over a long tenure, so this
        // page must never pull an unbounded amount of it just to render an
        // inline preview. One small indexed query per employee (bounded by
        // the 25-per-page pagination above, not a real N+1 risk) fetches the
        // few most recent rows plus a total count for "History (showing X of
        // N)"; anything beyond the cap is only reachable via the dedicated,
        // paginated attendance.schedules.history page.
        $expiredHistoryCap = 5;
        $expiredAssignments = collect();
        $expiredCounts = [];
        foreach ($employees as $employee) {
            $expiredQuery = ShiftAssignment::forUser($employee->id)
                ->whereNotNull('effective_until')
                ->where('effective_until', '<', Carbon::today()->toDateString());

            $expiredCounts[$employee->id] = (clone $expiredQuery)->count();

            if ($expiredCounts[$employee->id] > 0) {
                $expiredAssignments[$employee->id] = $expiredQuery->with('shift')
                    ->orderByDesc('effective_from')
                    ->limit($expiredHistoryCap)
                    ->get();
            }
        }

        $rowOverrides = $this->findConflictingOverrides($employees->pluck('id'), $activeAssignments);

        return view('attendance.schedules.index', compact(
            'departments', 'lockedDepartments', 'shifts', 'employees', 'deptId', 'shiftId', 'employeeType', 'search',
            'showExempt', 'canManageExemption', 'activeAssignments', 'expiredAssignments', 'expiredCounts', 'rowOverrides'
        ));
    }

    /**
     * A ShiftAssignment row only reflects assignment HISTORY - a per-date
     * override on the Shift Schedule week-grid (EmployeeShiftSchedule: rest
     * day, field work, forced Standard Day, or a one-off different shift)
     * silently wins over it for that exact date (see WorkSchedule::isWorkday()).
     * This flags rows whose range contains one, so this screen doesn't look
     * confidently "current" while the actual DTR/payroll outcome differs.
     * Bounded to the next 30 days - a far-future conflict on an open-ended
     * row isn't yet actionable, and scanning years of overrides isn't worth it.
     *
     * @param  Collection<int, int>  $employeeIds
     * @param  Collection<int, Collection<int, ShiftAssignment>>  $activeAssignments
     * @return array<int, array{dates: string, link: string}> assignment row id => conflicting dates + a link to that week on the Shift Schedule page
     */
    private function findConflictingOverrides($employeeIds, $activeAssignments): array
    {
        $windowEnd = Carbon::today()->addDays(30);

        // Excludes is_rotation_generated rows - those are the rotation
        // generator's own rest days, written by the same action as the
        // assignment they'd otherwise appear to "conflict" with, not an
        // independent edit worth flagging.
        $overridesByUser = EmployeeShiftSchedule::whereIn('user_id', $employeeIds)
            ->whereBetween('date', [Carbon::today()->toDateString(), $windowEnd->toDateString()])
            ->where('is_rotation_generated', false)
            ->orderBy('date')
            ->get(['user_id', 'date'])
            ->groupBy('user_id');

        $rowOverrides = [];
        foreach ($activeAssignments as $userId => $rows) {
            $userOverrides = $overridesByUser->get($userId, collect());
            if ($userOverrides->isEmpty()) {
                continue;
            }

            foreach ($rows as $row) {
                $rangeEnd = $row->effective_until ?? $windowEnd;
                $conflicting = $userOverrides->filter(
                    fn (EmployeeShiftSchedule $o) => $o->date->gte($row->effective_from) && $o->date->lte($rangeEnd)
                );

                if ($conflicting->isNotEmpty()) {
                    $firstConflict = $conflicting->first()->date;
                    $rowOverrides[$row->id] = [
                        'dates' => $this->compressDatesToRanges($conflicting->pluck('date')),
                        'link' => route('attendance.shift-schedule.index', [
                            'employee_id' => $userId,
                            'week_start' => $firstConflict->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                        ]),
                    ];
                }
            }
        }

        return $rowOverrides;
    }

    /**
     * Collapses a set of dates into "Jul 13, 2026" / "Jul 13 – 17, 2026"
     * style spans wherever they're actually consecutive, instead of naming
     * every single date - so a fully-overridden 5-day week reads as one
     * range rather than 5 comma-separated dates that just repeat the row's
     * own date-range label.
     *
     * @param  Collection<int, Carbon>  $dates
     */
    private function compressDatesToRanges($dates): string
    {
        $sorted = $dates->sort()->values();
        $ranges = [];
        $start = null;
        $end = null;

        foreach ($sorted as $date) {
            if ($start === null) {
                $start = $end = $date;

                continue;
            }

            if ($date->isSameDay($end->copy()->addDay())) {
                $end = $date;

                continue;
            }

            $ranges[] = $start->isSameDay($end) ? $start->toFormattedDateString() : $start->toFormattedDateString().' – '.$end->toFormattedDateString();
            $start = $end = $date;
        }

        if ($start !== null) {
            $ranges[] = $start->isSameDay($end) ? $start->toFormattedDateString() : $start->toFormattedDateString().' – '.$end->toFormattedDateString();
        }

        return implode(', ', $ranges);
    }

    /**
     * Full, paginated expired-assignment history for one employee - the
     * escape hatch from index()'s capped inline "History" preview. Kept as
     * its own route/query rather than loading unbounded history on the main
     * list page, which every visible employee would otherwise pay for.
     */
    public function history(Request $request, User $user): View
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        if ($accessibleIds !== null && ! in_array((int) $user->id, $accessibleIds, true)) {
            abort(403, 'You may only manage employees in your own department.');
        }

        $shifts = $this->resolveVisibleShiftsQuery($actor)->where('is_active', true)->orderBy('name')->get();

        $assignments = ShiftAssignment::forUser($user->id)
            ->whereNotNull('effective_until')
            ->where('effective_until', '<', Carbon::today()->toDateString())
            ->with('shift')
            ->orderByDesc('effective_from')
            ->paginate(20)
            ->withQueryString();

        return view('attendance.schedules.history', compact('user', 'shifts', 'assignments'));
    }

    /**
     * A single employee's actual day-by-day schedule for one month, combining
     * ShiftAssignment history and EmployeeShiftSchedule per-date overrides via
     * ResolvedScheduleService - the same precedence DTR/payroll already use,
     * surfaced so a Time Keeper doesn't have to cross-reference this screen
     * against the Shift Schedule page to know what will actually happen on a
     * given date (see the "overridden on ..." warning on the shift list above).
     */
    public function resolved(Request $request, User $user): View
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        if ($accessibleIds !== null && ! in_array((int) $user->id, $accessibleIds, true)) {
            abort(403, 'You may only manage employees in your own department.');
        }

        $month = (int) $request->query('month', (int) now()->month);
        $year = (int) $request->query('year', (int) now()->year);
        if ($month < 1 || $month > 12) {
            $month = (int) now()->month;
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) now()->year;
        }

        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $days = $this->resolvedScheduleService->buildMonth($user, $monthStart);

        return view('attendance.schedules.resolved', compact('user', 'monthStart', 'days', 'month', 'year'));
    }

    /**
     * The same filter set used by index() (department, current shift, employee
     * type, name/EmpNo search, active-vs-exempt) - shared with bulkAssign() so
     * a bulk action always targets exactly the employees the user sees on screen.
     *
     * @param  int[]|null  $accessibleIds
     */
    private function buildEmployeeQuery(?array $accessibleIds, ?int $deptId, ?int $shiftId, ?string $employeeType, string $search, bool $showExempt): Builder
    {
        return User::active()
            // Exempt employees are hidden from shift assignment unless explicitly requested.
            ->where('dtr_exempt', $showExempt)
            ->when($accessibleIds !== null, fn ($q) => $q->whereIn('id', $accessibleIds))
            ->when($deptId, fn ($q) => $q->where('Dept_id', $deptId))
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->when($employeeType, fn ($q, $type) => $q->where('employee_type', $type))
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search): void {
                $sub->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('EmpNo', 'like', '%'.$search.'%');
            }));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        if ($accessibleIds !== null && ! in_array((int) $user->id, $accessibleIds, true)) {
            abort(403, 'You may only manage employees in your own department.');
        }

        $validated = $request->validate([
            // form_type distinguishes the "+ Add Shift" submission and the
            // per-row "Edit" correction (both of which must always state an
            // explicit date range) from the per-item "Remove" button (which
            // intentionally omits dates - it reverts those days to Standard
            // Day starting today, open-ended).
            'form_type' => ['nullable', 'string', 'in:add,edit,remove'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'effective_from' => ['nullable', 'required_if:form_type,add,edit', 'date'],
            'effective_until' => ['nullable', 'required_if:form_type,add,edit', 'date', 'after_or_equal:effective_from'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => ['integer', 'between:0,6'],
            'no_break' => ['nullable', 'boolean'],
        ]);

        if ($validated['shift_id'] !== null) {
            $this->assertShiftAssignable($validated['shift_id'], $user->Dept_id, $actor);
        }

        $from = isset($validated['effective_from']) ? Carbon::parse($validated['effective_from']) : Carbon::today();
        $until = isset($validated['effective_until']) ? Carbon::parse($validated['effective_until']) : null;
        $daysOfWeek = $validated['days_of_week'] ?? null;
        // Mirror ShiftAssignmentService::assign()'s own forcing rule here so
        // the audit log and flash message below describe what actually gets
        // persisted, not the raw (possibly narrower/broader) submitted value.
        $workDays = $daysOfWeek ?? ($validated['work_days'] ?? null);
        $noBreak = (bool) ($validated['no_break'] ?? false);
        $isCorrection = ($validated['form_type'] ?? null) === 'edit';

        // Named from what was actually submitted, not users.shift_id (today's
        // cache) - a day-scoped assignment for days that don't include today
        // wouldn't be reflected there yet.
        $submittedShiftId = $validated['shift_id'] ?? null;
        $assignedShift = $submittedShiftId ? Shift::find($submittedShiftId) : null;
        $label = $assignedShift?->name ?? 'Standard Day';

        // An "edit" submission reuses assign() unchanged: submitting the same
        // effective_from as the row being corrected triggers its existing
        // same-start-date replacement rule (delete-and-recreate) rather than
        // the usual truncate-and-append history rule - see
        // ShiftAssignmentService::assign() for why that's already safe here.
        $this->shiftAssignmentService->assign($user, $submittedShiftId, $from, $until, $actor->id, $daysOfWeek, $workDays, $noBreak);

        $this->recomputeEmployee($user);
        $this->logShiftAssigned($actor, $user, $submittedShiftId, $assignedShift?->name, $from, $until, $daysOfWeek, $isCorrection, $workDays, $noBreak);

        $name = trim("{$user->first_name} {$user->last_name}");
        $daysLabel = ShiftAssignment::daysOfWeekLabel($daysOfWeek);
        $scopeText = $daysLabel !== null ? " ({$daysLabel})" : '';
        $window = $until !== null ? " from {$from->toFormattedDateString()} to {$until->toFormattedDateString()}" : '';

        $message = $isCorrection
            ? "{$name}'s assignment corrected to {$label}{$scopeText}{$window}. Existing time records were recomputed."
            : "{$name} assigned to {$label}{$scopeText}{$window}. Existing time records were recomputed.";

        return back()->with('schedule_status', $message);
    }

    /**
     * Assign one shift to a hand-picked set of employees (checked on the
     * current page of the list, or every employee matching the current
     * filters via select_all_matching), so HR doesn't have to save one row
     * at a time. The shift_id update is a single query; DTR recompute for
     * the (possibly large) affected set is deferred to a queued job.
     */
    public function bulkAssign(Request $request): RedirectResponse
    {
        set_time_limit(120);

        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'select_all_matching' => ['nullable', 'boolean'],
            'user_ids' => [Rule::requiredIf(fn () => ! $request->boolean('select_all_matching')), 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'assign_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['required', 'date', 'after_or_equal:effective_from'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => ['integer', 'between:0,6'],
            'no_break' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('select_all_matching')) {
            $deptId = $request->integer('dept_id') ?: null;
            $shiftId = $request->integer('shift_id') ?: null;
            $employeeType = $request->input('employee_type') ?: null;
            $search = trim((string) $request->input('search', ''));

            // Auth is enforced here via $accessibleIds inside
            // buildEmployeeQuery() - no separate array_diff() check needed
            // for this branch (unlike the explicit-user_ids branch below).
            $userIds = $this->buildEmployeeQuery($accessibleIds, $deptId, $shiftId, $employeeType, $search, false)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $userIds = array_map('intval', $validated['user_ids']);

            if ($accessibleIds !== null) {
                $unauthorized = array_diff($userIds, $accessibleIds);
                if (! empty($unauthorized)) {
                    abort(403, 'You may only manage employees in your own department.');
                }
            }
        }

        if (empty($userIds)) {
            return back()->with('schedule_error', 'No employees matched the current filters.');
        }

        $assignShiftId = $validated['assign_shift_id'] ?? null;
        $from = Carbon::parse($validated['effective_from']);
        $until = Carbon::parse($validated['effective_until']);
        $daysOfWeek = $validated['days_of_week'] ?? null;
        // Mirror ShiftAssignmentService::assign()'s own forcing rule (see update()).
        $workDays = $daysOfWeek ?? ($validated['work_days'] ?? null);
        $noBreak = (bool) ($validated['no_break'] ?? false);

        $employees = User::whereIn('id', $userIds)->get(['id', 'Dept_id', 'dtr_exempt']);

        if ($assignShiftId !== null) {
            foreach ($employees->pluck('Dept_id')->unique() as $employeeDeptId) {
                $this->assertShiftAssignable($assignShiftId, $employeeDeptId, $actor);
            }
        }

        $employeeIds = $employees->pluck('id')->all();

        foreach ($employees as $employee) {
            $this->shiftAssignmentService->assign($employee, $assignShiftId, $from, $until, $actor->id, $daysOfWeek, $workDays, $noBreak);
        }

        $this->logBulkShiftAssigned($actor, $employeeIds, $assignShiftId, $from, $until, $daysOfWeek, $workDays, $noBreak);

        BulkShiftRecomputeJob::dispatch($employeeIds);

        $label = $assignShiftId ? (Shift::find($assignShiftId)?->name ?? 'shift') : 'Standard Day';
        $count = count($employeeIds);
        $window = $until !== null ? " from {$from->toFormattedDateString()} to {$until->toFormattedDateString()}" : '';

        return back()->with('schedule_status', "Assigned {$label} to {$count} selected employee(s){$window}. Time records are being recomputed in the background.");
    }

    /**
     * Revert a hand-picked set of employees (checked on the current page, or
     * every employee matching the current filters via select_all_matching)
     * to open-ended Standard Day starting today - the bulk equivalent of the
     * per-row "Remove" action in update(). Always fully reverts (no attempt
     * to replicate update()'s rare $keepDayScope preservation for a
     * concurrent day-split combo); an employee needing that gets excluded
     * from the bulk selection and handled via the per-row remove instead.
     */
    public function bulkRemove(Request $request): RedirectResponse
    {
        set_time_limit(120);

        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        $validated = $request->validate([
            'select_all_matching' => ['nullable', 'boolean'],
            'user_ids' => [Rule::requiredIf(fn () => ! $request->boolean('select_all_matching')), 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if ($request->boolean('select_all_matching')) {
            $deptId = $request->integer('dept_id') ?: null;
            $shiftId = $request->integer('shift_id') ?: null;
            $employeeType = $request->input('employee_type') ?: null;
            $search = trim((string) $request->input('search', ''));

            $userIds = $this->buildEmployeeQuery($accessibleIds, $deptId, $shiftId, $employeeType, $search, false)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $userIds = array_map('intval', $validated['user_ids']);

            if ($accessibleIds !== null) {
                $unauthorized = array_diff($userIds, $accessibleIds);
                if (! empty($unauthorized)) {
                    abort(403, 'You may only manage employees in your own department.');
                }
            }
        }

        if (empty($userIds)) {
            return back()->with('schedule_error', 'No employees matched the current filters.');
        }

        $employees = User::whereIn('id', $userIds)->get(['id', 'Dept_id', 'dtr_exempt']);
        $employeeIds = $employees->pluck('id')->all();
        $today = Carbon::today();

        foreach ($employees as $employee) {
            $this->shiftAssignmentService->assign($employee, null, $today, null, $actor->id, null, null, false);
        }

        $this->logBulkShiftRemoved($actor, $employeeIds, $today);

        BulkShiftRecomputeJob::dispatch($employeeIds);

        $count = count($employeeIds);

        return back()->with('schedule_status', "Removed any assigned shift from {$count} selected employee(s), reverting them to Standard Day starting {$today->toFormattedDateString()}. Time records are being recomputed in the background.");
    }

    /**
     * Toggle an employee's biometric/DTR exemption. Exempt employees are skipped
     * by the import pipeline, excluded from Form 48/DTR exports, and hidden from
     * the shift-assignment list. Turning exemption on clears any assigned shift.
     */
    public function toggleExempt(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        abort_unless($this->isUnscopedManager($actor), 403, 'Only Time Keeper or HR Manager may toggle DTR exemption.');

        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        if ($accessibleIds !== null && ! in_array((int) $user->id, $accessibleIds, true)) {
            abort(403, 'You may only manage employees in your own department.');
        }

        $exempt = ! $user->dtr_exempt;
        $user->update([
            'dtr_exempt' => $exempt,
            'shift_id' => $exempt ? null : $user->shift_id,
        ]);

        $this->logExemptionToggled($actor, $user, $exempt);

        $name = trim("{$user->first_name} {$user->last_name}");
        $message = $exempt
            ? "{$name} is now exempt from biometric/DTR."
            : "{$name} is no longer exempt from biometric/DTR.";

        return back()->with('schedule_status', $message);
    }

    private function logExemptionToggled(User $actor, User $employee, bool $exempt): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => 'dtr_exemption_toggled',
                'target_type' => 'user',
                'target_id' => $employee->id,
                'details' => ['exempt' => $exempt],
            ]);
        } catch (\Exception) {
            // audit failure must not block the toggle
        }
    }

    /**
     * $shiftId/$shiftName describe what was actually submitted, not
     * $employee->shift_id (today's cache) - a day-scoped assignment for days
     * that don't include today wouldn't be reflected there yet, which would
     * otherwise log a misleading "reverted to Standard Day" for a real
     * assignment.
     *
     * $isCorrection tags an "Edit" submission with a distinct action
     * ('shift_assignment_corrected' instead of 'shift_assigned') so the Shift
     * Change Log clearly flags a retroactive fix to already-recorded history,
     * rather than reading like a fresh assignment.
     */
    private function logShiftAssigned(User $actor, User $employee, ?int $shiftId, ?string $shiftName, Carbon $from, ?Carbon $until, ?array $daysOfWeek = null, bool $isCorrection = false, ?array $workDays = null, bool $noBreak = false): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => $isCorrection ? 'shift_assignment_corrected' : 'shift_assigned',
                'target_type' => 'user',
                'target_id' => $employee->id,
                'details' => [
                    'shift_id' => $shiftId,
                    'shift_name' => $shiftName,
                    'actor_role' => $actor->access_level,
                    'effective_from' => $from->toDateString(),
                    'effective_until' => $until?->toDateString(),
                    'days_of_week' => $daysOfWeek,
                    'work_days' => $workDays,
                    'no_break' => $noBreak,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block the assignment
        }
    }

    /**
     * One hr_audit_trails row per affected employee (same shape as
     * logShiftAssigned), bulk-inserted in chunks so ShiftLogController's
     * existing department-filtered log view and 'shift_assigned' label need
     * no changes to display a bulk assignment.
     *
     * @param  int[]  $employeeIds
     */
    private function logBulkShiftAssigned(User $actor, array $employeeIds, ?int $assignShiftId, Carbon $from, ?Carbon $until, ?array $daysOfWeek = null, ?array $workDays = null, bool $noBreak = false): void
    {
        $shiftName = $assignShiftId ? Shift::find($assignShiftId)?->name : null;
        $now = now();

        $rows = array_map(fn (int $employeeId) => [
            'actor_user_id' => $actor->id,
            'module' => 'shift_management',
            'action' => 'shift_assigned',
            'target_type' => 'user',
            'target_id' => $employeeId,
            'details' => json_encode([
                'shift_id' => $assignShiftId,
                'shift_name' => $shiftName,
                'actor_role' => $actor->access_level,
                'bulk' => true,
                'effective_from' => $from->toDateString(),
                'effective_until' => $until?->toDateString(),
                'days_of_week' => $daysOfWeek,
                'work_days' => $workDays,
                'no_break' => $noBreak,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ], $employeeIds);

        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                HRAuditTrail::insert($chunk);
            }
        } catch (\Exception) {
            // audit failure must not block the assignment
        }
    }

    private function logBulkShiftRemoved(User $actor, array $employeeIds, Carbon $from): void
    {
        $now = now();

        $rows = array_map(fn (int $employeeId) => [
            'actor_user_id' => $actor->id,
            'module' => 'shift_management',
            'action' => 'shift_removed',
            'target_type' => 'user',
            'target_id' => $employeeId,
            'details' => json_encode([
                'actor_role' => $actor->access_level,
                'bulk' => true,
                'effective_from' => $from->toDateString(),
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ], $employeeIds);

        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                HRAuditTrail::insert($chunk);
            }
        } catch (\Exception) {
            // audit failure must not block the removal
        }
    }

    /**
     * Rebuild the employee's DTR rows across their full attendance-log range so
     * stored late/undertime reflect the new shift. One employee's range is
     * bounded, so run synchronously.
     */
    private function recomputeEmployee(User $user): void
    {
        $this->importService->recomputeFullRange($user);
    }
}
