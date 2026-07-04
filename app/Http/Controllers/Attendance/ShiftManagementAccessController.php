<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\ShiftManagementGrant;
use App\Models\User;
use App\Services\DepartmentService;
use App\Support\RoleNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Time Keeper / HR Manager screen for granting or revoking a department's
 * access to the Shift Management screens (Shift Templates, Shift Assignment,
 * Shift Schedule). Access is per-department, off by default - whoever heads
 * (or covers via OIC) a department only gets in once that department is
 * explicitly granted here, and loses it the moment it's revoked, regardless
 * of who currently holds the Department Head / Administrative Officer role.
 */
class ShiftManagementAccessController extends Controller
{
    private const MANAGER_ROLES = ['time keeper', 'hr manager'];

    public function __construct(
        private readonly DepartmentService $departmentService,
    ) {}

    private function authorizeManager(User $user): void
    {
        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));
        abort_unless(in_array($role, self::MANAGER_ROLES, true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeManager($request->user());

        $search = trim((string) $request->query('search', ''));

        $departments = Department::query()
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search): void {
                $sub->where('Dept_name', 'like', '%'.$search.'%')
                    ->orWhere('DeptCode', 'like', '%'.$search.'%');
            }))
            ->orderBy('Dept_name')
            ->paginate(15)
            ->withQueryString();

        $activeGrants = ShiftManagementGrant::active()
            ->whereIn('dept_id', $departments->pluck('Dept_id'))
            ->get()
            ->keyBy('dept_id');

        $rows = $departments->getCollection()->map(function (Department $dept) use ($activeGrants) {
            $head = $this->departmentService->getDepartmentHeadUser($dept);
            $ao = $this->departmentService->getAdminOfficerUser($dept);

            return [
                'department' => $dept,
                'head_name' => $head ? trim("{$head->first_name} {$head->last_name}") : null,
                'ao_name' => $ao ? trim("{$ao->first_name} {$ao->last_name}") : null,
                'granted' => $activeGrants->has($dept->Dept_id),
            ];
        });

        $departments->setCollection($rows);

        return view('attendance.shift-access.index', ['rows' => $departments, 'search' => $search]);
    }

    public function grant(Request $request, Department $department): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        ShiftManagementGrant::updateOrCreate(
            ['dept_id' => $department->Dept_id],
            ['granted_by' => $actor->id, 'revoked_at' => null, 'revoked_by' => null]
        );

        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => 'access_granted',
                'target_type' => 'department',
                'target_id' => $department->Dept_id,
                'details' => [
                    'dept_name' => $department->Dept_name,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block the grant
        }

        return back()->with('access_status', "Shift management access granted for {$department->Dept_name}.");
    }

    public function revoke(Request $request, Department $department): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManager($actor);

        ShiftManagementGrant::where('dept_id', $department->Dept_id)->update([
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ]);

        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'shift_management',
                'action' => 'access_revoked',
                'target_type' => 'department',
                'target_id' => $department->Dept_id,
                'details' => [
                    'dept_name' => $department->Dept_name,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block the revoke
        }

        return back()->with('access_status', "Shift management access revoked for {$department->Dept_name}.");
    }
}
