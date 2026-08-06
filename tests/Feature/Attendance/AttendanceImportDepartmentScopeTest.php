<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Services\DtrPunchResolver;
use App\Services\IntegrationApiService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftPunchGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A department-scoped "Queue Import" run must not silently drop punches
 * belonging to employees outside the chosen department. The biometric API
 * call itself always returns the whole company's punches for the date
 * range regardless of the department filter - scoping the candidate-match
 * lookup by Dept_id used to mean any out-of-department employee's punch
 * was discarded on the spot (never persisted) and misreported as "no
 * matching HRIS EmpNo," even when their EmpNo was perfectly valid.
 */
class AttendanceImportDepartmentScopeTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeService(array $bulkLogs): PersonnelLogImportService
    {
        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');
        $api->method('fetchBulkLogs')->willReturnOnConsecutiveCalls(
            [$bulkLogs, 200],
            [[], 200],
        );

        return new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);
    }

    public function test_scoped_import_still_persists_punches_for_other_departments(): void
    {
        $deptA = Department::forceCreate(['DeptCode' => 'DEPT-A', 'Dept_name' => 'Department A', 'Designation' => 'Test']);
        $deptB = Department::forceCreate(['DeptCode' => 'DEPT-B', 'Dept_name' => 'Department B', 'Designation' => 'Test']);

        $userA = $this->createEmployee(['EmpNo' => '5001001', 'Dept_id' => $deptA->Dept_id]);
        $userB = $this->createEmployee(['EmpNo' => '5002002', 'Dept_id' => $deptB->Dept_id]);

        $bulkLogs = [
            ['personnelid' => '5001001', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'],
            ['personnelid' => '5002002', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'],
        ];

        $service = $this->makeService($bulkLogs);

        // Run scoped to Department A only.
        $result = $service->importForDateRange('2026-08-01', '2026-08-01', $deptA->Dept_id);

        $this->assertNull($result['error']);

        // Both punches must be persisted - Department B's employee is not
        // in scope for this run's DTR recompute, but their raw punch data
        // must never be discarded just because the run was scoped to A.
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $userA->id, 'logdate' => '2026-08-01']);
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $userB->id, 'logdate' => '2026-08-01']);

        // Department B's employee has a valid EmpNo and must not be
        // reported as unmatched, even though the run was scoped to A.
        $unmatchedMessages = array_filter(
            $result['messages'],
            fn (string $m) => str_contains($m, '5002002') || str_contains($m, 'no matching HRIS EmpNo')
        );
        $this->assertEmpty($unmatchedMessages, 'Out-of-department employee with a valid EmpNo must not be reported as unmatched.');

        // Only the in-scope (Department A) employee's DTR update is reported.
        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, '5001001')),
            'Expected the in-scope employee\'s DTR update to be reported.'
        );
        $this->assertFalse(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'Updated DTR for EmpNo 5002002')),
            'Out-of-scope employee\'s DTR update should not be reported for a department-scoped run.'
        );
    }

    public function test_genuinely_unmatched_personnelid_is_still_reported(): void
    {
        $deptA = Department::forceCreate(['DeptCode' => 'DEPT-A2', 'Dept_name' => 'Department A2', 'Designation' => 'Test']);
        $this->createEmployee(['EmpNo' => '5001001', 'Dept_id' => $deptA->Dept_id]);

        $bulkLogs = [
            ['personnelid' => '9999999', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN', 'personnelfirstname' => 'Nobody', 'personnellastname' => 'Registered'],
        ];

        $service = $this->makeService($bulkLogs);

        $result = $service->importForDateRange('2026-08-01', '2026-08-01', $deptA->Dept_id);

        $this->assertDatabaseMissing('attendance_logs', ['emp_no' => '9999999']);
        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'personnelid=9999999')),
            'A personnelid with no matching EmpNo anywhere in HRIS should still be reported as unmatched.'
        );
    }
}
