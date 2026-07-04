<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\ScopesEmployeesByDepartment;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\Shift;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\PersonnelLogImportService;
use App\Support\RoleNormalizer;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        $shifts = Shift::where('is_active', true)->orderBy('name')->get();

        $employees = User::query()
            // Exempt employees are hidden from shift assignment unless explicitly requested.
            ->where('dtr_exempt', $showExempt)
            ->when($accessibleIds !== null, fn ($q) => $q->whereIn('id', $accessibleIds))
            ->when($deptId, fn ($q) => $q->where('Dept_id', $deptId))
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search): void {
                $sub->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('EmpNo', 'like', '%'.$search.'%');
            }))
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(25, ['id', 'first_name', 'last_name', 'Dept_id', 'shift_id', 'dtr_exempt'])
            ->withQueryString();

        return view('attendance.schedules.index', compact('departments', 'lockedDepartments', 'shifts', 'employees', 'deptId', 'shiftId', 'search', 'showExempt'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($actor);

        if ($accessibleIds !== null && ! in_array((int) $user->id, $accessibleIds, true)) {
            abort(403, 'You may only manage employees in your own department.');
        }

        $validated = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        $user->update(['shift_id' => $validated['shift_id'] ?? null]);

        $this->recomputeEmployee($user);
        $this->logShiftAssigned($actor, $user);

        $name = trim("{$user->first_name} {$user->last_name}");
        $label = $user->shift_id ? ($user->shift()->value('name') ?? 'shift') : 'Standard Day';

        return back()->with('schedule_status', "{$name} assigned to {$label}. Existing time records were recomputed.");
    }

    /**
     * Toggle an employee's biometric/DTR exemption. Exempt employees are skipped
     * by the import pipeline, excluded from Form 48/DTR exports, and hidden from
     * the shift-assignment list. Turning exemption on clears any assigned shift.
     */
    public function toggleExempt(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
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

    private function logShiftAssigned(User $actor, User $employee): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => 'shift_assigned',
                'target_type' => 'user',
                'target_id' => $employee->id,
                'details' => [
                    'shift_id' => $employee->shift_id,
                    'shift_name' => $employee->shift?->name,
                    'actor_role' => $actor->access_level,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block the assignment
        }
    }

    /**
     * Rebuild the employee's DTR rows across their full attendance-log range so
     * stored late/undertime reflect the new shift. One employee's range is
     * bounded, so run synchronously.
     */
    private function recomputeEmployee(User $user): void
    {
        $range = AttendanceLog::where('user_id', $user->id)
            ->selectRaw('MIN(logdate) as min_d, MAX(logdate) as max_d')
            ->first();

        if ($range === null || $range->min_d === null) {
            return;
        }

        $this->importService->recomputeDtr(
            $user,
            Carbon::parse($range->min_d)->toDateString(),
            Carbon::parse($range->max_d)->toDateString(),
        );
    }
}
