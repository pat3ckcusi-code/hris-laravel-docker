<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Eta;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Locator;
use App\Models\OicAssignment;
use App\Models\TravelOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Workforce Calendar: the "who's away" planning calendar showing per-date
 * counts and employee lists across Leave, ETA, Locator, Travel Order, and
 * Office Order for one department/one month at a time.
 */
class WorkforceCalendarTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeDepartment(string $name): Department
    {
        return Department::create([
            'DeptCode' => strtoupper(str_replace(' ', '_', $name)),
            'Dept_name' => $name,
            'Designation' => $name,
        ]);
    }

    public function test_employee_cannot_access_workforce_calendar(): void
    {
        $this->actingAs($this->createEmployee())
            ->get(route('attendance.workforce-calendar.index'))
            ->assertStatus(403);
    }

    public function test_time_keeper_sees_all_departments_in_dropdown(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.workforce-calendar.index', ['department_id' => $deptA->Dept_id, 'month' => 6, 'year' => 2026]))
            ->assertStatus(200)
            ->assertSee('Dept A')
            ->assertSee('Dept B');
    }

    public function test_department_head_cannot_view_another_departments_data_via_tampered_param(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');

        $employeeA = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $employeeB = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        $this->fileApprovedLeave($employeeA->id, '2026-06-10', 'Vacation Leave');
        $this->fileApprovedLeave($employeeB->id, '2026-06-10', 'Vacation Leave');

        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);

        $response = $this->actingAs($dh)
            ->get(route('attendance.workforce-calendar.index', ['department_id' => $deptB->Dept_id, 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200)
            ->assertSee('Dept A')
            ->assertDontSee('Dept B')
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');
    }

    public function test_approved_leave_appears_on_the_correct_date(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'OnLeave']);

        $this->fileApprovedLeave($employee->id, '2026-06-10', 'Vacation Leave');

        // A cancelled leave date must not count as an absence.
        $cancelled = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'CancelledLeave']);
        $cancelledRequest = LeaveRequest::create([
            'user_id' => $cancelled->id,
            'leave_type' => 'Sick Leave',
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-11',
            'status' => 'approved',
        ]);
        LeaveDate::create([
            'leave_request_id' => $cancelledRequest->id,
            'leave_date' => '2026-06-11',
            'is_cancelled' => true,
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.workforce-calendar.index', ['department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200)
            ->assertSee('OnLeave')
            ->assertSee('Vacation Leave')
            ->assertDontSee('CancelledLeave');
    }

    public function test_eta_locator_travel_order_and_office_order_all_appear(): void
    {
        $dept = $this->makeDepartment('Dept A');

        $etaEmployee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'OnEta']);
        Eta::create([
            'user_id' => $etaEmployee->id,
            'departure_date' => '2026-06-15',
            'arrival_date' => '2026-06-15',
            'destination' => 'Manila',
            'purpose' => 'Meeting',
            'status' => 'approved',
        ]);

        $locatorEmployee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'OnLocator']);
        Locator::create([
            'user_id' => $locatorEmployee->id,
            'application_type' => 'official',
            'location' => 'City Hall',
            'travel_date' => '2026-06-16',
            'intended_departure_time' => '08:00:00',
            'intended_arrival_time' => '17:00:00',
            'detail' => 'Field Verification',
            'status' => 'approved',
        ]);

        $travelEmployee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'OnTravel']);
        $travelOrder = TravelOrder::create([
            'travel_order_num' => 'TO-2026-001',
            'purpose' => 'Conference',
            'destination' => 'Cebu',
            'start_date' => '2026-06-17',
            'end_date' => '2026-06-18',
            'recommender' => $travelEmployee->id,
            'created_by' => $travelEmployee->id,
            'status' => 'Approved',
        ]);
        DB::table('travel_order_employees')->insert([
            'travel_order_id' => $travelOrder->id,
            'emp_no' => $travelEmployee->EmpNo,
        ]);

        $officeEmployee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'OnOfficeOrder']);
        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => 'OO-2026-001',
            'subject' => 'Test Memo',
            'issued_date' => '2026-06-01',
            'effective_date' => '2026-06-20',
            'status' => 'Pending Recommendation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('office_order_employees')->insert([
            'office_order_id' => $officeOrderId,
            'emp_no' => $officeEmployee->EmpNo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.workforce-calendar.index', ['department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200)
            ->assertSee('OnEta')
            ->assertSee('Manila')
            ->assertSee('OnLocator')
            ->assertSee('Field Verification')
            ->assertSee('OnTravel')
            ->assertSee('TO-2026-001')
            ->assertSee('OnOfficeOrder')
            ->assertSee('OO-2026-001');
    }

    public function test_travel_order_and_office_order_entries_carry_order_id_for_click_through(): void
    {
        // Guards WorkforceCalendarService::addEntry()'s $extra payload: the
        // day-detail modal's Office/Travel Order rows are only clickable
        // because each entry carries the real order id (see
        // wfcOpenTravelOrder()/wfcOpenOfficeOrder() in the Blade view) - a
        // future refactor of addTravelOrders()/addOfficeOrders() that drops
        // 'order_id' from the entry would silently make those rows
        // non-clickable again with no other test catching it.
        $dept = $this->makeDepartment('Dept A');

        $travelEmployee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'OnTravel']);
        $travelOrder = TravelOrder::create([
            'travel_order_num' => 'TO-2026-002',
            'purpose' => 'Conference',
            'destination' => 'Cebu',
            'start_date' => '2026-06-17',
            'end_date' => '2026-06-18',
            'recommender' => $travelEmployee->id,
            'created_by' => $travelEmployee->id,
            'status' => 'Approved',
        ]);
        DB::table('travel_order_employees')->insert([
            'travel_order_id' => $travelOrder->id,
            'emp_no' => $travelEmployee->EmpNo,
        ]);

        $officeEmployee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'OnOfficeOrder']);
        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => 'OO-2026-002',
            'subject' => 'Test Memo',
            'issued_date' => '2026-06-01',
            'effective_date' => '2026-06-20',
            'status' => 'Pending Recommendation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('office_order_employees')->insert([
            'office_order_id' => $officeOrderId,
            'emp_no' => $officeEmployee->EmpNo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.workforce-calendar.index', ['department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200);

        preg_match_all("/data-employees='([^']*)'/", $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1], 'Expected at least one day cell with a data-employees attribute');

        $foundTravelOrderId = false;
        $foundOfficeOrderId = false;
        foreach ($matches[1] as $raw) {
            $entries = json_decode(html_entity_decode($raw), true);
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (($entry['type'] ?? null) === 'travel_order' && ($entry['order_id'] ?? null) === $travelOrder->id) {
                    $foundTravelOrderId = true;
                }
                if (($entry['type'] ?? null) === 'office_order' && ($entry['order_id'] ?? null) === $officeOrderId) {
                    $foundOfficeOrderId = true;
                }
            }
        }

        $this->assertTrue($foundTravelOrderId, 'Travel Order entry is missing its order_id');
        $this->assertTrue($foundOfficeOrderId, 'Office Order entry is missing its order_id');
    }

    public function test_oic_covering_employee_can_view_workforce_calendar_for_covered_department_only(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');

        $coveringEmployee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $inDeptA = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $inDeptB = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        $this->fileApprovedLeave($inDeptA->id, '2026-06-10', 'Vacation Leave');
        $this->fileApprovedLeave($inDeptB->id, '2026-06-10', 'Vacation Leave');

        OicAssignment::create([
            'user_id' => $coveringEmployee->id,
            'dept_id' => $deptA->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($coveringEmployee)
            ->get(route('attendance.workforce-calendar.index', ['department_id' => $deptA->Dept_id, 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200)
            ->assertSee('Dept A')
            ->assertDontSee('Dept B')
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');
    }

    private function fileApprovedLeave(int $userId, string $date, string $leaveType): LeaveRequest
    {
        $leaveRequest = LeaveRequest::create([
            'user_id' => $userId,
            'leave_type' => $leaveType,
            'start_date' => $date,
            'end_date' => $date,
            'status' => 'approved',
        ]);

        LeaveDate::create([
            'leave_request_id' => $leaveRequest->id,
            'leave_date' => $date,
            'is_cancelled' => false,
        ]);

        return $leaveRequest;
    }
}
