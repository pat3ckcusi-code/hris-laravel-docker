<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DtrExcuse;
use App\Models\HRAuditTrail;
use App\Models\Setting;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\PersonnelLogImportService;
use App\Support\HabitualPatternRule;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DtrExcuseController extends Controller
{
    private const ADMIN_ROLES = ['hr manager', 'time keeper'];

    public function __construct(
        private DepartmentService $departmentService,
        private PersonnelLogImportService $importService,
    ) {}

    /**
     * True for the unrestricted admin roles (HR Manager / Time Keeper), false
     * for the department-scoped officer roles (Administrative Officer /
     * Department Head).
     */
    private function isAdmin(User $user): bool
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));

        return in_array($role, self::ADMIN_ROLES, true);
    }

    /**
     * Resolve employee IDs accessible to the acting user.
     * Returns null for full admins (all employees), or an array of IDs for scoped officers.
     */
    private function resolveAccessibleEmployeeIds(User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $officerRole = $this->departmentService->resolveEffectiveOfficerRole($user);
        if ($officerRole === null) {
            abort(403);
        }

        $depts = ($officerRole === 'administrative officer')
            ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
            : $this->departmentService->resolveAllDepartmentsForUser($user);

        return User::active()->whereIn('Dept_id', $depts->pluck('Dept_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    /**
     * Departments the acting user may filter by: every department for
     * unrestricted admins (HR Manager / Time Keeper), or only the caller's
     * own department(s) - including active OIC coverage - for Department
     * Head / Administrative Officer. Mirrors WorkforceCalendarController.
     */
    private function resolveAccessibleDepartments(User $user): Collection
    {
        if ($this->isAdmin($user)) {
            return Department::orderBy('Dept_name')->get();
        }

        $officerRole = $this->departmentService->resolveEffectiveOfficerRole($user);
        if ($officerRole === null) {
            abort(403);
        }

        return $officerRole === 'administrative officer'
            ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
            : $this->departmentService->resolveAllDepartmentsForUser($user);
    }

    /**
     * Human-readable Form 48 slot labels for one excuse row (e.g. ['Full
     * Day'] or ['AM In', 'PM Out']) - shared by check()'s duplicate-check
     * payload and buildExcuseAbuseFlags()'s violation-detail payload.
     *
     * @return array<int, string>
     */
    private function excusedSlotLabels(DtrExcuse $excuse): array
    {
        if ($excuse->is_full_day) {
            return ['Full Day'];
        }

        $labels = [];
        if ($excuse->excuse_am_in) {
            $labels[] = 'AM In';
        }
        if ($excuse->excuse_am_out) {
            $labels[] = 'AM Out';
        }
        if ($excuse->excuse_pm_in) {
            $labels[] = 'PM In';
        }
        if ($excuse->excuse_pm_out) {
            $labels[] = 'PM Out';
        }

        return $labels;
    }

    /**
     * Employees who filed $threshold+ DTR excuses in a calendar month, in a
     * pattern matching HabitualPatternRule (2 consecutive months, or 2
     * months within the same semester) - a possible sign of DTR Excuse
     * abuse rather than genuine one-off inability to punch. Company-wide,
     * not department-scoped (see class-level access notes on index()).
     * Each flag carries the individual excuse rows behind its violation
     * months, for the "view details" modal on the DTR Excuses page.
     *
     * @return Collection<int, array{user_id: int, employee: ?User, violation_months: Collection<int, int>, total_excuses: int, violations: Collection<int, array>}>
     */
    private function buildExcuseAbuseFlags(int $year, int $threshold): Collection
    {
        $monthly = DB::table('dtr_excuses')
            ->whereYear('date', $year)
            ->selectRaw('user_id, MONTH(date) as mo, COUNT(*) as excuse_count')
            ->groupBy('user_id', 'mo')
            ->get()
            ->groupBy('user_id');

        $employees = User::whereIn('id', $monthly->keys())
            ->with('department')
            ->get(['id', 'first_name', 'last_name', 'Dept_id'])
            ->keyBy('id');

        $flags = collect();
        $violationMonthsByUser = [];

        foreach ($monthly as $userId => $months) {
            $violationMonths = $months->where('excuse_count', '>=', $threshold)
                ->pluck('mo')->map(fn ($m) => (int) $m)->sort()->values();

            if (! HabitualPatternRule::meets($violationMonths)) {
                continue;
            }

            $violationMonthsByUser[$userId] = $violationMonths->all();

            $flags->push([
                'user_id' => $userId,
                'employee' => $employees->get($userId),
                'violation_months' => $violationMonths,
                'total_excuses' => (int) $months->sum('excuse_count'),
            ]);
        }

        if ($flags->isEmpty()) {
            return $flags;
        }

        $violationsByUser = DtrExcuse::whereIn('user_id', array_keys($violationMonthsByUser))
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get(['user_id', 'date', 'excuse_type', 'is_full_day', 'excuse_am_in', 'excuse_am_out', 'excuse_pm_in', 'excuse_pm_out', 'reason'])
            ->filter(fn (DtrExcuse $e) => in_array((int) $e->date->month, $violationMonthsByUser[$e->user_id], true))
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->map(fn (DtrExcuse $e) => [
                'date' => $e->date->format('M d, Y'),
                'type' => DtrExcuse::typeConfig($e->excuse_type)['label'],
                'scope' => implode(', ', $this->excusedSlotLabels($e)),
                'reason' => $e->reason,
            ])->values());

        return $flags->map(function (array $flag) use ($violationsByUser) {
            $flag['violations'] = $violationsByUser->get($flag['user_id'], collect());

            return $flag;
        })->sortBy(fn ($f) => $f['employee']?->last_name)->values();
    }

    private function paginateCollection(Request $request, Collection $items, int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $pageName]
        );
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $accessibleIds = $this->resolveAccessibleEmployeeIds($user);
        $departments = $this->resolveAccessibleDepartments($user);
        $departmentId = $request->integer('department_id') ?: null;

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

        $excusesQuery = DtrExcuse::with(['user.department', 'filedBy'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

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

        if ($departmentId) {
            $excusesQuery->whereHas('user', fn ($q) => $q->where('Dept_id', $departmentId));
        }

        $excuses = $excusesQuery->paginate(25)->withQueryString();
        $filters = compact('search', 'dateFrom', 'dateTo', 'excuseType', 'departmentId');

        $abuseYear = (int) $request->query('abuse_year', (int) now()->year);
        if ($abuseYear < 2000 || $abuseYear > 2100) {
            $abuseYear = (int) now()->year;
        }

        $canViewAbuseFlags = $this->isAdmin($user);
        $abuseFlags = collect();
        $abuseThreshold = null;

        if ($canViewAbuseFlags) {
            $abuseThreshold = (int) (Setting::first()?->dtr_excuse_abuse_monthly_threshold ?? 3);
            $abuseFlags = $this->paginateCollection(
                $request,
                $this->buildExcuseAbuseFlags($abuseYear, $abuseThreshold),
                15,
                'abuse_page'
            );
        }

        return view('attendance.dtr-excuse.index', compact(
            'employees', 'excuses', 'filters', 'departments',
            'canViewAbuseFlags', 'abuseFlags', 'abuseYear', 'abuseThreshold'
        ));
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

        $batchId = (string) Str::uuid();
        $now = now();
        $auditRows = [];

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
                $record = $existing;
                $wasMerged = true;
            } else {
                $record = DtrExcuse::create([
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
                $wasMerged = false;
            }

            $auditRows[] = [
                'actor_user_id' => $user->id,
                'module' => 'dtr_excuse',
                'action' => 'dtr_excuse_filed',
                'target_type' => 'user',
                'target_id' => $userId,
                'batch_id' => $batchId,
                'details' => json_encode([
                    'date' => $validated['date'],
                    'excuse_type' => $validated['excuse_type'],
                    'is_full_day' => $record->is_full_day,
                    'excuse_am_in' => $record->excuse_am_in,
                    'excuse_am_out' => $record->excuse_am_out,
                    'excuse_pm_in' => $record->excuse_pm_in,
                    'excuse_pm_out' => $record->excuse_pm_out,
                    'reason' => $validated['reason'] ?? null,
                    'merged' => $wasMerged,
                    'actor_role' => $user->access_level,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Re-derive this day's slots now that the resolver knows which slot(s)
            // have no real punch, so an already-imported punch shifts into its
            // correct slot instead of staying mis-assigned.
            $this->importService->recomputeDtr(User::find($userId), $validated['date'], $validated['date']);
        }

        try {
            foreach (array_chunk($auditRows, 500) as $chunk) {
                HRAuditTrail::insert($chunk);
            }
        } catch (\Exception) {
            // audit failure must not block the filing
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
            ->map(fn ($e) => [
                'id' => $e->user_id,
                'name' => trim(($e->user?->last_name ?? '').', '.($e->user?->first_name ?? '')),
                'excused_slots' => $this->excusedSlotLabels($e),
            ])
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
        $excusedUserId = $dtrExcuse->user_id;

        $deletedDetails = [
            'date' => $excusedDate,
            'excuse_type' => $dtrExcuse->excuse_type,
            'is_full_day' => $dtrExcuse->is_full_day,
            'excuse_am_in' => $dtrExcuse->excuse_am_in,
            'excuse_am_out' => $dtrExcuse->excuse_am_out,
            'excuse_pm_in' => $dtrExcuse->excuse_pm_in,
            'excuse_pm_out' => $dtrExcuse->excuse_pm_out,
            'reason' => $dtrExcuse->reason,
            'originally_filed_by_user_id' => $dtrExcuse->filed_by_user_id,
            'actor_role' => $user->access_level,
        ];

        $dtrExcuse->delete();

        try {
            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'dtr_excuse',
                'action' => 'dtr_excuse_deleted',
                'target_type' => 'user',
                'target_id' => $excusedUserId,
                'details' => $deletedDetails,
            ]);
        } catch (\Exception) {
            // audit failure must not block the deletion
        }

        // Re-derive the day's slots without this excuse's exclusions in case the
        // punch should move back to its originally-resolved slot.
        $this->importService->recomputeDtr($excusedUser, $excusedDate, $excusedDate);

        return back()->with('success', 'Excuse removed.');
    }
}
