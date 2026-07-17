<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\UniformInspection;
use App\Models\UniformInspectionDetail;
use App\Models\User;
use App\Services\UniformInspectionDeductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UniformInspectionController extends Controller
{
    public function __construct(private readonly UniformInspectionDeductionService $deductionService) {}

    public function index(Request $request): View
    {
        $query = UniformInspection::with(['details.employee']);

        if ($request->filled('date')) {
            $query->whereDate('inspection_date', $request->date);
        }
        if ($request->filled('department_id')) {
            $query->whereHas('details.employee', fn ($q) => $q->where('Dept_id', $request->department_id));
        }
        if ($request->filled('violation_type')) {
            $query->whereHas('details', fn ($q) => $q->where('violation_type', $request->violation_type));
        }
        if ($request->filled('employee_id')) {
            $query->whereHas('details', fn ($q) => $q->where('employee_id', $request->employee_id));
        }

        $inspections = $query->orderByDesc('inspection_date')
            ->orderByDesc('inspection_time')
            ->paginate(25)
            ->withQueryString();

        $departments = Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);
        $employees = User::where('Status', 'Active')
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'EmpNo', 'first_name', 'last_name']);
        $violationTypes = UniformInspection::VALID_VIOLATION_TYPES;

        return view('uniform-inspection.index', compact(
            'inspections', 'departments', 'employees', 'violationTypes'
        ));
    }

    public function create(): View
    {
        $violationTypes = UniformInspection::VALID_VIOLATION_TYPES;

        return view('uniform-inspection.create', compact('violationTypes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $violationTypeList = implode(',', UniformInspection::VALID_VIOLATION_TYPES);

        $validated = $request->validate([
            'inspection_date' => ['required', 'date'],
            'inspection_time' => ['required', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'details' => ['required', 'array', 'min:1', 'max:50'],
            'details.*.employee_id' => ['required', 'integer', 'exists:users,id'],
            'details.*.violation_type' => ['required', 'string', 'in:'.$violationTypeList],
            'details.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $inspection = UniformInspection::create([
                'inspection_date' => $validated['inspection_date'],
                'inspection_time' => $validated['inspection_time'],
                'remarks' => $validated['remarks'] ?? null,
            ]);

            foreach ($validated['details'] as $index => $detailData) {
                $offenseNumber = UniformInspectionDetail::where('employee_id', $detailData['employee_id'])
                    ->count() + 1;

                UniformInspectionDetail::create([
                    'uniform_inspection_id' => $inspection->id,
                    'employee_id' => $detailData['employee_id'],
                    'violation_type' => $detailData['violation_type'],
                    'offense_number' => $offenseNumber,
                    'remarks' => $detailData['remarks'] ?? null,
                ]);
            }

            $employeeIds = collect($validated['details'])->pluck('employee_id')->unique()->all();
            $deductionResult = $this->deductionService->applyForNewEmployees($inspection, $employeeIds, Auth::user());

            HRAuditTrail::create([
                'actor_user_id' => Auth::id(),
                'module' => 'uniform_inspection',
                'action' => 'create',
                'target_type' => 'uniform_inspection',
                'target_id' => $inspection->id,
                'details' => [
                    'inspection_date' => $validated['inspection_date'],
                    'detail_count' => count($validated['details']),
                ],
            ]);

            return ['inspection' => $inspection, 'deduction' => $deductionResult];
        });

        $inspection = $result['inspection'];

        if (! empty($result['deduction']['skipped'])) {
            $names = collect($result['deduction']['skipped'])
                ->map(fn ($u) => trim(($u->last_name ?? '').', '.($u->first_name ?? '')) ?: 'Employee #'.$u->id)
                ->implode('; ');
            session()->flash('warning', "VL deduction skipped (insufficient balance) for: {$names}. Violation record was saved.");
        }

        return redirect()
            ->route('leave-manager.uniform-inspections.show', $inspection)
            ->with('success', 'Inspection recorded successfully.');
    }

    public function show(UniformInspection $uniformInspection): View
    {
        $uniformInspection->load(['department', 'details.employee.department']);

        return view('uniform-inspection.show', [
            'inspection' => $uniformInspection,
            'violationTypes' => UniformInspection::VALID_VIOLATION_TYPES,
        ]);
    }

    public function edit(UniformInspection $uniformInspection): View
    {
        $uniformInspection->load(['details.employee']);

        $violationTypes = UniformInspection::VALID_VIOLATION_TYPES;

        return view('uniform-inspection.edit', compact('uniformInspection', 'violationTypes'));
    }

    public function update(Request $request, UniformInspection $uniformInspection): RedirectResponse
    {
        $violationTypeList = implode(',', UniformInspection::VALID_VIOLATION_TYPES);

        $validated = $request->validate([
            'inspection_date' => ['required', 'date'],
            'inspection_time' => ['required', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'details' => ['required', 'array', 'min:1', 'max:50'],
            'details.*.id' => ['nullable', 'integer', 'exists:uniform_inspection_details,id'],
            'details.*.employee_id' => ['required', 'integer', 'exists:users,id'],
            'details.*.violation_type' => ['required', 'string', 'in:'.$violationTypeList],
            'details.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $deductionResult = DB::transaction(function () use ($validated, $uniformInspection) {
            $uniformInspection->update([
                'inspection_date' => $validated['inspection_date'],
                'inspection_time' => $validated['inspection_time'],
                'remarks' => $validated['remarks'] ?? null,
            ]);

            $keptIds = collect($validated['details'])
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            $uniformInspection->details()
                ->whereNotIn('id', $keptIds)
                ->each(fn (UniformInspectionDetail $detail) => $detail->delete());

            foreach ($validated['details'] as $detailData) {
                $existingId = isset($detailData['id']) ? (int) $detailData['id'] : null;

                if ($existingId) {
                    $detail = UniformInspectionDetail::find($existingId);
                    if (! $detail) {
                        continue;
                    }

                    $detail->violation_type = $detailData['violation_type'];
                    $detail->remarks = $detailData['remarks'] ?? null;
                    $detail->save();
                } else {
                    $offenseNumber = UniformInspectionDetail::where('employee_id', $detailData['employee_id'])
                        ->count() + 1;

                    UniformInspectionDetail::create([
                        'uniform_inspection_id' => $uniformInspection->id,
                        'employee_id' => $detailData['employee_id'],
                        'violation_type' => $detailData['violation_type'],
                        'offense_number' => $offenseNumber,
                        'remarks' => $detailData['remarks'] ?? null,
                    ]);
                }
            }

            $currentEmployeeIds = $uniformInspection->details()->pluck('employee_id')->unique()->all();
            $deductionResult = $this->deductionService->reconcile($uniformInspection, $currentEmployeeIds, Auth::user());

            HRAuditTrail::create([
                'actor_user_id' => Auth::id(),
                'module' => 'uniform_inspection',
                'action' => 'update',
                'target_type' => 'uniform_inspection',
                'target_id' => $uniformInspection->id,
                'details' => ['inspection_date' => $validated['inspection_date']],
            ]);

            return $deductionResult;
        });

        if (! empty($deductionResult['skipped'])) {
            $names = collect($deductionResult['skipped'])
                ->map(fn ($u) => trim(($u->last_name ?? '').', '.($u->first_name ?? '')) ?: 'Employee #'.$u->id)
                ->implode('; ');
            session()->flash('warning', "VL deduction skipped (insufficient balance) for: {$names}. Violation record was saved.");
        }

        return redirect()
            ->route('leave-manager.uniform-inspections.show', $uniformInspection)
            ->with('success', 'Inspection updated successfully.');
    }

    public function destroy(UniformInspection $uniformInspection): RedirectResponse
    {
        $id = $uniformInspection->id;

        DB::transaction(function () use ($uniformInspection, $id) {
            $this->deductionService->refundAllForInspection($uniformInspection, Auth::user());

            $uniformInspection->delete();

            HRAuditTrail::create([
                'actor_user_id' => Auth::id(),
                'module' => 'uniform_inspection',
                'action' => 'delete',
                'target_type' => 'uniform_inspection',
                'target_id' => $id,
                'details' => [],
            ]);
        });

        return redirect()
            ->route('leave-manager.uniform-inspections.index')
            ->with('success', 'Inspection deleted.');
    }

    public function apiEmployeeViolationHistory(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $rows = UniformInspectionDetail::with('inspection')
            ->where('employee_id', $request->employee_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'inspection_date' => $v->inspection?->inspection_date?->format('Y-m-d'),
                'violation_type' => $v->violation_type,
                'offense_number' => $v->offense_number,
                'remarks' => $v->remarks,
            ]);

        return response()->json(['data' => $rows]);
    }
}
