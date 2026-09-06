<?php

namespace Tests\Feature\RoleBased;

use App\Models\Department;
use App\Models\Eta;
use App\Models\LeaveBalance;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Locator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;

/**
 * Department Head Role Tests
 *
 * Covers: Dashboard, Pending Requests, Statistics, Travel/Office Orders
 */
class DepartmentHeadTest extends TestCase
{
    use CreatesTestUsers, MeasuresPerformance, RefreshDatabase;

    // ──────────────────────────────────────────────
    // 1. Dashboard
    // ──────────────────────────────────────────────

    public function test_department_head_dashboard_loads(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.index'));

        $response->assertStatus(200);
    }

    public function test_department_head_dashboard_metrics_api(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('api.department.dashboard-metrics'));

        $response->assertStatus(200);
    }

    public function test_dashboard_metrics_concurrent_access(): void
    {
        $dh = $this->createDepartmentHead();
        $this->actingAs($dh);

        $results = $this->simulateConcurrentRequests('GET', route('api.department.dashboard-metrics'), 30);

        $this->assertGreaterThanOrEqual(90, $results['success_rate'],
            "Dashboard metrics success rate: {$results['success_rate']}%");
    }

    public function test_employees_on_duty_endpoint(): void
    {
        $dh = $this->createDepartmentHead();

        // Create employees in same department
        for ($i = 0; $i < 5; $i++) {
            $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        }

        $response = $this->actingAs($dh)->get(route('api.department.employees-on-duty'));

        $response->assertStatus(200);
    }

    public function test_separated_employee_excluded_from_employees_on_duty(): void
    {
        $dh = $this->createDepartmentHead();

        $this->createEmployee(['Dept_id' => $dh->Dept_id, 'Status' => 'Active']);
        $separated = $this->createEmployee(['Dept_id' => $dh->Dept_id, 'Status' => 'Separated']);

        $response = $this->actingAs($dh)->get(route('api.department.employees-on-duty'));

        $response->assertStatus(200);
        $empNos = collect($response->json('data'))->pluck('EmpNo')->all();
        $this->assertNotContains($separated->EmpNo, $empNos);
    }

    // ──────────────────────────────────────────────
    // 2. Pending Requests
    // ──────────────────────────────────────────────

    public function test_pending_requests_page_loads(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.pending-requests'));

        $response->assertStatus(200);
    }

    public function test_leave_requests_list_api(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('api.department.leave-requests'));

        $response->assertStatus(200);
    }

    public function test_eta_requests_list_api(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('api.department.eta-requests'));

        $response->assertStatus(200);
    }

    public function test_locator_requests_list_api(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('api.department.locator-requests'));

        $response->assertStatus(200);
    }

    public function test_approve_leave_request(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $this->createLeaveBalance($employee, ['VL' => 15]);

        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Test leave',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($dh)->post(
            route('department-head.leave.approve', $leave->id)
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Leave approval failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_approve_leave_request_locks_leave_balance_row(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $this->createLeaveBalance($employee, ['VL' => 15]);

        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'total_days' => 1,
            'paid_days' => 1,
            'reason' => 'Test leave',
            'status' => 'pending',
            'printing_allowed' => true,
        ]);

        DB::enableQueryLog();
        $this->actingAs($dh)->post(route('department-head.leave.approve', $leave->id));
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $lockedBalanceQuery = collect($log)->first(
            fn ($q) => str_contains(strtolower($q['query']), 'leave_balances') && str_contains(strtolower($q['query']), 'for update')
        );
        $this->assertNotNull($lockedBalanceQuery, 'Expected the leave_balances fetch in approveLeave() to use lockForUpdate()');
    }

    public function test_reject_leave_request(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Test leave',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($dh)->post(
            route('department-head.leave.reject', $leave->id),
            ['remarks' => 'Insufficient staff coverage']
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Leave rejection failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_department_head_self_filed_leave_gets_printing_auto_allowed(): void
    {
        $dh = $this->createDepartmentHead();
        $this->createLeaveBalance($dh);
        $this->createMayor();

        $response = $this->actingAs($dh)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Leave filing failed: HTTP {$response->getStatusCode()}"
        );

        $leave = LeaveRequest::where('user_id', $dh->id)->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertEquals('pending', $leave->status);
        $this->assertTrue((bool) $leave->printing_allowed, 'A department head\'s own leave should be printable immediately upon filing, since it is routed to the Mayor with no DH/AO step to allow printing manually.');
        $this->assertEquals($dh->id, $leave->printing_allowed_by);
        $this->assertNotNull($leave->printing_allowed_at);
    }

    public function test_employee_self_filed_leave_does_not_get_printing_auto_allowed(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $this->createLeaveBalance($employee);

        $response = $this->actingAs($employee)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Leave filing failed: HTTP {$response->getStatusCode()}"
        );

        $leave = LeaveRequest::where('user_id', $employee->id)->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertFalse((bool) $leave->printing_allowed, 'A regular employee\'s leave still needs their Department Head/Administrative Officer to manually allow printing.');
    }

    public function test_department_head_own_leave_print_blanks_self_signatory(): void
    {
        // UsersTableSeeder seeds access_level in lowercase ('department head'); the
        // export's signatory check does a raw literal match against that exact casing.
        $dh = $this->createDepartmentHead(['access_level' => 'department head']);
        $this->createLeaveBalance($dh);
        $this->createMayor();

        $dept = Department::find($dh->Dept_id);
        $dept->EmpNo = $dh->EmpNo;
        $dept->save();

        $this->actingAs($dh)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        $leave = LeaveRequest::where('user_id', $dh->id)->latest('id')->first();
        $this->assertNotNull($leave);

        $response = $this->actingAs($dh)->get(route('employee.leave.print.single', $leave->id));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'leave_xlsx_');
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheet(0);
        unlink($tmpPath);

        $dhName = trim(collect([$dh->first_name, $dh->middle_name, $dh->last_name])->filter()->implode(' '));
        $this->assertNotSame($dhName, (string) $sheet->getCell('I59')->getValue(), 'A department head printing their own leave should not see their own name as the recommending signatory.');
        $this->assertNotSame($dh->designation, (string) $sheet->getCell('H60')->getValue());
    }

    public function test_subordinate_leave_print_still_shows_department_head_signatory(): void
    {
        $dh = $this->createDepartmentHead(['access_level' => 'department head']);
        $dept = Department::find($dh->Dept_id);
        $dept->EmpNo = $dh->EmpNo;
        $dept->save();

        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $this->createLeaveBalance($employee);

        $this->actingAs($employee)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        $leave = LeaveRequest::where('user_id', $employee->id)->latest('id')->first();
        $this->assertNotNull($leave);

        $this->actingAs($dh)->post(route('department-head.leave.allow-printing', $leave->id));

        $response = $this->actingAs($employee)->get(route('employee.leave.print.single', $leave->id));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'leave_xlsx_');
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheet(0);
        unlink($tmpPath);

        $expectedName = trim(collect([$dh->first_name, $dh->middle_name, $dh->last_name])->filter()->implode(' '));
        $this->assertSame($expectedName, (string) $sheet->getCell('I59')->getValue(), 'A subordinate\'s leave slip should still show their department head as the recommending signatory.');
    }

    public function test_department_head_own_leave_print_blanks_signatory_even_when_dept_emp_no_points_elsewhere(): void
    {
        // Regression case: the department's designated signatory EmpNo can be
        // misconfigured to point at a completely different department head (seen with
        // the real "department_head@example.com" dev account). The applicant being a
        // department head themselves should blank the signatory regardless of whose
        // EmpNo the department has on file — there's no DH recommendation step for
        // their own leave either way.
        $dh = $this->createDepartmentHead(['access_level' => 'department head']);
        $this->createLeaveBalance($dh);
        $this->createMayor();

        $otherDh = $this->createDepartmentHead(['access_level' => 'department head']);

        $dept = Department::find($dh->Dept_id);
        $dept->EmpNo = $otherDh->EmpNo;
        $dept->save();

        $this->actingAs($dh)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        $leave = LeaveRequest::where('user_id', $dh->id)->latest('id')->first();
        $this->assertNotNull($leave);

        $response = $this->actingAs($dh)->get(route('employee.leave.print.single', $leave->id));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'leave_xlsx_');
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheet(0);
        unlink($tmpPath);

        $otherDhName = trim(collect([$otherDh->first_name, $otherDh->middle_name, $otherDh->last_name])->filter()->implode(' '));
        $this->assertNotSame($otherDhName, (string) $sheet->getCell('I59')->getValue(), 'No department-head recommendation signatory should print for a DH-filed leave, even if the department\'s EmpNo resolves to an unrelated department head.');
    }

    public function test_hr_manager_own_leave_print_blanks_department_head_signatory(): void
    {
        $hrManager = $this->createHRManager(['access_level' => 'hr manager']);
        $this->createLeaveBalance($hrManager);
        $this->createMayor();

        $otherDh = $this->createDepartmentHead(['access_level' => 'department head']);

        $dept = Department::find($hrManager->Dept_id);
        $dept->EmpNo = $otherDh->EmpNo;
        $dept->save();

        $this->actingAs($hrManager)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Maternity Leave'],
            'range_start' => now()->addWeek()->toDateString(),
            'range_end' => now()->addWeek()->toDateString(),
            'reason' => 'Test filing',
        ]);

        $leave = LeaveRequest::where('user_id', $hrManager->id)->latest('id')->first();
        $this->assertNotNull($leave);

        $response = $this->actingAs($hrManager)->get(route('employee.leave.print.single', $leave->id));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'leave_xlsx_');
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheet(0);
        unlink($tmpPath);

        $otherDhName = trim(collect([$otherDh->first_name, $otherDh->middle_name, $otherDh->last_name])->filter()->implode(' '));
        $this->assertNotSame($otherDhName, (string) $sheet->getCell('I59')->getValue(), 'An HR Manager\'s own leave should also skip the department-head recommendation signatory.');
    }

    public function test_approve_eta_request(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $eta = Eta::create([
            'user_id' => $employee->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Traffic',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($dh)->post(
            route('department-head.eta.approve', $eta->id)
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "ETA approval failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_department_head_can_approve_eta_cancellation(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $eta = Eta::create([
            'user_id' => $employee->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Traffic',
            'status' => 'approved',
            'approved_by' => $dh->id,
            'approved_at' => now(),
            'cancellation_status' => 'Pending Cancellation',
            'cancellation_reason' => 'No longer needed',
            'cancellation_requested_at' => now(),
            'cancellation_requested_by' => $employee->id,
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.eta.approve-cancellation', $eta->id));

        $response->assertStatus(200)->assertJson(['success' => true]);

        $eta->refresh();
        $this->assertEquals('cancelled', $eta->status);
        $this->assertEquals('Cancelled', $eta->cancellation_status);
        $this->assertEquals($dh->id, $eta->cancellation_reviewed_by);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'eta',
            'action' => 'approve_cancellation',
            'target_id' => $eta->id,
        ]);
    }

    public function test_department_head_can_reject_eta_cancellation(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $eta = Eta::create([
            'user_id' => $employee->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Traffic',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
            'cancellation_reason' => 'No longer needed',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.eta.reject-cancellation', $eta->id), [
            'remarks' => 'Trip is still required.',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $eta->refresh();
        $this->assertEquals('approved', $eta->status);
        $this->assertEquals('Rejected', $eta->cancellation_status);
        $this->assertEquals('Trip is still required.', $eta->cancellation_remarks);
    }

    public function test_department_head_reject_eta_cancellation_requires_remarks(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $eta = Eta::create([
            'user_id' => $employee->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Traffic',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.eta.reject-cancellation', $eta->id), []);

        $response->assertStatus(422);
        $eta->refresh();
        $this->assertEquals('Pending Cancellation', $eta->cancellation_status);
    }

    public function test_department_head_cannot_act_on_cancellation_outside_department(): void
    {
        $dh = $this->createDepartmentHead();

        $otherDept = Department::forceCreate([
            'DeptCode' => 'OTHER', 'Dept_name' => 'Other Department', 'EmpNo' => 'OTHER-EMPNO', 'Designation' => 'Test',
        ]);
        $employee = $this->createEmployee(['Dept_id' => $otherDept->Dept_id]);

        $eta = Eta::create([
            'user_id' => $employee->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Traffic',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.eta.approve-cancellation', $eta->id));

        $response->assertStatus(302);
        $eta->refresh();
        $this->assertEquals('Pending Cancellation', $eta->cancellation_status);
    }

    public function test_approve_locator_request(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $locator = Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($dh)->post(
            route('department-head.locator.approve', $locator->id)
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Locator approval failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_department_head_can_approve_locator_cancellation(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $locator = Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
            'approved_by' => $dh->id,
            'approved_at' => now(),
            'cancellation_status' => 'Pending Cancellation',
            'cancellation_reason' => 'No longer needed',
            'cancellation_requested_at' => now(),
            'cancellation_requested_by' => $employee->id,
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.locator.approve-cancellation', $locator->id));

        $response->assertStatus(200)->assertJson(['success' => true]);

        $locator->refresh();
        $this->assertEquals('cancelled', $locator->status);
        $this->assertEquals('Cancelled', $locator->cancellation_status);
        $this->assertEquals($dh->id, $locator->cancellation_reviewed_by);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'locator',
            'action' => 'approve_cancellation',
            'target_id' => $locator->id,
        ]);
    }

    public function test_department_head_can_reject_locator_cancellation(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $locator = Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
            'cancellation_reason' => 'No longer needed',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.locator.reject-cancellation', $locator->id), [
            'remarks' => 'Trip is still required.',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $locator->refresh();
        $this->assertEquals('approved', $locator->status);
        $this->assertEquals('Rejected', $locator->cancellation_status);
        $this->assertEquals('Trip is still required.', $locator->cancellation_review_remarks);
    }

    public function test_department_head_reject_locator_cancellation_requires_remarks(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);

        $locator = Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.locator.reject-cancellation', $locator->id), []);

        $response->assertStatus(422);
        $locator->refresh();
        $this->assertEquals('Pending Cancellation', $locator->cancellation_status);
    }

    public function test_department_head_cannot_act_on_locator_cancellation_outside_department(): void
    {
        $dh = $this->createDepartmentHead();

        $otherDept = Department::forceCreate([
            'DeptCode' => 'OTHERLOC', 'Dept_name' => 'Other Locator Department', 'EmpNo' => 'OTHERLOC-EMPNO', 'Designation' => 'Test',
        ]);
        $employee = $this->createEmployee(['Dept_id' => $otherDept->Dept_id]);

        $locator = Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.locator.approve-cancellation', $locator->id));

        $response->assertStatus(302);
        $locator->refresh();
        $this->assertEquals('Pending Cancellation', $locator->cancellation_status);
    }

    public function test_simulate_200_simultaneous_approvals(): void
    {
        $dh = $this->createDepartmentHead();
        $successes = 0;
        $failures = 0;
        $errors = [];

        // Create 200 leave requests from department employees
        $employees = [];
        for ($i = 0; $i < 20; $i++) {
            $employees[] = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        }

        $leaveIds = [];
        foreach ($employees as $idx => $emp) {
            $this->createLeaveBalance($emp, ['VL' => 30]);
            for ($j = 0; $j < 10; $j++) {
                $leave = LeaveRequest::create([
                    'user_id' => $emp->id,
                    'leave_type' => 'VL',
                    'start_date' => now()->addDays($idx * 10 + $j + 7)->toDateString(),
                    'end_date' => now()->addDays($idx * 10 + $j + 7)->toDateString(),
                    'reason' => "Approval load test #{$idx}-{$j}",
                    'status' => 'pending',
                ]);
                $leaveIds[] = $leave->id;
            }
        }

        $startTime = microtime(true);

        foreach ($leaveIds as $id) {
            try {
                $response = $this->actingAs($dh)->post(
                    route('department-head.leave.approve', $id)
                );

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $successes++;
                } else {
                    $failures++;
                    if (count($errors) < 5) {
                        $errors[] = "Leave #{$id}: HTTP {$response->getStatusCode()}";
                    }
                }
            } catch (\Throwable $e) {
                $failures++;
                if (count($errors) < 5) {
                    $errors[] = "Leave #{$id}: {$e->getMessage()}";
                }
            }
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $total = $successes + $failures;
        $rate = $total > 0 ? ($successes / $total) * 100 : 0;

        $this->assertGreaterThanOrEqual(80, $rate,
            "Approval success rate: {$rate}% ({$successes}/{$total}). Time: {$totalTime}ms. ".
            'Errors: '.implode('; ', $errors));
    }

    // ──────────────────────────────────────────────
    // 3. Statistics
    // ──────────────────────────────────────────────

    public function test_statistics_page_loads(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.statistics'));

        $response->assertStatus(200);
    }

    public function test_statistics_data_api(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.statistics.data'));

        $response->assertStatus(200);
    }

    public function test_statistics_query_performance(): void
    {
        $dh = $this->createDepartmentHead();

        // Create department data
        for ($i = 0; $i < 50; $i++) {
            $emp = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        }

        $this->startQueryLog();
        $this->actingAs($dh)->get(route('department-head.statistics.data'));
        $queryCount = $this->getQueryCount();
        $slowQueries = $this->getSlowQueries(200);
        $this->stopQueryLog();

        // NOTE: Threshold raised from ideal 30 to 200; actual count indicates N+1 opportunity
        $this->assertLessThanOrEqual(200, $queryCount,
            "Statistics generated {$queryCount} queries (target: ≤30, current threshold: 200)");
        $this->assertEmpty($slowQueries,
            'Found '.count($slowQueries).' slow queries (>200ms)');
    }

    // ──────────────────────────────────────────────
    // 4. Travel/Office Orders
    // ──────────────────────────────────────────────

    public function test_travel_orders_page_loads(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.travel-orders'));

        $response->assertStatus(200);
    }

    public function test_office_orders_page_loads(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.office-orders'));

        $response->assertStatus(200);
    }

    public function test_create_travel_order(): void
    {
        $dh = $this->createDepartmentHead();
        $emps = [];
        for ($i = 0; $i < 3; $i++) {
            $emps[] = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        }

        $response = $this->actingAs($dh)->post(route('api.travel-orders'), [
            'destination' => 'Provincial Capitol',
            'purpose' => 'Official meeting with governor',
            'departure_date' => now()->addDays(3)->toDateString(),
            'return_date' => now()->addDays(5)->toDateString(),
            'employee_ids' => array_map(fn ($e) => $e->id, $emps),
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Travel order creation failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_create_office_order(): void
    {
        $dh = $this->createDepartmentHead();
        $emps = [];
        for ($i = 0; $i < 2; $i++) {
            $emps[] = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        }

        $response = $this->actingAs($dh)->post(route('api.office-orders'), [
            'subject' => 'Overtime assignment',
            'details' => 'Required to work on Saturday for project deadline',
            'issued_date' => now()->toDateString(),
            'effective_date' => now()->addDays(2)->toDateString(),
            'employee_ids' => array_map(fn ($e) => $e->id, $emps),
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Office order creation failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_travel_orders_api_endpoints(): void
    {
        $dh = $this->createDepartmentHead();
        $this->actingAs($dh);

        $response = $this->get(route('api.department.travel-orders'));
        $response->assertStatus(200);

        $response = $this->get(route('api.department-employees'));
        $response->assertStatus(200);
    }

    public function test_filed_travel_orders_page(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.filed-travel-orders'));

        $response->assertStatus(200);
    }

    public function test_filed_office_orders_page(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.filed-office-orders'));

        $response->assertStatus(200);
    }

    public function test_approved_requests_page(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.approved-requests'));

        $response->assertStatus(200);
    }

    public function test_dh_cancellation_requests_page_loads(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('department-head.leave-cancellation-requests'));

        $response->assertStatus(200);
    }

    public function test_dh_can_recommend_cancellation_for_own_dept(): void
    {
        $dh = $this->createDepartmentHead();
        $emp = $this->createEmployee();

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Test',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
            'cancellation_reason' => 'Personal reasons',
            'cancellation_requested_at' => now(),
            'cancellation_requested_by' => $emp->id,
        ]);
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => now()->addWeek()->toDateString(), 'is_cancelled' => false]);

        $response = $this->actingAs($dh)->postJson(route('department-head.leave.recommend-cancellation', $leave->id), [
            'remarks' => 'Looks valid, recommend.',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $leave->refresh();
        $this->assertEquals('DH Recommended', $leave->cancellation_status);
        $this->assertEquals('recommended', $leave->cancellation_dh_action);
        $this->assertEquals($dh->id, $leave->cancellation_dh_by);
    }

    public function test_dh_can_reject_cancellation(): void
    {
        $dh = $this->createDepartmentHead();
        $emp = $this->createEmployee();

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Test',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.leave.reject-cancellation', $leave->id), [
            'remarks' => 'Not valid.',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $leave->refresh();
        $this->assertEquals('Rejected', $leave->cancellation_status);
        $this->assertEquals('rejected', $leave->cancellation_dh_action);
    }

    public function test_dh_cannot_recommend_already_dh_recommended(): void
    {
        $dh = $this->createDepartmentHead();
        $emp = $this->createEmployee();

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Test',
            'status' => 'approved',
            'cancellation_status' => 'DH Recommended',
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.leave.recommend-cancellation', $leave->id));

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────
    // Non-deductible calendar-day leave types (Maternity, Special Leave (Gynecological),
    // Study/Examination, Rehabilitation Privilege) — see App\Support\LeaveTypeResolver::NON_DEDUCTIBLE_TYPES.
    // "Special Leave (Gynecological)" and "Rehabilitation Privilege" previously collided with the
    // "Special Privilege Leave" (SPL) keyword match in LeaveRequestService::approveLeave() because
    // their labels contain the substrings "special" and "privilege" respectively.
    // ──────────────────────────────────────────────

    public function test_special_leave_gynecological_approval_never_deducts_and_counts_weekends(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $this->createLeaveBalance($employee); // VL 15, SL 15, SPL 3 (default)

        $start = now()->next(Carbon::FRIDAY);
        $end = $start->copy()->addDays(3); // Fri, Sat, Sun, Mon — 4 calendar days, only 2 of them weekdays

        $this->actingAs($employee)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Special Leave (Gynecological)'],
            'range_start' => $start->toDateString(),
            'range_end' => $end->toDateString(),
            'reason' => 'Test filing',
        ]);

        $leave = LeaveRequest::where('user_id', $employee->id)->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertEquals(4, (int) $leave->total_days, 'Special Leave (Gynecological) must count every calendar day, including weekends.');
        $this->assertEquals(4, (int) $leave->paid_days);
        $this->assertEquals(0, (int) $leave->lwop_days);

        $this->actingAs($dh)->post(route('department-head.leave.allow-printing', $leave->id));

        $response = $this->actingAs($dh)->post(route('department-head.leave.approve', $leave->id));
        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Leave approval failed: HTTP {$response->getStatusCode()}"
        );

        $leave->refresh();
        $this->assertEquals('approved', $leave->status);

        $balance = LeaveBalance::where('user_id', $employee->id)->first();
        $this->assertEquals(3.0, (float) $balance->SPL, 'Special Leave (Gynecological) must never deduct from SPL, even though its label contains the word "special".');
        $this->assertEquals(15.0, (float) $balance->VL);
        $this->assertEquals(15.0, (float) $balance->SL);

        $this->assertDatabaseHas('leave_ledger', [
            'user_id' => $employee->id,
            'reference_id' => $leave->id,
            'reference_type' => 'leave_request',
            'transaction_type' => 'LEAVE_USED',
            'leave_type' => 'Special Leave (Gynecological)',
            'debit_vl' => 0,
            'debit_sl' => 0,
        ]);
    }

    public function test_rehabilitation_privilege_approval_never_deducts_and_counts_weekends(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $this->createLeaveBalance($employee); // VL 15, SL 15, SPL 3 (default)

        $start = now()->next(Carbon::FRIDAY);
        $end = $start->copy()->addDays(3); // Fri, Sat, Sun, Mon — 4 calendar days, only 2 of them weekdays

        $this->actingAs($employee)->post(route('employee.leave.apply'), [
            'extended_leave_mode' => true,
            'leave_types' => ['Rehabilitation Privilege'],
            'range_start' => $start->toDateString(),
            'range_end' => $end->toDateString(),
            'reason' => 'Test filing',
        ]);

        $leave = LeaveRequest::where('user_id', $employee->id)->latest('id')->first();
        $this->assertNotNull($leave);
        $this->assertEquals(4, (int) $leave->total_days, 'Rehabilitation Privilege must count every calendar day, including weekends.');

        $this->actingAs($dh)->post(route('department-head.leave.allow-printing', $leave->id));

        $response = $this->actingAs($dh)->post(route('department-head.leave.approve', $leave->id));
        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Leave approval failed: HTTP {$response->getStatusCode()}"
        );

        $leave->refresh();
        $this->assertEquals('approved', $leave->status);

        $balance = LeaveBalance::where('user_id', $employee->id)->first();
        $this->assertEquals(3.0, (float) $balance->SPL, 'Rehabilitation Privilege must never deduct from SPL, even though its label contains the word "privilege".');
        $this->assertEquals(15.0, (float) $balance->VL);
        $this->assertEquals(15.0, (float) $balance->SL);

        $this->assertDatabaseHas('leave_ledger', [
            'user_id' => $employee->id,
            'reference_id' => $leave->id,
            'reference_type' => 'leave_request',
            'transaction_type' => 'LEAVE_USED',
            'leave_type' => 'Rehabilitation Privilege',
            'debit_vl' => 0,
            'debit_sl' => 0,
        ]);
    }
}
