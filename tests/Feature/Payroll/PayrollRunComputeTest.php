<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PayrollRunComputeTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeRun(array $overrides = []): PayrollRun
    {
        return PayrollRun::create(array_merge([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
        ], $overrides));
    }

    public function test_compute_flashes_a_computed_summary_with_employee_count_and_period(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();

        $plantilla = Plantilla::create([
            'title' => 'Staff',
            'salary_grade' => 10,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);
        $employee = $this->createEmployee();
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
            'step' => 1,
        ]);
        SalaryMatrix::create(['sg' => 10, 'step' => 1, 'year' => 2026, 'amount' => 25000.00]);

        $response = $this->actingAs($manager)->post(route('payroll.runs.compute', $run->id));

        $response->assertRedirect();
        $response->assertSessionHas('computed_summary', function ($summary) use ($run) {
            return $summary['count'] === 1 && $summary['period'] === $run->period;
        });
        $response->assertSessionMissing('error');
        $this->assertEquals('computed', $run->fresh()->status);
    }

    public function test_compute_is_rejected_for_an_already_locked_run(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun(['status' => 'locked', 'locked_at' => now()]);

        $response = $this->actingAs($manager)->post(route('payroll.runs.compute', $run->id));

        $response->assertSessionHas('error', 'Cannot compute a locked payroll run.');
        $response->assertSessionMissing('computed_summary');
        $this->assertEquals('locked', $run->fresh()->status);
    }

    public function test_compute_requires_period_start_and_end(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun(['period_start' => null, 'period_end' => null]);

        $response = $this->actingAs($manager)->post(route('payroll.runs.compute', $run->id));

        $response->assertSessionHas('error', 'Period start and end dates are required for computation.');
        $response->assertSessionMissing('computed_summary');
    }
}
