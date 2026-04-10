<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Dtr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $records = Dtr::where('employee_id', $request->user()->id)
            ->latest('date')
            ->paginate(20);

        return view('employee.attendance', compact('records'));
    }
}
