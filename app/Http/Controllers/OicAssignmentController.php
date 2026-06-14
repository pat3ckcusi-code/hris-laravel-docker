<?php

namespace App\Http\Controllers;

use App\Models\HRAuditTrail;
use App\Models\OicAssignment;
use App\Models\User;
use App\Services\DepartmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class OicAssignmentController extends Controller
{
    public function __construct(private DepartmentService $departmentService) {}

    public function index()
    {
        $user = Auth::user();
        $canAppoint = $this->isRealDeptHeadOrAO($user);
        $depts = $this->resolveUserDepts($user);

        $appointingRole = $this->normalizeRole($user);

        if ($depts->isEmpty()) {
            return view('department-head.oic-assignments', [
                'assignments' => collect(),
                'departments' => collect(),
                'employeesByDept' => [],
                'canAppoint' => $canAppoint,
                'appointingRole' => $appointingRole,
            ]);
        }

        $deptIds = $depts->pluck('Dept_id')->toArray();

        $assignments = OicAssignment::with(['user', 'department', 'appointedBy'])
            ->whereIn('dept_id', $deptIds)
            ->orderByDesc('start_date')
            ->get();

        $employeesByDept = [];
        foreach ($depts as $dept) {
            $employeesByDept[$dept->Dept_id] = User::where('Dept_id', $dept->Dept_id)
                ->whereNotIn('access_level', ['department head', 'department-head', 'administrative officer', 'administrative-officer'])
                ->orderBy('name')
                ->get(['id', 'name', 'first_name', 'last_name', 'designation']);
        }

        return view('department-head.oic-assignments', [
            'assignments' => $assignments,
            'departments' => $depts,
            'employeesByDept' => $employeesByDept,
            'canAppoint' => $canAppoint,
            'appointingRole' => $appointingRole,
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if (! $this->isRealDeptHeadOrAO($user)) {
            abort(403, 'OIC users cannot appoint further OICs.');
        }

        // Role is always the appointing user's own role — never trust the submitted value.
        $appointingRole = $this->normalizeRole($user);

        $depts = $this->resolveUserDepts($user);
        $deptIds = $depts->pluck('Dept_id')->toArray();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'dept_id' => ['required', 'integer'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $validated['role'] = $appointingRole;

        if (! in_array((int) $validated['dept_id'], $deptIds)) {
            return redirect()->back()->with('error', 'You are not authorized to manage OIC for that department.');
        }

        $oicUser = User::findOrFail($validated['user_id']);
        if ((int) $oicUser->Dept_id !== (int) $validated['dept_id']) {
            return redirect()->back()->with('error', 'The selected employee does not belong to that department.');
        }

        $conflict = OicAssignment::where('dept_id', $validated['dept_id'])
            ->where('role', $validated['role'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhere(function ($q2) use ($validated) {
                        $q2->where('start_date', '<=', $validated['start_date'])
                            ->where('end_date', '>=', $validated['end_date']);
                    });
            })
            ->exists();

        if ($conflict) {
            return redirect()->back()->with('error', 'An OIC assignment for that role and department already overlaps with the selected date range.');
        }

        $assignment = OicAssignment::create([
            'user_id' => $validated['user_id'],
            'dept_id' => $validated['dept_id'],
            'role' => $validated['role'],
            'appointed_by' => $user->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $user->id,
            'module' => 'oic',
            'action' => 'appoint',
            'target_type' => 'oic_assignment',
            'target_id' => $assignment->id,
            'details' => [
                'oic_user_id' => $assignment->user_id,
                'oic_user_name' => $oicUser->name,
                'dept_id' => $assignment->dept_id,
                'role' => $assignment->role,
                'start_date' => $assignment->start_date->toDateString(),
                'end_date' => $assignment->end_date->toDateString(),
                'appointed_by' => $user->id,
            ],
        ]);

        return redirect()->route('department-head.oic-assignments.index')
            ->with('success', 'OIC appointment saved successfully.');
    }

    public function destroy(int $id)
    {
        $user = Auth::user();

        if (! $this->isRealDeptHeadOrAO($user)) {
            abort(403, 'OIC users cannot cancel OIC assignments.');
        }

        $depts = $this->resolveUserDepts($user);
        $deptIds = $depts->pluck('Dept_id')->toArray();

        $assignment = OicAssignment::findOrFail($id);

        if (! in_array((int) $assignment->dept_id, $deptIds)) {
            return redirect()->back()->with('error', 'You are not authorized to cancel that OIC assignment.');
        }

        $assignment->delete();

        return redirect()->route('department-head.oic-assignments.index')
            ->with('success', 'OIC assignment cancelled.');
    }

    private function resolveUserDepts(User $user): Collection
    {
        $normalizedRole = strtolower(str_replace(['-', '_'], ' ', (string) ($user->access_level ?? '')));

        if ($normalizedRole === 'administrative officer') {
            return $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        }

        return $this->departmentService->resolveAllDepartmentsForUser($user);
    }

    private function isRealDeptHeadOrAO(User $user): bool
    {
        $role = $this->normalizeRole($user);

        return in_array($role, ['department head', 'administrative officer'], true);
    }

    private function normalizeRole(User $user): string
    {
        return strtolower(str_replace(['-', '_'], ' ', (string) ($user->access_level ?? '')));
    }
}
