<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\TravelOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Coverage for the Office Order / Travel Order Cancel actions
 * (OfficeOrderController::cancel(), TravelOrderController::cancel()).
 * Travel Order's cancel is additionally department-scoped (orderIsAccessible())
 * and status-gated to Pending/Approved only, unlike Office Order's cancel,
 * which has no department scoping and allows cancelling from any status
 * except already-Cancelled (Office Order has no approval workflow at all).
 */
class OfficeTravelOrderCancelTest extends TestCase
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

    private function createTravelOrderForEmployee(User $employee, string $status = 'Pending'): TravelOrder
    {
        $travelOrder = TravelOrder::create([
            'travel_order_num' => 'TO-TEST-'.$employee->id.'-'.uniqid(),
            'purpose' => 'Conference',
            'destination' => 'Cebu',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'recommender' => $employee->id,
            'created_by' => $employee->id,
            'status' => $status,
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
            'office_order_num' => 'OO-TEST-'.$employee->id.'-'.uniqid(),
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

    // --- Travel Order cancel -------------------------------------------------

    public function test_department_head_can_cancel_pending_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Trip no longer needed',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $travelOrder->refresh();
        $this->assertSame('Cancelled', $travelOrder->status);
        $this->assertSame('Trip no longer needed', $travelOrder->cancellation_reason);
        $this->assertSame($dh->id, $travelOrder->cancelled_by);
        $this->assertNotNull($travelOrder->cancelled_at);

        $audit = HRAuditTrail::where('action', 'travel_order_cancelled')
            ->where('target_id', $travelOrder->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('travel_order', $audit->module);
        $this->assertSame('travel_order', $audit->target_type);
        $this->assertSame($dh->id, $audit->actor_user_id);
        $this->assertSame('Trip no longer needed', $audit->details['reason']);
        $this->assertSame($travelOrder->travel_order_num, $audit->details['travel_order_num']);
    }

    public function test_department_head_can_cancel_approved_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Approved');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Called off after approval',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame('Cancelled', $travelOrder->fresh()->status);
    }

    public function test_administrative_officer_within_scope_can_cancel_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $ao = $this->createAdminOfficer(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($ao)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'AO cancelling',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame('Cancelled', $travelOrder->fresh()->status);
    }

    public function test_cancel_travel_order_requires_a_reason(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", []);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('Pending', $travelOrder->fresh()->status);
    }

    public function test_rejected_travel_order_cannot_be_cancelled(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Rejected');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Trying anyway',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame('Rejected', $travelOrder->fresh()->status);
    }

    public function test_already_cancelled_travel_order_cannot_be_cancelled_again(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'First cancellation',
        ])->assertStatus(200);

        $response = $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Second attempt',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $travelOrder->refresh();
        $this->assertSame('Cancelled', $travelOrder->status);
        $this->assertSame('First cancellation', $travelOrder->cancellation_reason);
    }

    public function test_department_head_outside_scope_cannot_cancel_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $otherDept = $this->makeDepartment('Other Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $dh = $this->createDepartmentHead(['Dept_id' => $otherDept->Dept_id]);

        $response = $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Should not be allowed',
        ]);

        $response->assertStatus(404)->assertJson(['success' => false]);
        $this->assertSame('Pending', $travelOrder->fresh()->status);
    }

    public function test_time_keeper_cannot_cancel_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $timeKeeper = $this->createTimeKeeper();

        $this->actingAs($timeKeeper)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Not allowed',
        ])->assertStatus(403);
    }

    public function test_cancel_nonexistent_travel_order_returns_404(): void
    {
        $dh = $this->createDepartmentHead();

        $this->actingAs($dh)->postJson('/api/travel-orders/999999/cancel', [
            'reason' => 'Does not matter',
        ])->assertStatus(404)->assertJson(['success' => false]);
    }

    public function test_show_endpoint_returns_cancellation_details_after_cancel(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Weather advisory',
        ])->assertStatus(200);

        $timeKeeper = $this->createTimeKeeper();
        $response = $this->actingAs($timeKeeper)->getJson("/api/travel-orders/{$travelOrder->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.status', 'Cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Weather advisory');

        $data = $response->json('data');
        $this->assertNotEmpty($data['cancelled_at']);
        $this->assertNotEmpty($data['cancelled_by_name']);
    }

    public function test_cancelled_travel_order_cannot_be_edited(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Cancelled before edit attempt',
        ])->assertStatus(200);

        $this->actingAs($dh)->get("/travel-orders/{$travelOrder->id}/edit")->assertStatus(403);
    }

    public function test_mayor_cannot_approve_or_reject_a_cancelled_travel_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $travelOrder = $this->createTravelOrderForEmployee($employee, 'Pending');
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $this->actingAs($dh)->postJson("/api/travel-orders/{$travelOrder->id}/cancel", [
            'reason' => 'Cancelled before mayor acts',
        ])->assertStatus(200);

        $mayor = $this->createMayor();

        $this->actingAs($mayor)->postJson("/mayor/travel-orders/{$travelOrder->id}/approve")
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame('Cancelled', $travelOrder->fresh()->status);

        $this->actingAs($mayor)->postJson("/mayor/travel-orders/{$travelOrder->id}/reject", [
            'rejection_note' => 'n/a',
        ])->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame('Cancelled', $travelOrder->fresh()->status);
    }

    // --- Office Order cancel ---------------------------------------------------

    public function test_department_head_can_cancel_office_order(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($dh)->postJson("/api/office-orders/{$officeOrderId}/cancel", [
            'reason' => 'No longer needed',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $order = DB::table('office_orders')->where('id', $officeOrderId)->first();
        $this->assertSame('Cancelled', $order->status);
        $this->assertSame('No longer needed', $order->cancellation_reason);
        $this->assertSame($dh->id, $order->cancelled_by);
        $this->assertNotNull($order->cancelled_at);

        $audit = HRAuditTrail::where('action', 'office_order_cancelled')
            ->where('target_id', $officeOrderId)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('office_order', $audit->module);
        $this->assertSame('No longer needed', $audit->details['reason']);
    }

    public function test_cancel_office_order_requires_a_reason(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($dh)->postJson("/api/office-orders/{$officeOrderId}/cancel", []);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_already_cancelled_office_order_cannot_be_cancelled_again(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $this->actingAs($dh)->postJson("/api/office-orders/{$officeOrderId}/cancel", [
            'reason' => 'First cancellation',
        ])->assertStatus(200);

        $response = $this->actingAs($dh)->postJson("/api/office-orders/{$officeOrderId}/cancel", [
            'reason' => 'Second attempt',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $order = DB::table('office_orders')->where('id', $officeOrderId)->first();
        $this->assertSame('Cancelled', $order->status);
        $this->assertSame('First cancellation', $order->cancellation_reason);
    }

    public function test_cancelled_office_order_cannot_be_edited(): void
    {
        $dept = $this->makeDepartment('Order Dept');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        $officeOrderId = $this->createOfficeOrderForEmployee($employee);
        $dh = $this->createDepartmentHead(['Dept_id' => $dept->Dept_id]);

        $this->actingAs($dh)->postJson("/api/office-orders/{$officeOrderId}/cancel", [
            'reason' => 'Cancelled before edit attempt',
        ])->assertStatus(200);

        $this->actingAs($dh)->get("/office-orders/{$officeOrderId}/edit")->assertStatus(403);
    }
}
