<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Services\IntegrationApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Admin diagnostic tool (added after investigating a real report that Kyla
 * Maureen Axalan's attendance logs were "missing"): lets Time Keeper/HR
 * Manager check the raw biometric feed directly for one employee/date,
 * bypassing the import pipeline, to tell "our app failed to match this"
 * apart from "the source system genuinely has nothing for this person that
 * day." Must always agree with PersonnelLogImportService's own matching
 * (it reuses that service's buildEmpNoLookupMaps()/resolveUserForPersonnelId()
 * directly rather than a separate reimplementation).
 */
class AttendanceImportCheckEmployeeTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function mockApi(array $bulkLogs): void
    {
        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');
        $api->method('fetchBulkLogs')->willReturnOnConsecutiveCalls(
            [$bulkLogs, 200],
            [[], 200],
        );

        $this->app->instance(IntegrationApiService::class, $api);
    }

    public function test_found_under_own_empno(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['EmpNo' => '300858', 'first_name' => 'KYLA MAUREEN', 'last_name' => 'AXALAN']);

        $this->mockApi([
            ['personnelid' => '0300858', 'logdate' => '2026-08-24', 'logtime' => '07:53', 'inout' => 'IN', 'personnelfirstname' => 'KYLA MAUREEN', 'personnellastname' => 'AXALAN'],
        ]);

        $response = $this->actingAs($timeKeeper)->postJson(route('hr-manager.attendance.import.check-employee'), [
            'user_id' => $employee->id,
            'date' => '2026-08-24',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'matched_by_id');
        $response->assertJsonCount(0, 'matched_by_name_different_id');
        $response->assertJsonPath('matched_by_id.0.personnelid', '0300858');
    }

    public function test_not_found_anywhere_is_reported_as_a_gap(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['EmpNo' => '2501612', 'first_name' => 'KYLA MAUREEN', 'last_name' => 'AXALAN']);

        $this->mockApi([
            ['personnelid' => '9999999', 'logdate' => '2026-08-25', 'logtime' => '08:00', 'inout' => 'IN', 'personnelfirstname' => 'SOMEONE', 'personnellastname' => 'ELSE'],
        ]);

        $response = $this->actingAs($timeKeeper)->postJson(route('hr-manager.attendance.import.check-employee'), [
            'user_id' => $employee->id,
            'date' => '2026-08-25',
        ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'matched_by_id');
        $response->assertJsonCount(0, 'matched_by_name_different_id');
        $response->assertJsonPath('total_records_that_day', 1);
    }

    public function test_name_match_under_different_id_is_flagged_as_a_mismatch(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['EmpNo' => '5001001', 'first_name' => 'JOHN', 'last_name' => 'DELACRUZ']);

        // A punch under a completely different id, but matching this employee's name.
        $this->mockApi([
            ['personnelid' => '7777777', 'logdate' => '2026-08-24', 'logtime' => '08:00', 'inout' => 'IN', 'personnelfirstname' => 'JOHN', 'personnellastname' => 'DELACRUZ'],
        ]);

        $response = $this->actingAs($timeKeeper)->postJson(route('hr-manager.attendance.import.check-employee'), [
            'user_id' => $employee->id,
            'date' => '2026-08-24',
        ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'matched_by_id');
        $response->assertJsonCount(1, 'matched_by_name_different_id');
        $response->assertJsonPath('matched_by_name_different_id.0.personnelid', '7777777');
    }

    public function test_reports_when_already_imported_in_our_database(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['EmpNo' => '300858']);

        AttendanceLog::create([
            'user_id' => $employee->id,
            'emp_no' => '300858',
            'logdate' => '2026-08-18',
            'logtime' => '07:53:00',
            'logtype' => 'SYSTEM',
            'in_out' => 'IN',
        ]);

        $this->mockApi([
            ['personnelid' => '300858', 'logdate' => '2026-08-18', 'logtime' => '07:53', 'inout' => 'IN'],
        ]);

        $response = $this->actingAs($timeKeeper)->postJson(route('hr-manager.attendance.import.check-employee'), [
            'user_id' => $employee->id,
            'date' => '2026-08-18',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'already_in_attendance_logs');
    }

    public function test_employee_with_no_empno_is_rejected_with_a_clear_message(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['EmpNo' => null]);

        $response = $this->actingAs($timeKeeper)->postJson(route('hr-manager.attendance.import.check-employee'), [
            'user_id' => $employee->id,
            'date' => '2026-08-24',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'This employee has no EmpNo set - the import can never match their punches.');
    }

    public function test_department_head_cannot_access_the_diagnostic_tool(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee(['EmpNo' => '300858']);

        $response = $this->actingAs($dh)->postJson(route('hr-manager.attendance.import.check-employee'), [
            'user_id' => $employee->id,
            'date' => '2026-08-24',
        ]);

        $response->assertForbidden();
    }
}
