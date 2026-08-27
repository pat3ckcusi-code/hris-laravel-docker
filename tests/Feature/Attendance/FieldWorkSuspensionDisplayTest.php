<?php

namespace Tests\Feature\Attendance;

use App\Models\EmployeeShiftSchedule;
use App\Models\WorkSuspension;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A field_work/wfh EmployeeShiftSchedule override with zero punches used to
 * render as generic "Absent" whenever ANY WorkSuspension existed for the
 * date, full-day or half-day alike - the field-work render loop deferred to
 * a catch-all pass that only has a label for a full-day suspension. See
 * DtrController::data()'s field-work/wfh row-push loop.
 */
class FieldWorkSuspensionDisplayTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-08-18';

    private function dtrData(int $employeeId): array
    {
        $tk = $this->createTimeKeeper();

        $response = $this->actingAs($tk)->getJson(route('attendance.dtr.data', [
            'employee_id' => $employeeId,
            'dtr_type' => 'monthly',
            'month' => '2026-08',
        ]));

        $response->assertOk();

        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse(self::DATE)->format('M d, Y (D)'));

        $this->assertNotNull($row, 'Expected a row for '.self::DATE);

        return $row;
    }

    public function test_half_day_suspension_on_field_work_day_shows_field_work_with_suspension_note(): void
    {
        $employee = $this->createEmployee();

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '13:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        $row = $this->dtrData($employee->id);

        $this->assertStringContainsString('Field Work', $row['status_badge']);
        $this->assertStringContainsString('PM Suspended', $row['status_badge']);
        $this->assertSame('-', $row['time_in_am']);
        $this->assertSame('-', $row['time_out_pm']);
    }

    public function test_half_day_suspension_on_wfh_day_shows_work_from_home_with_suspension_note(): void
    {
        $employee = $this->createEmployee();

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'wfh',
        ]);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '13:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        $row = $this->dtrData($employee->id);

        $this->assertStringContainsString('Work From Home', $row['status_badge']);
        $this->assertStringContainsString('PM Suspended', $row['status_badge']);
    }

    public function test_full_day_suspension_on_field_work_day_still_shows_plain_suspension_badge(): void
    {
        $employee = $this->createEmployee();

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        $row = $this->dtrData($employee->id);

        $this->assertStringNotContainsString('Field Work', $row['status_badge']);
        $this->assertStringContainsString('Weather / Typhoon', $row['status_badge']);
        $this->assertSame('WEATHER / TYPHOON', $row['time_in_am']);
    }

    public function test_field_work_day_with_workend_capping_suspension_shows_no_suspension_note(): void
    {
        $employee = $this->createEmployee();

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        // 15:00 falls strictly between lunchReturn (13:00) and workEnd (17:00):
        // applySuspension() only caps workEnd, excludes zero slots.
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '15:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        $row = $this->dtrData($employee->id);

        $this->assertStringContainsString('Field Work', $row['status_badge']);
        $this->assertStringNotContainsString('PM Suspended', $row['status_badge']);
    }

    public function test_frontline_exempt_field_work_day_ignores_suspension_entirely(): void
    {
        $employee = $this->createEmployee(['is_frontline' => true]);

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '13:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        $row = $this->dtrData($employee->id);

        $this->assertStringContainsString('Field Work', $row['status_badge']);
        $this->assertStringNotContainsString('PM Suspended', $row['status_badge']);
    }

    public function test_half_day_suspended_field_work_date_renders_exactly_one_row(): void
    {
        $employee = $this->createEmployee();

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '13:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        $tk = $this->createTimeKeeper();
        $response = $this->actingAs($tk)->getJson(route('attendance.dtr.data', [
            'employee_id' => $employee->id,
            'dtr_type' => 'monthly',
            'month' => '2026-08',
        ]));

        $formattedDate = Carbon::parse(self::DATE)->format('M d, Y (D)');
        $matches = collect($response->json('data'))->where('date', $formattedDate);

        $this->assertCount(1, $matches);
    }

    public function test_is_full_day_true_only_when_suspension_time_is_null(): void
    {
        $this->assertTrue((new WorkSuspension(['suspension_time' => null]))->isFullDay());
        $this->assertFalse((new WorkSuspension(['suspension_time' => '13:00:00']))->isFullDay());
    }
}
