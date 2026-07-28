<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PayslipPdfTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function seedLockedRunWithDetail(): array
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();

        $plantilla = Plantilla::create([
            'title' => 'Clerk III',
            'salary_grade' => 6,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620.00]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $run->update(['status' => 'locked', 'locked_at' => now()]);

        return compact('admin', 'employee', 'run');
    }

    public function test_store_blocked_for_unlocked_run(): void
    {
        $admin = $this->createPayrollManager();
        $run = PayrollRun::create([
            'period' => '2026-04 unlocked',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'computed',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(route('payroll.payslips.store'), ['payroll_run_id' => $run->id]);

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('payslips', 0);
    }

    public function test_generate_payslips_snapshots_deduction_breakdown_from_payroll_detail(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedLockedRunWithDetail();

        $response = $this->actingAs($admin)->post(route('payroll.payslips.store'), ['payroll_run_id' => $run->id]);

        $response->assertRedirect(route('payroll.payslips.index'));

        $payslip = Payslip::where('employee_id', $employee->id)->where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertEquals(18620.00, $payslip->basic_salary);
        $this->assertNotNull($payslip->deduction_breakdown);
        $this->assertGreaterThan(0, $payslip->net_pay);
    }

    public function test_pdf_download_returns_pdf_response(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedLockedRunWithDetail();
        $this->actingAs($admin)->post(route('payroll.payslips.store'), ['payroll_run_id' => $run->id]);

        $payslip = Payslip::where('employee_id', $employee->id)->where('payroll_run_id', $run->id)->firstOrFail();

        $response = $this->actingAs($admin)->get(route('payroll.payslips.download', $payslip->id));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_employee_can_download_own_payslip_but_not_anothers(): void
    {
        ['employee' => $employee, 'run' => $run] = $this->seedLockedRunWithDetail();
        $otherEmployee = $this->createEmployee();

        $payslip = Payslip::create([
            'employee_id' => $employee->id,
            'payroll_run_id' => $run->id,
            'basic_salary' => 18620,
            'gross_pay' => 18620,
            'net_pay' => 15000,
            'deduction_breakdown' => [],
        ]);

        $response = $this->actingAs($employee)->get(route('dashboard.employee.payslips.download', $payslip->id));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));

        $response = $this->actingAs($otherEmployee)->get(route('dashboard.employee.payslips.download', $payslip->id));
        $response->assertNotFound();
    }
}
