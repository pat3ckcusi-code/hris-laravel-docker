<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\TravelOrderExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TravelOrderController extends Controller
{
    protected DepartmentService $departmentService;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    // API endpoint to get employees in the same department(s) as the authenticated user
    public function getDepartmentEmployees(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['employees' => []]);
        }

        $deptIds = $this->resolveAccessibleDeptIds($user);
        if (empty($deptIds)) {
            return response()->json(['employees' => []]);
        }

        $employees = User::whereIn('Dept_id', $deptIds)
            ->select('id', 'EmpNo', 'name', 'last_name', 'first_name', 'designation')
            ->get();

        return response()->json(['employees' => $employees]);
    }

    // List travel orders for the authenticated user's department(s) (JSON)
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'data' => []]);
        }

        $empNos = $this->resolveAccessibleEmpNos($user);
        if (empty($empNos)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // fetch travel orders that include any of those emp_nos
        $orders = DB::table('travel_orders')
            ->join('travel_order_employees', 'travel_orders.id', '=', 'travel_order_employees.travel_order_id')
            ->whereIn('travel_order_employees.emp_no', $empNos)
            ->select('travel_orders.id', 'travel_orders.travel_order_num', 'travel_orders.destination', 'travel_orders.start_date', 'travel_orders.end_date', 'travel_orders.status', 'travel_orders.created_at')
            ->distinct()
            ->orderByDesc('travel_orders.created_at')
            ->get();

        $data = $orders->map(function ($o) {
            // fetch employee list for this order
            $emps = DB::table('travel_order_employees')
                ->where('travel_order_id', $o->id)
                ->pluck('emp_no')
                ->map(function ($eno) {
                    $u = User::where('EmpNo', $eno)->first();

                    return $u ? ($u->last_name.', '.$u->first_name) : $eno;
                })->values();

            return [
                'id' => $o->id,
                'travel_order_num' => $o->travel_order_num,
                'destination' => $o->destination,
                'departure' => optional($o)->start_date,
                'return' => optional($o)->end_date,
                'employees' => $emps,
                'status' => $o->status,
                'created_at' => $o->created_at,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // Return single travel order details as JSON
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (! $this->orderIsAccessible((int) $id, $this->resolveAccessibleEmpNos($user))) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $loaded = $this->loadOrderWithEmployees((int) $id);
        if (! $loaded) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $order = $loaded['order'];

        // Resolve creator and recommender names
        $creator = $order->created_by ? User::find($order->created_by) : null;
        $recommender = $order->recommender ? User::find($order->recommender) : null;

        $creatorName = $creator ? trim(($creator->last_name ?? '').', '.($creator->first_name ?? '')) : 'N/A';
        $recommenderName = $recommender ? trim(($recommender->last_name ?? '').', '.($recommender->first_name ?? '')) : 'N/A';

        return response()->json(['success' => true, 'data' => [
            'id' => $order->id,
            'travel_order_num' => $order->travel_order_num,
            'destination' => $order->destination,
            'report_to' => $order->report_to,
            'date_of_last_travel' => $order->date_of_last_travel,
            'departure' => $order->start_date,
            'return' => $order->end_date,
            'purpose' => $order->purpose,
            'per_diem' => $order->per_diem ?? null,
            'appropriation' => $order->appropriation ?? null,
            'remarks' => $order->Remarks ?? null,
            'status' => $order->status,
            'created_by' => $creatorName,
            'recommender' => $recommenderName,
            'created_at' => $order->created_at,
            'dept_office' => $loaded['deptOffice'],
            'employees' => $loaded['employees'],
        ]]);
    }

    // Stream the travel order filled into the official Travel Order.xlsx layout
    public function printExcel(Request $request, $id, TravelOrderExportService $exportService)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (! $this->orderIsAccessible((int) $id, $this->resolveAccessibleEmpNos($user))) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $loaded = $this->loadOrderWithEmployees((int) $id);
        if (! $loaded) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return $exportService->download($loaded['order'], $loaded['employees'], $loaded['deptOffice']);
    }

    // Show the creation form pre-filled for editing an existing Pending travel order
    public function edit(Request $request, $id)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if (! $this->orderIsAccessible((int) $id, $this->resolveAccessibleEmpNos($user))) {
            abort(404);
        }

        $loaded = $this->loadOrderWithEmployees((int) $id);
        if (! $loaded) {
            abort(404);
        }

        if (strtolower((string) $loaded['order']->status) !== 'pending') {
            abort(403, 'Only travel orders with Pending status can be edited.');
        }

        $selectedIds = User::whereIn('EmpNo', $loaded['employees']->pluck('EmpNo'))->pluck('id')->map(fn ($v) => (int) $v)->toArray();

        return view('department-head.travel-orders', [
            'order' => $loaded['order'],
            'selectedIds' => $selectedIds,
        ]);
    }

    // Update an existing Pending travel order (fields + employee list). The order number is preserved.
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'exists:users,id',
            'departure_date' => 'required|date',
            'return_date' => 'required|date|after_or_equal:departure_date',
            'destination' => 'required|string|max:255',
            'purpose' => 'required|string|max:2000',
            'remarks' => 'nullable|string|max:50',
            'per_diem' => 'nullable|string|max:255',
            'appropriation' => 'required|string|max:255',
            'report_to' => 'nullable|string|max:255',
            'date_of_last_travel' => 'nullable|date',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $this->orderIsAccessible((int) $id, $this->resolveAccessibleEmpNos($user))) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $order = DB::table('travel_orders')->where('id', $id)->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (strtolower((string) $order->status) !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only travel orders with "Pending" status can be edited.'], 422);
        }

        DB::table('travel_orders')->where('id', $id)->update([
            'purpose' => $validated['purpose'],
            'destination' => $validated['destination'],
            'per_diem' => $validated['per_diem'] ?? null,
            'appropriation' => $validated['appropriation'],
            'report_to' => $validated['report_to'] ?? null,
            'date_of_last_travel' => $validated['date_of_last_travel'] ?? null,
            'start_date' => $validated['departure_date'],
            'end_date' => $validated['return_date'],
            'Remarks' => $validated['remarks'] ?? '',
            'updated_at' => now(),
        ]);

        $this->syncEmployees((int) $id, $validated['employee_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Travel order updated successfully.',
            'redirect' => route('department-head.filed-travel-orders'),
        ]);
    }

    // Replace the employee list for a travel order with the given user ids.
    private function syncEmployees(int $travelOrderId, array $employeeIds): void
    {
        DB::table('travel_order_employees')->where('travel_order_id', $travelOrderId)->delete();

        foreach ($employeeIds as $empId) {
            $emp = User::find($empId);
            if ($emp) {
                DB::table('travel_order_employees')->insert([
                    'travel_order_id' => $travelOrderId,
                    'emp_no' => $emp->EmpNo,
                ]);
            }
        }
    }

    // Department ids the authenticated user (Department Head or Administrative Officer) can manage.
    private function resolveAccessibleDeptIds(User $user): array
    {
        $roleNorm = strtolower(str_replace(['-', '_'], ' ', trim((string) ($user->access_level ?? ''))));
        $depts = ($roleNorm === 'administrative officer')
            ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
            : $this->departmentService->resolveAllDepartmentsForUser($user);

        return $depts->pluck('Dept_id')->filter()->values()->toArray();
    }

    // EmpNos of employees belonging to the authenticated user's accessible department(s).
    private function resolveAccessibleEmpNos(User $user): array
    {
        $deptIds = $this->resolveAccessibleDeptIds($user);

        return empty($deptIds)
            ? []
            : User::whereIn('Dept_id', $deptIds)->pluck('EmpNo')->filter()->values()->toArray();
    }

    // True iff at least one employee on the given travel order is within the accessible EmpNo set.
    private function orderIsAccessible(int $orderId, array $accessibleEmpNos): bool
    {
        if (empty($accessibleEmpNos)) {
            return false;
        }

        return DB::table('travel_order_employees')
            ->where('travel_order_id', $orderId)
            ->whereIn('emp_no', $accessibleEmpNos)
            ->exists();
    }

    // Fetch a travel order plus its employees (in filing order) and derived department/office label
    private function loadOrderWithEmployees(int $id): ?array
    {
        $order = DB::table('travel_orders')->where('id', $id)->first();
        if (! $order) {
            return null;
        }

        $employees = DB::table('travel_order_employees')
            ->join('users', 'users.EmpNo', '=', 'travel_order_employees.emp_no')
            ->where('travel_order_employees.travel_order_id', $order->id)
            ->orderBy('travel_order_employees.id')
            ->select('users.EmpNo', 'users.last_name', 'users.first_name', 'users.designation', 'users.Dept_id')
            ->get()
            ->map(fn ($u) => [
                'EmpNo' => $u->EmpNo,
                'name' => trim(($u->last_name ?? '').', '.($u->first_name ?? ''), ', '),
                'designation' => $u->designation ?? '',
                'Dept_id' => $u->Dept_id,
            ])
            ->values();

        $deptOffice = null;
        $firstDeptId = $employees->pluck('Dept_id')->filter()->first();
        if ($firstDeptId) {
            $deptOffice = Department::where('Dept_id', $firstDeptId)->value('Dept_name');
        }

        return ['order' => $order, 'employees' => $employees, 'deptOffice' => $deptOffice];
    }

    // Store a new travel order
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'exists:users,id',
            'departure_date' => 'required|date',
            'return_date' => 'required|date|after_or_equal:departure_date',
            'destination' => 'required|string|max:255',
            'purpose' => 'required|string|max:2000',
            'remarks' => 'nullable|string|max:50',
            'per_diem' => 'nullable|string|max:255',
            'appropriation' => 'required|string|max:255',
            'report_to' => 'nullable|string|max:255',
            'date_of_last_travel' => 'nullable|date',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Generate travel order number
        $travelOrderNum = 'TO-'.now()->format('YmdHis').'-'.$user->id;

        // Create travel order
        $travelOrderId = DB::table('travel_orders')->insertGetId([
            'travel_order_num' => $travelOrderNum,
            'purpose' => $validated['purpose'],
            'destination' => $validated['destination'],
            'per_diem' => $validated['per_diem'] ?? null,
            'appropriation' => $validated['appropriation'],
            'report_to' => $validated['report_to'] ?? null,
            'date_of_last_travel' => $validated['date_of_last_travel'] ?? null,
            'start_date' => $validated['departure_date'],
            'end_date' => $validated['return_date'],
            'Remarks' => $validated['remarks'] ?? '',
            'recommender' => $user->id, // or assign as needed
            'created_by' => $user->id,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
            'rejection_note' => '',
        ]);

        // Save employees
        foreach ($validated['employee_ids'] as $empId) {
            $emp = User::find($empId);
            if ($emp) {
                DB::table('travel_order_employees')->insert([
                    'travel_order_id' => $travelOrderId,
                    'emp_no' => $emp->EmpNo,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Travel order submitted successfully.',
            'redirect' => route('department-head.filed-travel-orders'),
        ]);
    }
}
