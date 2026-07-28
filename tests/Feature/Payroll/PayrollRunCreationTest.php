<?php

namespace Tests\Feature\Payroll;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PayrollRunCreationTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_store_rejects_period_end_before_period_start(): void
    {
        $manager = $this->createPayrollManager();

        $response = $this->actingAs($manager)->post(route('payroll.runs.store'), [
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-15',
            'period_end' => '2026-04-01',
        ]);

        $response->assertSessionHasErrors('period_end');
        $this->assertDatabaseCount('payroll_runs', 0);
    }

    public function test_store_accepts_period_end_equal_to_period_start(): void
    {
        $manager = $this->createPayrollManager();

        $response = $this->actingAs($manager)->post(route('payroll.runs.store'), [
            'period' => 'April 1, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-01',
        ]);

        $response->assertSessionDoesntHaveErrors('period_end');
        $this->assertDatabaseCount('payroll_runs', 1);
    }

    public function test_create_run_form_wires_up_client_side_min_and_error_markup(): void
    {
        $manager = $this->createPayrollManager();

        $response = $this->actingAs($manager)->get(route('payroll.runs.index'));

        $response->assertOk();
        $response->assertSee('id="period_end"', false);
        $response->assertSee('id="period-end-error"', false);
        $response->assertSee('Period End cannot be before Period Start.');
    }
}
