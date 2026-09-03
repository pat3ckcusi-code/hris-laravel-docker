<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TravelOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Access control for the read-only Office/Travel Order detail endpoints
 * (GET /api/office-orders/{id}, GET /api/travel-orders/{id}). These were split
 * out of the Department Head/Administrative Officer-only write group (see
 * routes/web.php) into their own group that also admits Time Keeper/HR
 * Manager, since the Workforce Calendar surfaces these two order types to
 * those two unrestricted, company-wide roles as well. Guards against two
 * classes of regression: (1) the whole write group (store/update/cancel)
 * accidentally getting opened up to Time Keeper/HR Manager instead of just
 * the two show() routes, and (2) TravelOrderController::show()'s new
 * unrestricted-role bypass accidentally loosening Department Head/
 * Administrative Officer's existing department-scoped access.
 */
class OfficeTravelOrderShowAccessTest extends TestCase
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

    private function createTravelOrderForEmployee(User $employee): TravelOrder
    {
        $travelOrder = TravelOrder::create([
            'travel_order_num' => 'TO-TEST-'.$employee->id,
            'purpose' => 'Conference',
            'destination' => 'Cebu',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'recommender' => $employee->id,
            'created_by' => $employee->id,
            'status' => 'Approved',
        ]);

        DB::table('travel_order_employees')->insert([
            'travel_order_id' => $travelOrder->id,
            'emp_no' => $employee->EmpNo,
        ]);

        return $travelOrder;
    }

    private function createOfficeOrderForEmployee(User $employee): int
    {
        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => 'OO-TEST-'.$employee->id,
            'subject' => 'Test Memo',
            'issued_date' => now()->toDateString(),
            'effective_date' => now()->addDays(2)->toDateString(),
            'status' => 'Pending Recommendation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('office_order_employees')->insert([
            'office_order_id' => $officeOrderId,
            'emp_no' => $employee->EmpNo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $officeOrderId;
    }

    public function test_time_keeper_can_view_travel_order_details_for_any_department(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee);

        // Time Keeper's own Dept_id is unrelated to the order's department -
        // proves this is a genuine company-wide bypass, not an accidental match.
        $unrelatedDept = $this->makeDepartment('Time Keeper Home Dept');
        $timeKeeper = $this->createTimeKeeper(['Dept_id' => $unrelatedDept->Dept_id]);

        $response = $this->actingAs($timeKeeper)->getJson("/api/travel-orders/{$travelOrder->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.travel_order_num', $travelOrder->travel_order_num)
            ->assertJsonPath('data.destination', 'Cebu');
    }

    public function test_time_keeper_can_view_office_order_details_for_any_department(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);

        $unrelatedDept = $this->makeDepartment('Time Keeper Home Dept');
        $timeKeeper = $this->createTimeKeeper(['Dept_id' => $unrelatedDept->Dept_id]);

        $response = $this->actingAs($timeKeeper)->getJson("/api/office-orders/{$officeOrderId}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.subject', 'Test Memo');
    }

    public function test_hr_manager_can_view_travel_order_and_office_order_details(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);

        $unrelatedDept = $this->makeDepartment('HR Manager Home Dept');
        $hrManager = $this->createHRManager(['Dept_id' => $unrelatedDept->Dept_id]);

        $this->actingAs($hrManager)->getJson("/api/travel-orders/{$travelOrder->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->actingAs($hrManager)->getJson("/api/office-orders/{$officeOrderId}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_time_keeper_gets_404_for_nonexistent_travel_order(): void
    {
        $timeKeeper = $this->createTimeKeeper();

        $this->actingAs($timeKeeper)->getJson('/api/travel-orders/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_department_head_within_scope_can_still_view_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee);

        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $this->actingAs($dh)->getJson("/api/travel-orders/{$travelOrder->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_department_head_outside_scope_cannot_view_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $otherDept = $this->makeDepartment('Other Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee);

        // Confirms the new Time Keeper/HR Manager bypass in
        // TravelOrderController::show() didn't accidentally loosen the
        // existing department-scoped check for Department Head/Administrative
        // Officer - a DH outside the order's department is still refused.
        $dh = $this->createDepartmentHead(['Dept_id' => $otherDept->Dept_id]);

        $this->actingAs($dh)->getJson("/api/travel-orders/{$travelOrder->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_unrelated_role_cannot_view_travel_order_or_office_order_details(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);

        foreach ([$this->createPayrollManager(), $this->createEmployee()] as $user) {
            $this->actingAs($user)->getJson("/api/travel-orders/{$travelOrder->id}")->assertStatus(403);
            $this->actingAs($user)->getJson("/api/office-orders/{$officeOrderId}")->assertStatus(403);
        }
    }

    public function test_time_keeper_still_cannot_write_travel_or_office_orders(): void
    {
        // Regression guard: only the two GET show() routes were meant to move
        // into the wider-access group - every write action must stay
        // Department Head/Administrative Officer only. HR Manager is
        // deliberately excluded from this check: EnsureRole unconditionally
        // treats an HR Manager as "acting as department head" whenever
        // 'department head' is among a route's allowed roles (see
        // app/Http/Middleware/EnsureRole.php), so HR Manager legitimately
        // passes this gate today - that's pre-existing, documented behavior
        // unrelated to this change, not something to guard against here.
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);

        $timeKeeper = $this->createTimeKeeper();

        $this->actingAs($timeKeeper)->postJson('/api/travel-orders', [])->assertStatus(403);
        $this->actingAs($timeKeeper)->putJson("/api/travel-orders/{$travelOrder->id}", [])->assertStatus(403);
        $this->actingAs($timeKeeper)->postJson('/api/office-orders', [])->assertStatus(403);
        $this->actingAs($timeKeeper)->putJson("/api/office-orders/{$officeOrderId}", [])->assertStatus(403);
        $this->actingAs($timeKeeper)->postJson("/api/office-orders/{$officeOrderId}/cancel", [])->assertStatus(403);
    }
}
