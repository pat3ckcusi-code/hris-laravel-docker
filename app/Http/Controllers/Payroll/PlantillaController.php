<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\HRAuditTrail;
use App\Models\Plantilla;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class PlantillaController extends Controller
{
    private const PLANTILLA_FIELDS = [
        'title', 'item_number', 'department', 'salary_grade', 'step', 'employment_type',
        'csc_eligibility', 'education', 'training', 'experience', 'competency',
    ];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $plantillas = Plantilla::with('activeAssignments.employee')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('item_number', 'like', "%{$search}%")
                        ->orWhere('department', 'like', "%{$search}%")
                        ->orWhereHas('activeAssignments.employee', function ($e) use ($search) {
                            $e->where('last_name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->query('department'), fn ($q, $dept) => $q->where('department', $dept))
            ->when($request->query('status') === 'vacant', fn ($q) => $q->whereDoesntHave('activeAssignments'))
            ->when($request->query('status') === 'filled', fn ($q) => $q->whereHas('activeAssignments'))
            ->when($request->query('eligibility'), fn ($q, $elig) => $q->where('csc_eligibility', $elig))
            ->orderBy('salary_grade')
            ->paginate(20)
            ->withQueryString();

        $departments = Plantilla::whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        $vacantPlantillas = Plantilla::where('is_historical', false)
            ->whereDoesntHave('activeAssignments')
            ->orderBy('salary_grade')
            ->orderBy('title')
            ->get(['id', 'item_number', 'title', 'department', 'salary_grade', 'step']);

        $stats = [
            'total' => Plantilla::where('is_historical', false)->count(),
            'filled' => Plantilla::where('is_historical', false)->whereHas('activeAssignments')->count(),
        ];
        $stats['vacant'] = $stats['total'] - $stats['filled'];

        $eligibilityOptions = Plantilla::ELIGIBILITY_OPTIONS;
        $routePrefix = $this->routePrefix($request);

        return view('payroll.plantilla', compact('plantillas', 'departments', 'vacantPlantillas', 'stats', 'eligibilityOptions', 'routePrefix'));
    }

    public function reports(Request $request): View
    {
        $stats = [
            'total' => Plantilla::where('is_historical', false)->count(),
            'filled' => Plantilla::where('is_historical', false)->whereHas('activeAssignments')->count(),
            'promotions_this_year' => HRAuditTrail::where('module', 'payroll')
                ->where('action', 'promotion')
                ->whereYear('created_at', now()->year)
                ->count(),
        ];
        $stats['vacant'] = $stats['total'] - $stats['filled'];

        $vacantSearch = trim((string) $request->query('vacant_search'));
        $vacantDepartment = $request->query('vacant_department');

        $vacantPositions = Plantilla::where('is_historical', false)
            ->whereDoesntHave('activeAssignments')
            ->when($vacantSearch !== '', function ($query) use ($vacantSearch) {
                $query->where(function ($q) use ($vacantSearch) {
                    $q->where('title', 'like', "%{$vacantSearch}%")
                        ->orWhere('item_number', 'like', "%{$vacantSearch}%")
                        ->orWhere('department', 'like', "%{$vacantSearch}%");
                });
            })
            ->when($vacantDepartment, fn ($q, $dept) => $q->where('department', $dept))
            ->orderBy('department')
            ->orderByDesc('salary_grade')
            ->paginate(15, ['*'], 'vacant_page')
            ->withQueryString();

        $promotionSearch = trim((string) $request->query('promotion_search'));
        $promotionEmployeeIds = $promotionSearch !== ''
            ? $this->matchingEmployeeIds($promotionSearch)
            : null;

        $promotions = HRAuditTrail::with('actor')
            ->where('module', 'payroll')
            ->where('action', 'promotion')
            ->when($promotionEmployeeIds !== null, fn ($q) => $q->whereIn('target_id', $promotionEmployeeIds))
            ->latest()
            ->paginate(15, ['*'], 'promotions_page')
            ->withQueryString();

        $activityAction = $request->query('activity_action');
        $activitySearch = trim((string) $request->query('activity_search'));
        $activityEmployeeIds = $activitySearch !== ''
            ? $this->matchingEmployeeIds($activitySearch)
            : null;

        $activity = HRAuditTrail::with('actor')
            ->where('module', 'payroll')
            ->whereIn('action', ['promotion', 'assignment_created', 'assignment_updated', 'assignment_removed'])
            ->when($activityAction, fn ($q, $action) => $q->where('action', $action))
            ->when($activityEmployeeIds !== null, fn ($q) => $q->whereIn('target_id', $activityEmployeeIds))
            ->latest()
            ->paginate(20, ['*'], 'activity_page')
            ->withQueryString();

        $departments = Plantilla::whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        $employeeIds = $promotions->pluck('target_id')
            ->merge($activity->pluck('target_id'))
            ->filter()
            ->unique();
        $employees = User::whereIn('id', $employeeIds)
            ->get(['id', 'name', 'last_name', 'first_name', 'designation', 'date_of_original_appointment', 'date_of_last_promotion'])
            ->keyBy('id');

        $routePrefix = $this->routePrefix($request);

        return view('payroll.plantilla-reports', compact('stats', 'vacantPositions', 'promotions', 'activity', 'employees', 'departments', 'routePrefix'));
    }

    private function matchingEmployeeIds(string $search): Collection
    {
        return User::active()->where('last_name', 'like', "%{$search}%")
            ->orWhere('first_name', 'like', "%{$search}%")
            ->orWhere('name', 'like', "%{$search}%")
            ->orWhere('EmpNo', 'like', "%{$search}%")
            ->pluck('id');
    }

    public function serviceTrail(Request $request): View
    {
        $employees = User::orderBy('last_name')
            ->get(['id', 'name', 'last_name', 'first_name', 'EmpNo', 'designation']);

        $employee = null;
        $assignments = collect();
        $activity = collect();

        if ($request->filled('employee_id')) {
            $employee = User::with('department')->findOrFail($request->integer('employee_id'));

            $assignments = EmployeeAssignment::with('plantilla')
                ->where('employee_id', $employee->id)
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->get();

            $activity = HRAuditTrail::with('actor')
                ->where('module', 'payroll')
                ->where('target_type', User::class)
                ->where('target_id', $employee->id)
                ->latest()
                ->get();
        }

        $orgDepartments = Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);
        $routePrefix = $this->routePrefix($request);

        return view('payroll.plantilla-service-trail', compact('employees', 'employee', 'assignments', 'activity', 'orgDepartments', 'routePrefix'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.plantilla.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'item_number' => 'nullable|string|max:50|unique:plantillas,item_number',
            'department' => 'nullable|string|max:255',
            'salary_grade' => 'required|integer|min:1|max:33',
            'step' => 'nullable|integer|min:1|max:8',
            'employment_type' => 'required|string|max:100',
            'csc_eligibility' => ['nullable', Rule::in(array_keys(Plantilla::ELIGIBILITY_OPTIONS))],
            'education' => 'nullable|string|max:2000',
            'training' => 'nullable|string|max:2000',
            'experience' => 'nullable|string|max:2000',
            'competency' => 'nullable|string|max:2000',
        ]);

        Plantilla::create($request->only(self::PLANTILLA_FIELDS));

        return redirect()->route('payroll.plantilla.index')
            ->with('status', 'Plantilla position created.');
    }

    public function show(Request $request, int $id): View
    {
        $plantilla = Plantilla::with('assignments.employee')->findOrFail($id);
        $employees = User::active()->orderBy('last_name')
            ->get(['id', 'name', 'last_name', 'first_name', 'designation', 'EmpNo']);
        $currentAssignments = EmployeeAssignment::whereNull('end_date')
            ->with('plantilla')
            ->get()
            ->keyBy('employee_id');
        $eligibilityOptions = Plantilla::ELIGIBILITY_OPTIONS;
        $routePrefix = $this->routePrefix($request);

        return view('payroll.plantilla-show', compact('plantilla', 'employees', 'currentAssignments', 'eligibilityOptions', 'routePrefix'));
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.plantilla.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'item_number' => ['nullable', 'string', 'max:50', Rule::unique('plantillas', 'item_number')->ignore($id)],
            'department' => 'nullable|string|max:255',
            'salary_grade' => 'required|integer|min:1|max:33',
            'step' => 'nullable|integer|min:1|max:8',
            'employment_type' => 'required|string|max:100',
            'csc_eligibility' => ['nullable', Rule::in(array_keys(Plantilla::ELIGIBILITY_OPTIONS))],
            'education' => 'nullable|string|max:2000',
            'training' => 'nullable|string|max:2000',
            'experience' => 'nullable|string|max:2000',
            'competency' => 'nullable|string|max:2000',
        ]);

        Plantilla::findOrFail($id)->update($request->only(self::PLANTILLA_FIELDS));

        return redirect()->route('payroll.plantilla.index')
            ->with('status', 'Plantilla position updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Plantilla::findOrFail($id)->delete();

        return redirect()->route('payroll.plantilla.index')
            ->with('status', 'Plantilla position deleted.');
    }

    /**
     * Whether this request came in through the Mayor's view-only routes or Payroll Manager's own routes,
     * so the shared views know which route names to link to and whether to show mutating actions.
     */
    private function routePrefix(Request $request): string
    {
        return str_starts_with((string) $request->route()->getName(), 'mayor.') ? 'mayor' : 'payroll';
    }
}
