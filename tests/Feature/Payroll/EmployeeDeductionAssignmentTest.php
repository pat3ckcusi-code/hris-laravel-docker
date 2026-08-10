<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\EmployeeDeduction;
use App\Support\HrisConstants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Bulk assignment of an "other" recurring Deduction (e.g. Cellphone, LIFE)
 * to multiple employees at once — mirrors EmployeeEarningAssignmentTest.php.
 */
class EmployeeDeductionAssignmentTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeOtherDeduction(array $overrides = []): Deduction
    {
        return Deduction::create(array_merge([
            'type' => 'Cellphone',
            'deduction_category' => 'other',
        ], $overrides));
    }

    /**
     * employee_deductions.amount is encrypted at rest (App\Casts\EncryptedDecimal),
     * so assertDatabaseHas() can't match it directly against a plain value —
     * assert via the Eloquent model instead, which decrypts on read.
     */
    private function assertDeductionAmount(int $employeeId, int $deductionId, float $expectedAmount, ?bool $recurring = null): void
    {
        $row = EmployeeDeduction::where('employee_id', $employeeId)->where('deduction_id', $deductionId)->first();

        $this->assertNotNull($row, "No employee_deductions row for employee {$employeeId} deduction {$deductionId}");
        $this->assertEquals($expectedAmount, $row->amount);

        if ($recurring !== null) {
            $this->assertSame($recurring, $row->recurring);
        }
    }

    public function test_bulk_assign_creates_row_per_selected_employee(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();
        $employees = [$this->createEmployee(), $this->createEmployee(), $this->createEmployee()];

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.store', $deduction->id),
            [
                'employee_ids' => array_map(fn ($e) => $e->id, $employees),
                'amount' => 100,
                'recurring' => '1',
            ]
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Assigned to 3 employee(s).');
        });

        foreach ($employees as $employee) {
            $this->assertDeductionAmount($employee->id, $deduction->id, 100, true);
        }
    }

    public function test_bulk_assign_skips_employees_already_assigned_and_reports_both_counts(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();
        $alreadyAssigned = $this->createEmployee();
        $new = $this->createEmployee();

        EmployeeDeduction::create([
            'employee_id' => $alreadyAssigned->id,
            'deduction_id' => $deduction->id,
            'amount' => 50,
            'recurring' => true,
        ]);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.store', $deduction->id),
            [
                'employee_ids' => [$alreadyAssigned->id, $new->id],
                'amount' => 100,
                'recurring' => '1',
            ]
        );

        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Assigned to 1 employee(s).')
                && str_contains($message, '1 already had this deduction and were skipped.');
        });

        $this->assertDeductionAmount($new->id, $deduction->id, 100);
        $this->assertDeductionAmount($alreadyAssigned->id, $deduction->id, 50);
        $this->assertEquals(1, EmployeeDeduction::where('employee_id', $alreadyAssigned->id)->where('deduction_id', $deduction->id)->count());
    }

    public function test_bulk_assign_with_all_already_assigned_creates_no_rows(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();
        $employee = $this->createEmployee();

        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'amount' => 50,
            'recurring' => true,
        ]);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.store', $deduction->id),
            ['employee_ids' => [$employee->id], 'amount' => 100, 'recurring' => '1']
        );

        $response->assertSessionHas('error');
        $this->assertEquals(1, EmployeeDeduction::where('deduction_id', $deduction->id)->count());
    }

    public function test_bulk_assign_requires_at_least_one_employee(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.store', $deduction->id),
            ['employee_ids' => [], 'amount' => 100]
        );

        $response->assertSessionHasErrors('employee_ids');
        $this->assertDatabaseCount('employee_deductions', 0);
    }

    public function test_bulk_assign_by_type_creates_a_row_for_every_active_employee_of_the_selected_types(): void
    {
        // Overridden away from the trait's default 'Permanent' employee_type
        // so the acting manager isn't itself swept into the "Permanent" bulk-assign.
        $manager = $this->createPayrollManager(['employee_type' => 'Elected Officials']);
        $deduction = $this->makeOtherDeduction();
        $permanentOne = $this->createEmployee(['employee_type' => 'Permanent']);
        $permanentTwo = $this->createEmployee(['employee_type' => 'Permanent']);
        $casual = $this->createEmployee(['employee_type' => 'Casual']);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => ['Permanent'], 'amount' => 150, 'recurring' => '1']
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Assigned to 2 employee(s)');
        });

        $this->assertDeductionAmount($permanentOne->id, $deduction->id, 150);
        $this->assertDeductionAmount($permanentTwo->id, $deduction->id, 150);
        $this->assertDatabaseMissing('employee_deductions', ['employee_id' => $casual->id, 'deduction_id' => $deduction->id]);
    }

    public function test_bulk_assign_by_type_skips_employees_already_assigned(): void
    {
        $manager = $this->createPayrollManager(['employee_type' => 'Elected Officials']);
        $deduction = $this->makeOtherDeduction();
        $alreadyAssigned = $this->createEmployee(['employee_type' => 'Permanent']);
        $new = $this->createEmployee(['employee_type' => 'Permanent']);

        EmployeeDeduction::create([
            'employee_id' => $alreadyAssigned->id,
            'deduction_id' => $deduction->id,
            'amount' => 50,
            'recurring' => true,
        ]);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => ['Permanent'], 'amount' => 150, 'recurring' => '1']
        );

        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Assigned to 1 employee(s)')
                && str_contains($message, '1 already had this deduction and were skipped.');
        });

        $this->assertDeductionAmount($new->id, $deduction->id, 150);
        $this->assertDeductionAmount($alreadyAssigned->id, $deduction->id, 50);
    }

    public function test_bulk_assign_by_type_covers_multiple_selected_types(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();
        $permanent = $this->createEmployee(['employee_type' => 'Permanent']);
        $casual = $this->createEmployee(['employee_type' => 'Casual']);
        $jobOrder = $this->createEmployee(['employee_type' => 'Job Orders']);

        $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => ['Permanent', 'Casual'], 'amount' => 75, 'recurring' => '0']
        );

        $this->assertDeductionAmount($permanent->id, $deduction->id, 75, false);
        $this->assertDeductionAmount($casual->id, $deduction->id, 75);
        $this->assertDatabaseMissing('employee_deductions', ['employee_id' => $jobOrder->id, 'deduction_id' => $deduction->id]);
    }

    public function test_bulk_assign_by_type_requires_at_least_one_type(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => [], 'amount' => 100]
        );

        $response->assertSessionHasErrors('employee_types');
        $this->assertDatabaseCount('employee_deductions', 0);
    }

    public function test_bulk_assign_by_type_rejects_an_invalid_employee_type(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => ['NotARealType'], 'amount' => 100]
        );

        $response->assertSessionHasErrors('employee_types.0');
        $this->assertDatabaseCount('employee_deductions', 0);
    }

    public function test_bulk_assign_by_type_rejected_for_a_loan_category_deduction(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan']);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => ['Permanent'], 'amount' => 100]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('employee_deductions', 0);
    }

    public function test_bulk_assign_by_type_rejected_for_an_inactive_deduction(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction(['is_active' => false]);
        $this->createEmployee(['employee_type' => 'Permanent']);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => ['Permanent'], 'amount' => 100]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('employee_deductions', 0);
    }

    public function test_show_page_passes_employee_types_and_type_data_attributes(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();
        $this->createEmployee(['employee_type' => 'Casual', 'name' => 'Casual Test Employee']);

        $response = $this->actingAs($manager)->get(route('payroll.contributions.show', $deduction->id));

        $response->assertStatus(200);
        foreach (HrisConstants::EMPLOYEE_TYPES as $type) {
            $response->assertSee($type);
        }
        $response->assertSee('data-type="Casual"', false);
    }
}
