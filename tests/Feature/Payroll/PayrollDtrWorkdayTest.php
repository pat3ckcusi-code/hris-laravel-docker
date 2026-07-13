<?php

namespace Tests\Feature\Payroll;

use App\Models\Dtr;
use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\Shift;
use App\Services\PayrollComputationService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A shift assignment's Work Days pattern (not just the hardcoded Mon-Fri
 * assumption) drives which dates count as workable/absent for payroll.
 */
class PayrollDtrWorkdayTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_mon_sat_shift_counts_saturday_absence_and_excludes_sunday(): void
    {
        $admin = $this->createPayrollManager();

        $shift = Shift::create([
            'name' => 'Mon-Sat',
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
        ]);
        $employee = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign(
            $employee, $shift->id, Carbon::parse('2026-01-01'), null, null, null, [1, 2, 3, 4, 5, 6]
        );

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        // 2026-04-01 (Wed) through 2026-04-03 (Fri): present.
        foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $date) {
            Dtr::create(['employee_id' => $employee->id, 'date' => $date, 'is_absent' => false]);
        }
        // 2026-04-04 (Sat) is a workday under the Mon-Sat pattern - left unpunched.
        // 2026-04-05 (Sun) has no DTR either, but must stay excluded entirely.

        $run = PayrollRun::create([
            'period' => '2026-04 workday-test',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-05',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame(3, $detail->days_worked);
        $this->assertSame(1, $detail->absent_days, 'Saturday must be flagged absent under the Mon-Sat pattern.');
    }
}
