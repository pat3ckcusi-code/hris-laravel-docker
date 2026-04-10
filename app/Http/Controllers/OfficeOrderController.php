<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OfficeOrderController extends Controller
{
    // List office orders relevant to the authenticated user's department
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['success' => false, 'data' => []]);

        $empNos = [];
        if (!empty($user->Dept_id)) {
            $empNos = User::where('Dept_id', $user->Dept_id)->pluck('EmpNo')->filter()->values()->toArray();
        }

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
                    return $u ? ($u->last_name . ', ' . $u->first_name) : $eno;
                })->values();

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
        if (!$user) return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        $order = DB::table('office_orders')->where('id', $id)->first();
        if (!$order) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        $empNos = DB::table('office_order_employees')->where('office_order_id', $order->id)->pluck('emp_no')->toArray();
        $employees = User::whereIn('EmpNo', $empNos)->get()->map(function ($u) {
            return ['EmpNo' => $u->EmpNo, 'name' => ($u->last_name . ', ' . $u->first_name), 'designation' => $u->designation ?? ''];
        })->values();

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
            'employees' => $employees,
        ]]);
    }

    // Store a new office order
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'exists:users,id',
            'issued_date' => 'required|date',
            'effective_date' => 'nullable|date|after_or_equal:issued_date',
            'subject' => 'required|string|max:255',
            'details' => 'required|string',
            'remarks' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $officeOrderNum = 'OO-' . now()->format('YmdHis') . '-' . $user->id;

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

        foreach ($validated['employee_ids'] as $empId) {
            $emp = User::find($empId);
            if ($emp) {
                DB::table('office_order_employees')->insert([
                    'office_order_id' => $officeOrderId,
                    'emp_no' => $emp->EmpNo,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Office order submitted successfully.',
            'redirect' => route('department-head.filed-office-orders'),
        ]);
    }
}
