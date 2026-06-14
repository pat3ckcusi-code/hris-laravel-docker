<?php

namespace App\Http\Controllers;

use App\Mail\LeaveRequestStatusNotification;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Response;

class DevController extends Controller
{
    public function previewLeaveEmail(): string|Response
    {
        if (! app()->environment('local')) {
            abort(404);
        }

        $employee = User::first();
        $leave = LeaveRequest::latest()->first();

        if (! $employee || ! $leave) {
            return 'No sample employee or leave request found in the database.';
        }

        if (! empty($employee->Dept_id)) {
            $dept = Department::find($employee->Dept_id);
            $employee->department_name = $dept->Dept_name ?? null;
        }

        $formatted = [
            'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
            'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
            'end' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
        ];

        $mailable = new LeaveRequestStatusNotification($employee, $leave, $formatted, 'approved');

        return $mailable->render();
    }
}
