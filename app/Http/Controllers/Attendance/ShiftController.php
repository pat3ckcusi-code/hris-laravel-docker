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
use Illuminate\Support\Facades\Validator;

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
     * A template always fully describes a 4-slot day (in / break-out /
     * break-in / out) - whether a given employee's assignment actually uses
     * the break slots is a per-assignment decision (ShiftAssignment::no_break),
     * not a template one, so break_out/break_in are unconditionally required
     * here regardless of how any assignment ends up using this template -
     * EXCEPT for an is_field_work_pair template, where Time In/Time Out
     * represent two different calendar days (Monday's check-in, Friday's
     * check-out) rather than one day's span, so there's no break window (or
     * even a same-day ordering) to require or validate at all.
     * The template's own no_break flag validated below is a UI default only
     * (pre-fills the per-employee checkbox when this template is picked) -
     * it never controls whether break_out/break_in are required or used.
     *
     * @return array{name: string, time_in: string, break_out: string|null, break_in: string|null, time_out: string, crosses_midnight: bool, is_active: bool, is_global: bool, no_break: bool, punch_requirement: string, is_field_work_pair: bool, is_single_punch: bool}
     */
    private function validateShift(Request $request): array
    {
        $isFieldWorkPair = $request->boolean('is_field_work_pair');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'time_in' => ['required', 'date_format:H:i'],
            'break_out' => [$isFieldWorkPair ? 'nullable' : 'required', 'date_format:H:i'],
            'break_in' => [$isFieldWorkPair ? 'nullable' : 'required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i'],
            'is_global' => ['nullable', 'boolean'],
            'no_break' => ['nullable', 'boolean'],
            'punch_requirement' => ['nullable', 'string', 'in:both,in_only,out_only'],
            'is_field_work_pair' => ['nullable', 'boolean'],
            'is_single_punch' => ['nullable', 'boolean'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,Dept_id'],
        ]);

        $validator->after(function ($validator) use ($request, $isFieldWorkPair) {
            if ($isFieldWorkPair) {
                return;
            }

            $timeIn = $request->input('time_in');
            $breakOut = $request->input('break_out');
            $breakIn = $request->input('break_in');
            $timeOut = $request->input('time_out');

            // Format errors on any of these are already reported by the base
            // rules above - skip the ordering check rather than double-report.
            if (! $timeIn || ! $breakOut || ! $breakIn || ! $timeOut) {
                return;
            }

            if (! $this->breakWindowIsWithinShift($timeIn, $breakOut, $breakIn, $timeOut)) {
                $validator->errors()->add(
                    'break_out',
                    'Break Out and Break In must fall between Time In and Time Out, in that order.'
                );
            }
        });

        $v = $validator->validate();

        return [
            'name' => $v['name'],
            'time_in' => $v['time_in'],
            'break_out' => $isFieldWorkPair ? null : $v['break_out'],
            'break_in' => $isFieldWorkPair ? null : $v['break_in'],
            'time_out' => $v['time_out'],
            'crosses_midnight' => $isFieldWorkPair ? false : Shift::isCrossMidnight($v['time_in'], $v['time_out']),
            'is_active' => true,
            'is_global' => $request->boolean('is_global'),
            'no_break' => $request->boolean('no_break'),
            'punch_requirement' => $v['punch_requirement'] ?? 'both',
            'is_field_work_pair' => $isFieldWorkPair,
            'is_single_punch' => $request->boolean('is_single_punch'),
        ];
    }

    /**
     * Normalizes the four HH:MM times onto a single timeline - rolling any
     * value at/before time_in forward by 24h, mirroring the same "which side
     * of midnight" logic Shift::isCrossMidnight() already uses for time_out
     * alone - then asserts strict shift order: time_in < break_out <
     * break_in < time_out. Catches a break window left outside the shift's
     * own span (e.g. a stale 12:00/13:00 default carried over onto a newly
     * created night shift) before it can produce degenerate matching windows
     * in AttendanceMatcher at DTR-resolution time.
     */
    private function breakWindowIsWithinShift(string $timeIn, string $breakOut, string $breakIn, string $timeOut): bool
    {
        $toMinutes = fn (string $hhmm): int => ((int) substr($hhmm, 0, 2)) * 60 + ((int) substr($hhmm, 3, 2));

        $timeInMinutes = $toMinutes($timeIn);
        $normalize = fn (int $minutes): int => $minutes <= $timeInMinutes ? $minutes + 1440 : $minutes;

        $breakOutMinutes = $normalize($toMinutes($breakOut));
        $breakInMinutes = $normalize($toMinutes($breakIn));
        $timeOutMinutes = $normalize($toMinutes($timeOut));

        return $timeInMinutes < $breakOutMinutes
            && $breakOutMinutes < $breakInMinutes
            && $breakInMinutes < $timeOutMinutes;
    }

    private function recomputeEmployee(User $user): void
    {
        $this->importService->recomputeFullRange($user);
    }
}
