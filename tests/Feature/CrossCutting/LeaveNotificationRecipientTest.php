<?php

namespace Tests\Feature\CrossCutting;

use App\Models\Department;
use App\Notifications\HrisTransactionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Regression coverage for leave-notification recipient resolution.
 *
 * Departments used to resolve their notification recipient (department head /
 * administrative officer) by matching a free-standing EmpNo string. Nothing
 * kept that string in sync when a user's role changed, so a former department
 * head could keep silently receiving leave notifications for employees they
 * no longer manage. The fix keys departments to users.id via a real FK
 * (department_head_id / admin_officer_id) and validates the role at read time.
 */
class LeaveNotificationRecipientTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers;

    public function test_current_department_head_receives_leave_filed_notification(): void
    {
        $dh = $this->createDepartmentHead();
        $dept = Department::find($dh->Dept_id);
        $dept->department_head_id = $dh->id;
        $dept->save();

        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $this->createLeaveBalance($employee);

        Notification::fake();

        $response = $this->actingAs($employee)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        $this->assertTrue($response->isSuccessful() || $response->isRedirection());

        Notification::assertSentTo($dh, HrisTransactionNotification::class);
    }

    public function test_department_head_demoted_to_employee_no_longer_receives_leave_notifications(): void
    {
        $recordsManager = $this->createRecordsManager();
        $dh = $this->createDepartmentHead();
        $dept = Department::find($dh->Dept_id);
        $dept->department_head_id = $dh->id;
        $dept->save();

        // Demote the former department head through the real update endpoint,
        // exercising the cascade-clear added to RecordsManagerController::update.
        $this->actingAs($recordsManager)->put(route('dashboard.records-manager.users.update', $dh->id), [
            'last_name' => $dh->last_name,
            'first_name' => $dh->first_name,
            'middle_name' => $dh->middle_name,
            'email' => $dh->email,
            'EmpNo' => $dh->EmpNo,
            'designation' => $dh->designation,
            'Dept_id' => $dh->Dept_id,
            'Status' => 'Active',
            'employee_type' => $dh->employee_type,
            'access_level' => 'employee',
            'date_hired' => now()->toDateString(),
        ]);

        $dept->refresh();
        $this->assertNull($dept->department_head_id, 'department_head_id should be cleared once the user is no longer a department head.');

        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $this->createLeaveBalance($employee);

        Notification::fake();

        $this->actingAs($employee)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        Notification::assertNotSentTo($dh, HrisTransactionNotification::class);
    }
}
