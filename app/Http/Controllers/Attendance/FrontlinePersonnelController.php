<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\User;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Time Keeper / HR Manager screen for designating frontline/essential
 * departments and individual employees - a standing designation (set once,
 * not re-picked per suspension) that exempts them from every declared work
 * suspension's leniency, per User::isFrontlineExempt().
 */
class FrontlinePersonnelController extends Controller
{
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $deptSearch = trim((string) $request->query('dept_search', ''));
        $empSearch = trim((string) $request->query('emp_search', ''));
        $empDeptId = $request->query('emp_dept_id', '');
        $empDeptId = $empDeptId !== '' ? (int) $empDeptId : null;

        $departments = Department::query()
            ->when($deptSearch !== '', fn ($q) => $q->where(function ($sub) use ($deptSearch): void {
                $sub->where('Dept_name', 'like', '%'.$deptSearch.'%')
                    ->orWhere('DeptCode', 'like', '%'.$deptSearch.'%');
            }))
            ->orderBy('Dept_name')
            ->paginate(15, ['*'], 'dept_page')
            ->withQueryString();

        $employees = User::active()
            ->when($empSearch !== '', fn ($q) => $q->where(function ($sub) use ($empSearch): void {
                $sub->where('last_name', 'like', '%'.$empSearch.'%')
                    ->orWhere('first_name', 'like', '%'.$empSearch.'%');
            }))
            ->when($empDeptId !== null, fn ($q) => $q->where('Dept_id', $empDeptId))
            ->with('department')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15, ['*'], 'emp_page')
            ->withQueryString();

        $allDepartments = Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);

        return view('attendance.frontline-personnel.index', [
            'departments' => $departments,
            'employees' => $employees,
            'deptSearch' => $deptSearch,
            'empSearch' => $empSearch,
            'empDeptId' => $empDeptId,
            'allDepartments' => $allDepartments,
        ]);
    }

    public function toggleDepartment(Request $request, Department $department): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        $frontline = ! $department->is_frontline;
        $department->update(['is_frontline' => $frontline]);

        $this->logToggled($actor, 'department', $department->Dept_id, [
            'frontline' => $frontline,
            'dept_name' => $department->Dept_name,
        ]);

        $message = $frontline
            ? "{$department->Dept_name} is now marked frontline/essential."
            : "{$department->Dept_name} is no longer marked frontline/essential.";

        return back()->with('frontline_status', $message);
    }

    public function toggleEmployee(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        $frontline = ! $user->is_frontline;
        $user->update(['is_frontline' => $frontline]);

        $this->logToggled($actor, 'user', $user->id, [
            'frontline' => $frontline,
            'employee_name' => trim("{$user->first_name} {$user->last_name}"),
        ]);

        $name = trim("{$user->first_name} {$user->last_name}");
        $message = $frontline
            ? "{$name} is now marked frontline/essential."
            : "{$name} is no longer marked frontline/essential.";

        return back()->with('frontline_status', $message);
    }

    /**
     * Opts a specific employee out of the frontline exemption they would
     * otherwise inherit from their department's own is_frontline flag - the
     * employee's own is_frontline flag (toggleEmployee above) still wins
     * outright if set. See User::isFrontlineExempt().
     */
    public function toggleEmployeeDepartmentExclusion(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        $excluded = ! $user->frontline_department_excluded;
        $user->update(['frontline_department_excluded' => $excluded]);

        $this->logToggled($actor, 'user_dept_exclusion', $user->id, [
            'excluded' => $excluded,
            'employee_name' => trim("{$user->first_name} {$user->last_name}"),
            'dept_name' => $user->department?->Dept_name,
        ]);

        $name = trim("{$user->first_name} {$user->last_name}");
        $message = $excluded
            ? "{$name} will no longer inherit frontline coverage from their department."
            : "{$name}'s department frontline coverage has been restored.";

        return back()->with('frontline_status', $message);
    }

    private function logToggled(User $actor, string $targetType, int $targetId, array $details): void
    {
        $action = match ($targetType) {
            'department' => 'frontline_department_toggled',
            'user' => 'frontline_employee_toggled',
            'user_dept_exclusion' => 'frontline_employee_dept_exclusion_toggled',
        };

        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => $details,
            ]);
        } catch (\Exception) {
            // audit failure must not block the toggle
        }
    }
}
