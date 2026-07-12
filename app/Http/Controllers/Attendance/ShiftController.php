<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\ScopesEmployeesByDepartment;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\PersonnelLogImportService;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Time Keeper management of named work-shift templates (e.g. "Standard Day",
 * "Night", "Mid"). Employees are assigned a template via EmployeeScheduleController.
 *
 * Department Head / Administrative Officer get view-only access (once granted
 * shift management access) so they know what templates exist when assigning
 * shifts to their own employees - templates are global, so creating/editing/
 * deleting one stays Time-Keeper/HR-Manager-only.
 */
class ShiftController extends Controller
{
    use ScopesEmployeesByDepartment;

    /** Roles allowed to manage shift templates. */
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    public function __construct(
        private readonly PersonnelLogImportService $importService,
        private readonly DepartmentService $departmentService,
    ) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->resolveAccessibleEmployeeIds($user);

        $canManage = $this->isUnscopedManager($user);
        $shifts = $this->resolveVisibleShiftsQuery($user)
            ->withCount('employees')
            ->with('departments')
            ->orderBy('name')
            ->get();
        $departments = Department::orderBy('Dept_name')->get();

        return view('attendance.shifts.index', compact('shifts', 'canManage', 'departments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        $data = $this->validateShift($request);
        $shift = Shift::create($data);
        $departmentIds = $data['is_global'] ? [] : $request->input('department_ids', []);
        $shift->departments()->sync($departmentIds);

        $this->logTemplateAction($actor, $shift, 'shift_template_created', $data + ['department_ids' => $departmentIds]);

        return back()->with('shift_status', "Shift template \"{$data['name']}\" created.");
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        $data = $this->validateShift($request);
        $shift->update($data);
        $departmentIds = $data['is_global'] ? [] : $request->input('department_ids', []);
        $shift->departments()->sync($departmentIds);

        // Times changed - recompute every employee currently on this shift.
        $shift->employees()->each(fn (User $u) => $this->recomputeEmployee($u));

        $this->logTemplateAction($actor, $shift, 'shift_template_updated', $data + ['department_ids' => $departmentIds]);

        return back()->with('shift_status', "Shift template \"{$shift->name}\" updated and affected DTRs recomputed.");
    }

    public function destroy(Request $request, Shift $shift): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        if ($shift->employees()->exists() || ShiftAssignment::where('shift_id', $shift->id)->exists()) {
            return back()->with('shift_error', "Cannot delete \"{$shift->name}\" - employees are still assigned to it.");
        }

        $name = $shift->name;
        $shiftId = $shift->id;
        $shift->delete();

        $this->logTemplateAction($actor, null, 'shift_template_deleted', ['shift_id' => $shiftId, 'name' => $name]);

        return back()->with('shift_status', 'Shift template deleted.');
    }

    private function logTemplateAction(User $actor, ?Shift $shift, string $action, array $details): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => $action,
                'target_type' => 'shift',
                'target_id' => $shift?->id ?? ($details['shift_id'] ?? null),
                'details' => $details,
            ]);
        } catch (\Exception) {
            // audit failure must not block the template change
        }
    }

    /**
     * @return array{name: string, time_in: string, break_out: string|null, break_in: string|null, time_out: string, crosses_midnight: bool, no_break: bool, is_active: bool, is_global: bool, work_days: int[]}
     */
    private function validateShift(Request $request): array
    {
        $noBreak = (bool) $request->input('no_break', false);

        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'time_in' => ['required', 'date_format:H:i'],
            'break_out' => $noBreak ? ['nullable'] : ['required', 'date_format:H:i'],
            'break_in' => $noBreak ? ['nullable'] : ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i'],
            'is_global' => ['nullable', 'boolean'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,Dept_id'],
            'work_days' => ['sometimes', 'array'],
            'work_days.*' => ['integer', 'between:0,6'],
        ]);

        // Unchecked checkboxes send no key at all, indistinguishable from
        // "selected none" - both safely fall back to Mon-Fri rather than
        // ever persisting a zero-day shift.
        $workDays = collect($v['work_days'] ?? [])
            ->map(fn ($d) => (int) $d)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'name' => $v['name'],
            'time_in' => $v['time_in'],
            'break_out' => $noBreak ? null : $v['break_out'],
            'break_in' => $noBreak ? null : $v['break_in'],
            'time_out' => $v['time_out'],
            'crosses_midnight' => Shift::isCrossMidnight($v['time_in'], $v['time_out']),
            'no_break' => $noBreak,
            'is_active' => true,
            'is_global' => $request->boolean('is_global'),
            'work_days' => $workDays !== [] ? $workDays : Shift::DEFAULT_WORK_DAYS,
        ];
    }

    private function recomputeEmployee(User $user): void
    {
        $this->importService->recomputeFullRange($user);
    }
}
