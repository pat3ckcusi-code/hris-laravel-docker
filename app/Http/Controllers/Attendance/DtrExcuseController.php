<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\DtrExcuse;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\PersonnelLogImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DtrExcuseController extends Controller
{
    private const ADMIN_ROLES = ['hr manager'];

    private const OFFICER_ROLES = ['administrative officer', 'department head'];

    public function __construct(
        private DepartmentService $departmentService,
        private PersonnelLogImportService $importService,
    ) {}

    /**
     * Resolve employee IDs accessible to the acting user.
     * Returns null for full admins (all employees), or an array of IDs for scoped officers.
     */
    private function resolveAccessibleEmployeeIds(User $user): ?array
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));

        if (in_array($role, self::ADMIN_ROLES, true)) {
            return null;
        }

        if (! in_array($role, self::OFFICER_ROLES, true)) {
            abort(403);
        }

        $roleNormalized = strtolower(str_replace(['-', '_'], ' ', $role));
        $depts = ($roleNormalized === 'administrative officer')
            ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
            : $this->departmentService->resolveAllDepartmentsForUser($user);

        return User::active()->whereIn('Dept_id', $depts->pluck('Dept_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($user);

        $employeesQuery = User::active()->where('dtr_exempt', false)
            ->orderBy('last_name')
            ->orderBy('first_name');
        if ($accessibleIds !== null) {
            $employeesQuery->whereIn('id', $accessibleIds);
        }
        $employees = $employeesQuery->get(['id', 'first_name', 'last_name', 'Dept_id']);

        $search = trim($request->input('search', ''));
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $excuseType = $request->input('excuse_type');

        $excusesQuery = DtrExcuse::with(['user', 'filedBy'])
            ->orderBy('date', 'desc');

        if ($accessibleIds !== null) {
            $excusesQuery->whereIn('user_id', $accessibleIds);
        }

        if ($search !== '') {
            $excusesQuery->whereHas('user', function ($q) use ($search) {
                $q->where('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%");
            });
        }

        if ($dateFrom) {
            $excusesQuery->whereDate('date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $excusesQuery->whereDate('date', '<=', $dateTo);
        }

        if ($excuseType) {
            $excusesQuery->where('excuse_type', $excuseType);
        }

        $excuses = $excusesQuery->paginate(25)->withQueryString();
        $filters = compact('search', 'dateFrom', 'dateTo', 'excuseType');

        return view('attendance.dtr-excuse.index', compact('employees', 'excuses', 'filters'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($user);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'excuse_type' => ['required', Rule::in(['power_interruption', 'system_failure', 'weather_disturbance', 'emergency', 'other'])],
            'is_full_day' => ['nullable', 'boolean'],
            'excuse_am_in' => ['nullable', 'boolean'],
            'excuse_am_out' => ['nullable', 'boolean'],
            'excuse_pm_in' => ['nullable', 'boolean'],
            'excuse_pm_out' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $userIds = array_map('intval', $validated['user_ids']);

        if ($accessibleIds !== null) {
            $unauthorized = array_diff($userIds, $accessibleIds);
            if (! empty($unauthorized)) {
                abort(403, 'You may only excuse employees in your department.');
            }
        }

        $isFullDay = ! empty($validated['is_full_day']);
        $newAmIn = $isFullDay || ! empty($validated['excuse_am_in']);
        $newAmOut = $isFullDay || ! empty($validated['excuse_am_out']);
        $newPmIn = $isFullDay || ! empty($validated['excuse_pm_in']);
        $newPmOut = $isFullDay || ! empty($validated['excuse_pm_out']);

        foreach ($userIds as $userId) {
            $existing = DtrExcuse::where('user_id', $userId)
                ->whereDate('date', $validated['date'])
                ->first();

            if ($existing) {
                $mergedFullDay = $isFullDay || $existing->is_full_day;
                $existing->update([
                    'excuse_type' => $validated['excuse_type'],
                    'reason' => $validated['reason'] ?? null,
                    'filed_by_user_id' => $user->id,
                    'is_full_day' => $mergedFullDay,
                    'excuse_am_in' => $mergedFullDay || $newAmIn || $existing->excuse_am_in,
                    'excuse_am_out' => $mergedFullDay || $newAmOut || $existing->excuse_am_out,
                    'excuse_pm_in' => $mergedFullDay || $newPmIn || $existing->excuse_pm_in,
                    'excuse_pm_out' => $mergedFullDay || $newPmOut || $existing->excuse_pm_out,
                ]);
            } else {
                DtrExcuse::create([
                    'user_id' => $userId,
                    'date' => $validated['date'],
                    'excuse_type' => $validated['excuse_type'],
                    'reason' => $validated['reason'] ?? null,
                    'filed_by_user_id' => $user->id,
                    'is_full_day' => $isFullDay,
                    'excuse_am_in' => $newAmIn,
                    'excuse_am_out' => $newAmOut,
                    'excuse_pm_in' => $newPmIn,
                    'excuse_pm_out' => $newPmOut,
                ]);
            }

            // Re-derive this day's slots now that the resolver knows which slot(s)
            // have no real punch, so an already-imported punch shifts into its
            // correct slot instead of staying mis-assigned.
            $this->importService->recomputeDtr(User::find($userId), $validated['date'], $validated['date']);
        }

        $count = count($userIds);

        return back()->with('success', $count === 1 ? 'DTR excuse filed successfully.' : "{$count} DTR excuses filed successfully.");
    }

    public function check(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($user);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'date' => ['required', 'date'],
        ]);

        $userIds = array_map('intval', $validated['user_ids']);
        if ($accessibleIds !== null) {
            $userIds = array_values(array_intersect($userIds, $accessibleIds));
        }

        $duplicates = DtrExcuse::whereIn('user_id', $userIds)
            ->whereDate('date', $validated['date'])
            ->with('user:id,first_name,last_name')
            ->get()
            ->map(function ($e) {
                $slots = [];
                if ($e->is_full_day) {
                    $slots[] = 'Full Day';
                } else {
                    if ($e->excuse_am_in) {
                        $slots[] = 'AM In';
                    }
                    if ($e->excuse_am_out) {
                        $slots[] = 'AM Out';
                    }
                    if ($e->excuse_pm_in) {
                        $slots[] = 'PM In';
                    }
                    if ($e->excuse_pm_out) {
                        $slots[] = 'PM Out';
                    }
                }

                return [
                    'id' => $e->user_id,
                    'name' => trim(($e->user?->last_name ?? '').', '.($e->user?->first_name ?? '')),
                    'excused_slots' => $slots,
                ];
            })
            ->values();

        return response()->json(['duplicates' => $duplicates]);
    }

    public function destroy(Request $request, DtrExcuse $dtrExcuse): RedirectResponse
    {
        $user = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($user);

        if ($accessibleIds !== null && ! in_array((int) $dtrExcuse->user_id, $accessibleIds, true)) {
            abort(403, 'You may only remove excuses for employees in your department.');
        }

        $excusedUser = $dtrExcuse->user;
        $excusedDate = $dtrExcuse->date->format('Y-m-d');

        $dtrExcuse->delete();

        // Re-derive the day's slots without this excuse's exclusions in case the
        // punch should move back to its originally-resolved slot.
        $this->importService->recomputeDtr($excusedUser, $excusedDate, $excusedDate);

        return back()->with('success', 'Excuse removed.');
    }
}
