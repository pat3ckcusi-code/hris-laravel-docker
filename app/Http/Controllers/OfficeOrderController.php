<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\OfficeOrderWordExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OfficeOrderController extends Controller
{
    protected DepartmentService $departmentService;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    // List office orders relevant to the authenticated user's department(s)
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'data' => []]);
        }

        $roleNorm = strtolower(str_replace(['-', '_'], ' ', trim((string) ($user->access_level ?? ''))));
        $depts = ($roleNorm === 'administrative officer')
            ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
            : $this->departmentService->resolveAllDepartmentsForUser($user);
        $deptIds = $depts->pluck('Dept_id')->filter()->values()->toArray();
        $empNos = empty($deptIds)
            ? []
            : User::whereIn('Dept_id', $deptIds)->pluck('EmpNo')->filter()->values()->toArray();

        if (empty($empNos)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $orders = DB::table('office_orders')
            ->join('office_order_employees', 'office_orders.id', '=', 'office_order_employees.office_order_id')
            ->whereIn('office_order_employees.emp_no', $empNos)
            ->select('office_orders.id', 'office_orders.office_order_num', 'office_orders.subject', 'office_orders.issued_date', 'office_orders.effective_date', 'office_orders.status', 'office_orders.created_at')
            ->distinct()
            ->orderByDesc('office_orders.created_at')
            ->get();

        $data = $orders->map(function ($o) {
            $emps = DB::table('office_order_employees')
                ->where('office_order_id', $o->id)
                ->pluck('emp_no')
                ->map(function ($eno) {
                    $u = User::where('EmpNo', $eno)->first();

                    return $u ? ($u->last_name.', '.$u->first_name) : $eno;
                })->sort()->values();

            return [
                'id' => $o->id,
                'office_order_num' => $o->office_order_num,
                'subject' => $o->subject,
                'issued_date' => optional($o)->issued_date,
                'effective_date' => optional($o)->effective_date,
                'employees' => $emps,
                'status' => $o->status,
                'created_at' => $o->created_at,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // Show single office order
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $order = DB::table('office_orders')->where('id', $id)->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $order->id,
            'office_order_num' => $order->office_order_num,
            'subject' => $order->subject,
            'issued_date' => $order->issued_date,
            'effective_date' => $order->effective_date,
            'details' => $order->details ?? null,
            'remarks' => $order->Remarks ?? null,
            'status' => $order->status,
            'created_at' => $order->created_at,
            'employees' => $this->recipients($order),
            'issued_by' => $this->issuer($order),
        ]]);
    }

    // Render the office order as a printable memo (To / From / Subject / Date)
    public function print(Request $request, $id)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $order = DB::table('office_orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        return view('department-head.office-order-print', [
            'order' => $order,
            'employees' => $this->recipients($order),
            'issuer' => $this->issuer($order),
        ]);
    }

    // Download the office order as a Word (.docx) memo
    public function downloadWord(Request $request, $id)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $order = DB::table('office_orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        return app(OfficeOrderWordExportService::class)->download($order, $this->recipients($order), $this->issuer($order));
    }

    // Recipients ("To") for an office order, as full-name + designation pairs, ordered by last name.
    private function recipients($order)
    {
        $empNos = DB::table('office_order_employees')->where('office_order_id', $order->id)->pluck('emp_no')->toArray();

        return User::whereIn('EmpNo', $empNos)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()->map(function ($u) {
                return ['name' => trim($u->first_name.' '.$u->last_name), 'designation' => $u->designation ?? ''];
            })->values();
    }

    // "From" for an office order: the head of the recipients' department,
    // falling back to the creating user when no department head is on file.
    private function issuer($order)
    {
        $firstEmpNo = DB::table('office_order_employees')->where('office_order_id', $order->id)->value('emp_no');
        if ($firstEmpNo) {
            $recipient = User::where('EmpNo', $firstEmpNo)->first();
            if ($recipient && ! empty($recipient->Dept_id)) {
                $dept = Department::where('Dept_id', $recipient->Dept_id)->first();
                if ($dept && ! empty($dept->EmpNo)) {
                    $head = User::where('EmpNo', $dept->EmpNo)->first();
                    if ($head) {
                        return [
                            'name' => trim($head->first_name.' '.$head->last_name),
                            'designation' => $head->designation ?: ($dept->Designation ?? ''),
                        ];
                    }
                }
            }
        }

        if (! $order->created_by) {
            return null;
        }
        $creator = User::find($order->created_by);

        return $creator ? ['name' => trim($creator->first_name.' '.$creator->last_name), 'designation' => $creator->designation ?? ''] : null;
    }

    // Store a new office order
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'exists:users,id',
            'office_order_num' => 'required|string|max:255',
            'issued_date' => 'required|date',
            'effective_date' => 'nullable|date|after_or_equal:issued_date',
            'subject' => 'required|string|max:255',
            'details' => 'required|string|max:5000',
            'remarks' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $officeOrderNum = $validated['office_order_num'];

        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => $officeOrderNum,
            'subject' => $validated['subject'],
            'issued_date' => $validated['issued_date'],
            'effective_date' => $validated['effective_date'] ?? null,
            'details' => $validated['details'],
            'Remarks' => $validated['remarks'] ?? '',
            'created_by' => $user->id,
            'status' => 'Pending Recommendation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncEmployees($officeOrderId, $validated['employee_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Office order submitted successfully.',
            'redirect' => route('department-head.filed-office-orders'),
        ]);
    }

    // Show the creation form pre-filled for editing an existing office order
    public function edit(Request $request, $id)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $order = DB::table('office_orders')->where('id', $id)->first();
        if (! $order) {
            abort(404);
        }

        $empNos = DB::table('office_order_employees')->where('office_order_id', $order->id)->pluck('emp_no')->toArray();
        $selectedIds = User::whereIn('EmpNo', $empNos)->pluck('id')->map(fn ($v) => (int) $v)->toArray();

        return view('department-head.office-orders', [
            'order' => $order,
            'selectedIds' => $selectedIds,
        ]);
    }

    // Update an existing office order (recipients + memo fields, including the order number).
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'exists:users,id',
            'office_order_num' => 'required|string|max:255',
            'issued_date' => 'required|date',
            'effective_date' => 'nullable|date|after_or_equal:issued_date',
            'subject' => 'required|string|max:255',
            'details' => 'required|string|max:5000',
            'remarks' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $order = DB::table('office_orders')->where('id', $id)->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        DB::table('office_orders')->where('id', $id)->update([
            'office_order_num' => $validated['office_order_num'],
            'subject' => $validated['subject'],
            'issued_date' => $validated['issued_date'],
            'effective_date' => $validated['effective_date'] ?? null,
            'details' => $validated['details'],
            'Remarks' => $validated['remarks'] ?? '',
            'updated_at' => now(),
        ]);

        $this->syncEmployees($id, $validated['employee_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Office order updated successfully.',
            'redirect' => route('department-head.filed-office-orders'),
        ]);
    }

    // Replace the recipient list for an office order with the given user ids.
    private function syncEmployees($officeOrderId, array $employeeIds)
    {
        DB::table('office_order_employees')->where('office_order_id', $officeOrderId)->delete();

        foreach ($employeeIds as $empId) {
            $emp = User::find($empId);
            if ($emp) {
                DB::table('office_order_employees')->insert([
                    'office_order_id' => $officeOrderId,
                    'emp_no' => $emp->EmpNo,
                ]);
            }
        }
    }
}
