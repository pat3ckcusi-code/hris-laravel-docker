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
            ->with('department')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15, ['*'], 'emp_page')
            ->withQueryString();

        return view('attendance.frontline-personnel.index', [
            'departments' => $departments,
            'employees' => $employees,
            'deptSearch' => $deptSearch,
            'empSearch' => $empSearch,
        ]);
    }

    public function toggleDepartment(Request $request, Department $department): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        $frontline = ! $department->is_frontline;
        $department->update(['is_frontline' => $frontline]);

        $this->logToggled($actor, 'department', $department->Dept_id, $frontline, [
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

        $this->logToggled($actor, 'user', $user->id, $frontline, [
            'employee_name' => trim("{$user->first_name} {$user->last_name}"),
        ]);

        $name = trim("{$user->first_name} {$user->last_name}");
        $message = $frontline
            ? "{$name} is now marked frontline/essential."
            : "{$name} is no longer marked frontline/essential.";

        return back()->with('frontline_status', $message);
    }

    private function logToggled(User $actor, string $targetType, int $targetId, bool $frontline, array $extraDetails): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => $targetType === 'department' ? 'frontline_department_toggled' : 'frontline_employee_toggled',
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => array_merge(['frontline' => $frontline], $extraDetails),
            ]);
        } catch (\Exception) {
            // audit failure must not block the toggle
        }
    }
}
