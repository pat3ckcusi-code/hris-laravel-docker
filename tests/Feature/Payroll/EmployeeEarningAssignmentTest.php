<?php

namespace Tests\Feature\Payroll;

use App\Models\Earning;
use App\Models\EmployeeEarning;
use App\Support\HrisConstants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Bulk assignment of an Earning (Allowance) type to multiple employees at once.
 *
 * Covers: bulk create for matching employees, skip-and-report for employees
 * already assigned, all-already-assigned, empty selection, and the
 * Employee Type filter wiring on the show page.
 */
class EmployeeEarningAssignmentTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeEarning(array $overrides = []): Earning
    {
        return Earning::create(array_merge([
            'type' => 'PERA',
            'description' => 'Personnel Economic Relief Allowance',
            'recurring' => true,
        ], $overrides));
    }

    /**
     * employee_earnings.amount is encrypted at rest (App\Casts\EncryptedDecimal),
     * so assertDatabaseHas() can't match it directly against a plain value —
     * assert via the Eloquent model instead, which decrypts on read.
     */
    private function assertEarningAmount(int $employeeId, int $earningsId, float $expectedAmount): void
    {
        $row = EmployeeEarning::where('employee_id', $employeeId)->where('earnings_id', $earningsId)->first();

        $this->assertNotNull($row, "No employee_earnings row for employee {$employeeId} earning {$earningsId}");
        $this->assertEquals($expectedAmount, $row->amount);
    }

    public function test_description_over_255_characters_is_stored_without_truncation(): void
    {
        // Regression guard: earnings.description used to be VARCHAR(255)
        // while validation allowed max:500, so a value over 255 chars threw
        // SQLSTATE[22001] on save.
        $manager = $this->createPayrollManager();
        $longDescription = str_repeat('D', 350);

        $this->actingAs($manager)->post(route('payroll.earnings.store'), [
            'type' => 'Custom Allowance',
            'description' => $longDescription,
        ])->assertRedirect(route('payroll.earnings.index'));

        $this->assertDatabaseHas('earnings', [
            'type' => 'Custom Allowance',
            'description' => $longDescription,
        ]);
    }

    public function test_bulk_assign_creates_row_per_selected_employee(): void
    {
        $manager = $this->createPayrollManager();
        $earning = $this->makeEarning();
        $employees = [$this->createEmployee(), $this->createEmployee(), $this->createEmployee()];

        $response = $this->actingAs($manager)->post(
            route('payroll.earnings.assignments.store', $earning->id),
            [
                'employee_ids' => array_map(fn ($e) => $e->id, $employees),
                'amount_type' => 'fixed',
                'amount' => 2000,
                'recurring' => '1',
            ]
        );

        $response->assertRedirect(route('payroll.earnings.show', $earning->id));
        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Assigned to 3 employee(s).');
        });

        foreach ($employees as $employee) {
            $this->assertDatabaseHas('employee_earnings', [
                'employee_id' => $employee->id,
                'earnings_id' => $earning->id,
                'amount_type' => 'fixed',
                'recurring' => true,
            ]);
            $this->assertEarningAmount($employee->id, $earning->id, 2000);
        }
    }

    public function test_bulk_assign_skips_employees_already_assigned_and_reports_both_counts(): void
    {
        $manager = $this->createPayrollManager();
        $earning = $this->makeEarning();
        $alreadyAssigned = $this->createEmployee();
        $new = $this->createEmployee();

        EmployeeEarning::create([
            'employee_id' => $alreadyAssigned->id,
            'earnings_id' => $earning->id,
            'amount_type' => 'fixed',
            'amount' => 1000,
            'recurring' => true,
        ]);

        $response = $this->actingAs($manager)->post(
            route('payroll.earnings.assignments.store', $earning->id),
            [
                'employee_ids' => [$alreadyAssigned->id, $new->id],
                'amount_type' => 'fixed',
                'amount' => 2000,
                'recurring' => '1',
            ]
        );

        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Assigned to 1 employee(s).')
                && str_contains($message, '1 already had this earning and were skipped.');
        });

        // The new employee gets a row; the already-assigned one keeps its original amount (not overwritten).
        $this->assertEarningAmount($new->id, $earning->id, 2000);
        $this->assertEarningAmount($alreadyAssigned->id, $earning->id, 1000);
        $this->assertEquals(1, EmployeeEarning::where('employee_id', $alreadyAssigned->id)
            ->where('earnings_id', $earning->id)->count());
    }

    public function test_bulk_assign_with_all_already_assigned_creates_no_rows(): void
    {
        $manager = $this->createPayrollManager();
        $earning = $this->makeEarning();
        $employee = $this->createEmployee();

        EmployeeEarning::create([
            'employee_id' => $employee->id,
            'earnings_id' => $earning->id,
            'amount_type' => 'fixed',
            'amount' => 1000,
            'recurring' => true,
        ]);

        $response = $this->actingAs($manager)->post(
            route('payroll.earnings.assignments.store', $earning->id),
            [
                'employee_ids' => [$employee->id],
                'amount_type' => 'fixed',
                'amount' => 2000,
                'recurring' => '1',
            ]
        );

        $response->assertSessionHas('error');
        $this->assertEquals(1, EmployeeEarning::where('earnings_id', $earning->id)->count());
    }

    public function test_bulk_assign_requires_at_least_one_employee(): void
    {
        $manager = $this->createPayrollManager();
        $earning = $this->makeEarning();

        $response = $this->actingAs($manager)->post(
            route('payroll.earnings.assignments.store', $earning->id),
            [
                'employee_ids' => [],
                'amount_type' => 'fixed',
                'amount' => 2000,
            ]
        );

        $response->assertSessionHasErrors('employee_ids');
        $this->assertDatabaseCount('employee_earnings', 0);
    }

    public function test_show_page_passes_employee_types_and_type_data_attributes(): void
    {
        $manager = $this->createPayrollManager();
        $earning = $this->makeEarning();
        $this->createEmployee(['employee_type' => 'Casual', 'name' => 'Casual Test Employee']);

        $response = $this->actingAs($manager)->get(route('payroll.earnings.show', $earning->id));

        $response->assertStatus(200);
        foreach (HrisConstants::EMPLOYEE_TYPES as $type) {
            $response->assertSee($type);
        }
        $response->assertSee('data-type="Casual"', false);
    }
}
