<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\HabitualViolationNotice;
use App\Notifications\HrisTransactionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Issuing a CSC habitual-violation notice (Habitual Tardiness / Frequent
 * Undertime) from the Time Logs Monitoring screen: a continuous lifetime
 * 1st -> 2nd -> 3rd -> wraps-to-1st offense cycle per (employee, violation
 * type), at most one notice per employee/type/year, Time Keeper/HR Manager
 * only. See TimeLogsMonitoringController::issueNotice() and
 * App\Models\HabitualViolationNotice.
 */
class HabitualViolationNoticeTest extends TestCase
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

    private function seedLateDays(int $employeeId, string $yearMonth, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Dtr::create([
                'employee_id' => $employeeId,
                'date' => sprintf('%s-%02d', $yearMonth, $i),
                'late_minutes' => 10,
                'undertime_minutes' => 0,
                'is_absent' => false,
            ]);
        }
    }

    private function seedUndertimeDays(int $employeeId, string $yearMonth, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Dtr::create([
                'employee_id' => $employeeId,
                'date' => sprintf('%s-%02d', $yearMonth, $i),
                'late_minutes' => 0,
                'undertime_minutes' => 15,
                'is_absent' => false,
            ]);
        }
    }

    private function flagHabitualTardy(int $employeeId, int $year): void
    {
        $this->seedLateDays($employeeId, sprintf('%d-01', $year), 10);
        $this->seedLateDays($employeeId, sprintf('%d-02', $year), 10);
    }

    private function flagFrequentUndertime(int $employeeId, int $year): void
    {
        // Different months than flagHabitualTardy() so a test flagging both
        // for the same employee/year doesn't collide with dtrs' unique
        // (employee_id, date) constraint.
        $this->seedUndertimeDays($employeeId, sprintf('%d-03', $year), 10);
        $this->seedUndertimeDays($employeeId, sprintf('%d-04', $year), 10);
    }

    public function test_first_notice_issuance_succeeds(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'FirstNotice']);
        $this->flagHabitualTardy($employee->id, 2026);

        $response = $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.time-logs-monitoring.issue-notice'), [
                'employee_id' => $employee->id,
                'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
                'year' => 2026,
            ]);

        $response->assertRedirect();
        $this->assertNotNull(session('success'));

        $this->assertDatabaseHas('habitual_violation_notices', [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
            'offense_number' => 1,
        ]);
    }

    public function test_duplicate_notice_for_same_employee_type_year_is_rejected(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'Duplicate']);
        $this->flagHabitualTardy($employee->id, 2026);

        $tk = $this->createTimeKeeper();
        $payload = [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
        ];

        $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), $payload);
        $second = $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), $payload);

        $second->assertRedirect();
        $this->assertNotNull(session('error'));

        $this->assertSame(1, HabitualViolationNotice::where('employee_id', $employee->id)
            ->where('violation_type', HabitualViolationNotice::VIOLATION_TARDY)
            ->where('year', 2026)
            ->count());
    }

    public function test_tardiness_and_undertime_notice_counters_are_independent(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'BothFlags']);
        $this->flagHabitualTardy($employee->id, 2026);
        $this->flagFrequentUndertime($employee->id, 2026);

        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
        ]);
        $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_UNDERTIME,
            'year' => 2026,
        ]);

        $this->assertDatabaseHas('habitual_violation_notices', [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'offense_number' => 1,
        ]);
        $this->assertDatabaseHas('habitual_violation_notices', [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_UNDERTIME,
            'offense_number' => 1,
        ]);
    }

    public function test_offense_number_is_lifetime_not_reset_by_calendar_year(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'SecondOffense']);

        HabitualViolationNotice::create([
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2024,
            'offense_number' => 1,
        ]);

        $this->flagHabitualTardy($employee->id, 2025);

        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.time-logs-monitoring.issue-notice'), [
                'employee_id' => $employee->id,
                'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
                'year' => 2025,
            ]);

        $this->assertDatabaseHas('habitual_violation_notices', [
            'employee_id' => $employee->id,
            'year' => 2025,
            'offense_number' => 2,
        ]);
    }

    public function test_offense_number_wraps_to_first_after_third(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'WrapAround']);

        foreach ([2022 => 1, 2023 => 2, 2024 => 3] as $year => $offense) {
            HabitualViolationNotice::create([
                'employee_id' => $employee->id,
                'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
                'year' => $year,
                'offense_number' => $offense,
            ]);
        }

        $this->flagHabitualTardy($employee->id, 2026);

        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.time-logs-monitoring.issue-notice'), [
                'employee_id' => $employee->id,
                'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
                'year' => 2026,
            ]);

        $this->assertDatabaseHas('habitual_violation_notices', [
            'employee_id' => $employee->id,
            'year' => 2026,
            'offense_number' => 1,
        ]);
    }

    public function test_new_years_flag_can_still_get_a_notice_after_a_prior_year_already_has_one(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'CrossYear']);
        $this->flagHabitualTardy($employee->id, 2025);
        $this->flagHabitualTardy($employee->id, 2026);

        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2025,
        ]);
        $second = $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
        ]);

        $this->assertNotNull(session('success'));
        $second->assertRedirect();

        $this->assertSame(2, HabitualViolationNotice::where('employee_id', $employee->id)->count());
    }

    public function test_role_gating_for_issue_notice(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'RoleGate']);
        $payload = [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
        ];

        $this->actingAs($this->createEmployee())
            ->post(route('attendance.time-logs-monitoring.issue-notice'), $payload)
            ->assertStatus(403);

        $this->actingAs($this->createDepartmentHead(['Dept_id' => $dept->Dept_id]))
            ->post(route('attendance.time-logs-monitoring.issue-notice'), $payload)
            ->assertStatus(403);

        $this->actingAs($this->createAdminOfficer(['Dept_id' => $dept->Dept_id]))
            ->post(route('attendance.time-logs-monitoring.issue-notice'), $payload)
            ->assertStatus(403);

        $this->flagHabitualTardy($employee->id, 2026);

        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.time-logs-monitoring.issue-notice'), $payload)
            ->assertRedirect();

        $this->assertSame(1, HabitualViolationNotice::where('employee_id', $employee->id)->count());
    }

    public function test_server_side_rederivation_rejects_a_not_actually_flagged_employee(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'NotFlagged']);
        // Only one violation month - does not meet the habitual pattern.
        $this->seedLateDays($employee->id, '2026-01', 10);

        $response = $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.time-logs-monitoring.issue-notice'), [
                'employee_id' => $employee->id,
                'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
                'year' => 2026,
            ]);

        $response->assertRedirect();
        $this->assertNotNull(session('error'));
        $this->assertSame(0, HabitualViolationNotice::where('employee_id', $employee->id)->count());
    }

    public function test_view_renders_issue_button_then_static_offense_indicator(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'ViewFlow']);
        $this->flagHabitualTardy($employee->id, 2026);

        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026]))
            ->assertSee('Issue Notice (Tardiness)');

        $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
        ]);

        $this->actingAs($tk)
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026]))
            ->assertSee('1st Offense - Reprimand')
            ->assertDontSee('Issue Notice (Tardiness)');
    }

    public function test_audit_trail_row_is_written_on_notice_issuance(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'AuditTrail']);
        $this->flagHabitualTardy($employee->id, 2026);
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->post(route('attendance.time-logs-monitoring.issue-notice'), [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
        ]);

        $notice = HabitualViolationNotice::where('employee_id', $employee->id)->firstOrFail();

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $tk->id,
            'module' => 'disciplinary_notice',
            'action' => 'notice_issued',
            'target_type' => 'habitual_violation_notice',
            'target_id' => $notice->id,
        ]);

        $row = DB::table('hr_audit_trails')->where('target_id', $notice->id)->first();
        $details = json_decode($row->details, true);
        $this->assertSame('Reprimand', $details['sanction']);
        $this->assertSame(HabitualViolationNotice::LEGAL_BASIS[HabitualViolationNotice::VIOLATION_TARDY], $details['legal_basis']);
    }

    public function test_notification_is_sent_to_employee_on_notice_issuance(): void
    {
        Notification::fake();

        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'Notified']);
        $this->flagHabitualTardy($employee->id, 2026);

        $this->actingAs($this->createTimeKeeper())->post(route('attendance.time-logs-monitoring.issue-notice'), [
            'employee_id' => $employee->id,
            'violation_type' => HabitualViolationNotice::VIOLATION_TARDY,
            'year' => 2026,
        ]);

        Notification::assertSentTo($employee->fresh(), HrisTransactionNotification::class);
    }

    public function test_legal_basis_differs_between_tardy_and_undertime(): void
    {
        $this->assertNotSame(
            HabitualViolationNotice::LEGAL_BASIS[HabitualViolationNotice::VIOLATION_TARDY],
            HabitualViolationNotice::LEGAL_BASIS[HabitualViolationNotice::VIOLATION_UNDERTIME]
        );
    }
}
