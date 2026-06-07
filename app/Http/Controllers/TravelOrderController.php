<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TravelOrderController extends Controller
{
    // API endpoint to get employees in the same department as the authenticated user
    public function getDepartmentEmployees(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->Dept_id) {
            return response()->json(['employees' => []]);
        }
        $employees = User::where('Dept_id', $user->Dept_id)
            ->select('id', 'EmpNo', 'name', 'last_name', 'first_name', 'designation')
            ->get();
        return response()->json(['employees' => $employees]);
    }

    // List travel orders for the authenticated user's department (JSON)
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['success' => false, 'data' => []]);

        // collect employee EmpNo values for user's department
        $empNos = [];
        if (!empty($user->Dept_id)) {
            $empNos = User::where('Dept_id', $user->Dept_id)->pluck('EmpNo')->filter()->values()->toArray();
        }

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
                    return $u ? ($u->last_name . ', ' . $u->first_name) : $eno;
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
        if (!$user) return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);

        $order = DB::table('travel_orders')->where('id', $id)->first();
        if (!$order) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        $empNos = DB::table('travel_order_employees')->where('travel_order_id', $order->id)->pluck('emp_no')->toArray();
        $employees = User::whereIn('EmpNo', $empNos)->get()->map(function ($u) {
            return ['EmpNo' => $u->EmpNo, 'name' => ($u->last_name . ', ' . $u->first_name), 'designation' => $u->designation ?? ''];
        })->values();

        // Resolve creator and recommender names
        $creator = $order->created_by ? User::find($order->created_by) : null;
        $recommender = $order->recommender ? User::find($order->recommender) : null;

        $creatorName = $creator ? trim(($creator->last_name ?? '') . ', ' . ($creator->first_name ?? '')) : 'N/A';
        $recommenderName = $recommender ? trim(($recommender->last_name ?? '') . ', ' . ($recommender->first_name ?? '')) : 'N/A';

        return response()->json(['success' => true, 'data' => [
            'id' => $order->id,
            'travel_order_num' => $order->travel_order_num,
            'destination' => $order->destination,
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
            'employees' => $employees,
        ]]);
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
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Generate travel order number
        $travelOrderNum = 'TO-' . now()->format('YmdHis') . '-' . $user->id;

        // Create travel order
        $travelOrderId = DB::table('travel_orders')->insertGetId([
            'travel_order_num' => $travelOrderNum,
            'purpose' => $validated['purpose'],
            'destination' => $validated['destination'],
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
