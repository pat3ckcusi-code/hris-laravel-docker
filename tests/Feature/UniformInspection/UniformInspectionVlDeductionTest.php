<?php

namespace Tests\Feature\UniformInspection;

use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveLedger;
use App\Models\UniformInspection;
use App\Models\UniformInspectionDeduction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Uniform Inspection violations deduct 1 VL day per employee per inspection
 * (not per violation row), skip silently on insufficient balance, and
 * refund on edit-removal/delete. See UniformInspectionDeductionService.
 */
class UniformInspectionVlDeductionTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function storePayload(array $details, array $overrides = []): array
    {
        return array_merge([
            'inspection_date' => '2026-07-17',
            'inspection_time' => '08:00',
            'remarks' => 'Flag ceremony inspection',
            'details' => $details,
        ], $overrides);
    }

    private function detail(User $employee, string $violationType = 'No Uniform', ?string $remarks = null): array
    {
        return [
            'employee_id' => $employee->id,
            'violation_type' => $violationType,
            'remarks' => $remarks,
        ];
    }

    public function test_single_employee_deducted_on_create(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);

        $response = $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee),
            ]));

        $inspection = UniformInspection::first();
        $response->assertRedirect(route('leave-manager.uniform-inspections.show', $inspection));

        $this->assertEquals(14.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);

        $deduction = UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
            ->where('employee_id', $employee->id)
            ->first();
        $this->assertNotNull($deduction);
        $this->assertSame(UniformInspectionDeduction::STATUS_DEDUCTED, $deduction->status);
        $this->assertEquals(1.0, $deduction->deducted_days);

        $this->assertTrue(
            LeaveLedger::where('user_id', $employee->id)
                ->where('transaction_type', 'UNIFORM_INSPECTION_DEDUCTION')
                ->where('leave_type', 'VL')
                ->where('debit_vl', 1.0)
                ->exists()
        );

        $this->assertTrue(
            HRAuditTrail::where('module', 'uniform_inspection')
                ->where('action', 'uniform_inspection_vl_deducted')
                ->where('target_id', $inspection->id)
                ->exists()
        );
    }

    public function test_duplicate_employee_within_one_submission_deducted_only_once(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);

        $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee, 'No Uniform'),
                $this->detail($employee, 'Untidy/Improper Wearing'),
            ]));

        $inspection = UniformInspection::first();

        $this->assertEquals(14.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);
        $this->assertSame(
            1,
            UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
                ->where('employee_id', $employee->id)
                ->count()
        );
        $this->assertSame(2, $inspection->details()->count());
    }

    public function test_insufficient_balance_skips_deduction_without_blocking_save(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 0.5]);

        $response = $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee),
            ]));

        $inspection = UniformInspection::first();
        $this->assertNotNull($inspection);
        $this->assertSame(1, $inspection->details()->count());

        $this->assertEquals(0.5, LeaveBalance::where('user_id', $employee->id)->first()->VL);

        $deduction = UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
            ->where('employee_id', $employee->id)
            ->first();
        $this->assertSame(UniformInspectionDeduction::STATUS_SKIPPED, $deduction->status);

        $this->assertFalse(
            LeaveLedger::where('user_id', $employee->id)
                ->where('transaction_type', 'UNIFORM_INSPECTION_DEDUCTION')
                ->exists()
        );

        $response->assertSessionHas('warning');
        $this->assertStringContainsString($employee->last_name, session('warning'));
    }

    public function test_employee_with_no_leave_balance_record_is_skipped_not_a_hard_error(): void
    {
        $employee = $this->createEmployee();
        LeaveBalance::where('user_id', $employee->id)->delete();

        $response = $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee),
            ]));

        $response->assertStatus(302);
        $inspection = UniformInspection::first();
        $this->assertSame(1, $inspection->details()->count());

        $deduction = UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
            ->where('employee_id', $employee->id)
            ->first();
        $this->assertSame(UniformInspectionDeduction::STATUS_SKIPPED, $deduction->status);
    }

    public function test_edit_removing_only_violation_row_refunds_vl(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);

        $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee),
            ]));

        $inspection = UniformInspection::first();
        $this->assertEquals(14.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);

        $otherEmployee = $this->createEmployee();
        $this->createLeaveBalance($otherEmployee, ['VL' => 15]);

        $this->actingAs($this->createLeaveManager())
            ->put(route('leave-manager.uniform-inspections.update', $inspection), $this->storePayload([
                $this->detail($otherEmployee),
            ]));

        $this->assertEquals(15.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);

        $deduction = UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
            ->where('employee_id', $employee->id)
            ->first();
        $this->assertSame(UniformInspectionDeduction::STATUS_REFUNDED, $deduction->status);

        $this->assertTrue(
            LeaveLedger::where('user_id', $employee->id)
                ->where('transaction_type', 'UNIFORM_INSPECTION_REFUND')
                ->where('credit_vl', 1.0)
                ->exists()
        );
    }

    public function test_edit_re_adding_employee_after_removal_re_deducts(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);

        $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee),
            ]));

        $inspection = UniformInspection::first();

        $otherEmployee = $this->createEmployee();
        $this->createLeaveBalance($otherEmployee, ['VL' => 15]);

        // Remove employee from the inspection (refund).
        $this->actingAs($this->createLeaveManager())
            ->put(route('leave-manager.uniform-inspections.update', $inspection), $this->storePayload([
                $this->detail($otherEmployee),
            ]));
        $this->assertEquals(15.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);

        // Re-add employee (re-deduct).
        $this->actingAs($this->createLeaveManager())
            ->put(route('leave-manager.uniform-inspections.update', $inspection), $this->storePayload([
                $this->detail($otherEmployee),
                $this->detail($employee),
            ]));

        $this->assertEquals(14.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);

        $this->assertSame(
            1,
            UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
                ->where('employee_id', $employee->id)
                ->count()
        );
        $deduction = UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
            ->where('employee_id', $employee->id)
            ->first();
        $this->assertSame(UniformInspectionDeduction::STATUS_DEDUCTED, $deduction->status);
    }

    public function test_delete_inspection_refunds_all_deducted_employees(): void
    {
        $employeeA = $this->createEmployee();
        $this->createLeaveBalance($employeeA, ['VL' => 15]);
        $employeeB = $this->createEmployee();
        $this->createLeaveBalance($employeeB, ['VL' => 15]);

        $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employeeA),
                $this->detail($employeeB),
            ]));

        $inspection = UniformInspection::first();
        $this->assertEquals(14.0, LeaveBalance::where('user_id', $employeeA->id)->first()->VL);
        $this->assertEquals(14.0, LeaveBalance::where('user_id', $employeeB->id)->first()->VL);

        $this->actingAs($this->createLeaveManager())
            ->delete(route('leave-manager.uniform-inspections.destroy', $inspection));

        $this->assertEquals(15.0, LeaveBalance::where('user_id', $employeeA->id)->first()->VL);
        $this->assertEquals(15.0, LeaveBalance::where('user_id', $employeeB->id)->first()->VL);

        $this->assertSame(
            2,
            LeaveLedger::where('transaction_type', 'UNIFORM_INSPECTION_REFUND')
                ->whereIn('user_id', [$employeeA->id, $employeeB->id])
                ->count()
        );
        $this->assertSame(
            2,
            HRAuditTrail::where('action', 'uniform_inspection_vl_refunded')->count()
        );
        $this->assertSame(0, UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)->count());
    }

    public function test_delete_inspection_with_skipped_employee_produces_no_ledger_or_balance_change(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 0.5]);

        $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee),
            ]));

        $inspection = UniformInspection::first();

        $this->actingAs($this->createLeaveManager())
            ->delete(route('leave-manager.uniform-inspections.destroy', $inspection));

        $this->assertEquals(0.5, LeaveBalance::where('user_id', $employee->id)->first()->VL);
        $this->assertFalse(
            LeaveLedger::where('user_id', $employee->id)
                ->where('transaction_type', 'UNIFORM_INSPECTION_REFUND')
                ->exists()
        );
    }

    public function test_mixed_batch_deducted_skipped_and_duplicate_in_one_submission(): void
    {
        $deducted = $this->createEmployee();
        $this->createLeaveBalance($deducted, ['VL' => 15]);

        $skipped = $this->createEmployee();
        $this->createLeaveBalance($skipped, ['VL' => 0.2]);

        $duplicate = $this->createEmployee();
        $this->createLeaveBalance($duplicate, ['VL' => 15]);

        $response = $this->actingAs($this->createLeaveManager())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($deducted),
                $this->detail($skipped),
                $this->detail($duplicate, 'No Uniform'),
                $this->detail($duplicate, 'Wrong Uniform'),
            ]));

        $this->assertEquals(14.0, LeaveBalance::where('user_id', $deducted->id)->first()->VL);
        $this->assertEquals(0.2, LeaveBalance::where('user_id', $skipped->id)->first()->VL);
        $this->assertEquals(14.0, LeaveBalance::where('user_id', $duplicate->id)->first()->VL);

        $warning = session('warning');
        $this->assertStringContainsString($skipped->last_name, $warning);
        $this->assertStringNotContainsString($deducted->last_name, $warning);
    }

    public function test_non_leave_manager_cannot_create_inspection(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->post(route('leave-manager.uniform-inspections.store'), $this->storePayload([
                $this->detail($employee),
            ]));

        $response->assertStatus(403);
    }
}
