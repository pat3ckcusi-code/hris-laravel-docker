<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Dtr;
use App\Services\AttendanceMonitoringExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Time Keeper / HR Manager company-wide Time Logs Monitoring: which
 * department has the most lateness/undertime, and which employees cross the
 * CSC MC No. 04, s. 1991 "Habitual Tardiness" threshold (mirrored for
 * undertime as "Frequent Undertime").
 */
class TimeLogsMonitoringTest extends TestCase
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

    public function test_time_keeper_can_view_time_logs_monitoring(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'late_minutes' => 15,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['month' => 6, 'year' => 2026]))
            ->assertStatus(200)
            ->assertSee('Dept A');
    }

    public function test_employee_and_department_head_cannot_access_time_logs_monitoring(): void
    {
        $deptA = $this->makeDepartment('Dept A');

        $this->actingAs($this->createEmployee())
            ->get(route('attendance.time-logs-monitoring'))
            ->assertStatus(403);

        $this->actingAs($this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]))
            ->get(route('attendance.time-logs-monitoring'))
            ->assertStatus(403);

        $this->actingAs($this->createAdminOfficer(['Dept_id' => $deptA->Dept_id]))
            ->get(route('attendance.time-logs-monitoring'))
            ->assertStatus(403);
    }

    public function test_time_keeper_can_browse_monitoring_matrix_across_departments(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'first_name' => 'Alice', 'last_name' => 'InDeptA']);
        $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'first_name' => 'Bob', 'last_name' => 'InDeptB']);

        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)
            ->get(route('attendance.monitoring-matrix', ['department_id' => $deptA->Dept_id, 'month' => 6, 'year' => 2026]))
            ->assertStatus(200)
            ->assertSee('Dept A')
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');

        $this->actingAs($tk)
            ->get(route('attendance.monitoring-matrix', ['department_id' => $deptB->Dept_id, 'month' => 6, 'year' => 2026]))
            ->assertStatus(200)
            ->assertSee('Dept B')
            ->assertSee('InDeptB')
            ->assertDontSee('InDeptA');
    }

    public function test_hr_manager_can_view_monitoring_matrix_but_employee_cannot(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($this->createHRManager())
            ->get(route('attendance.monitoring-matrix', ['department_id' => $deptA->Dept_id]))
            ->assertStatus(200);

        $this->actingAs($this->createEmployee())
            ->get(route('attendance.monitoring-matrix'))
            ->assertStatus(403);
    }

    public function test_department_ranking_puts_worst_department_first(): void
    {
        $deptHeavy = $this->makeDepartment('Heavy Dept');
        $deptLight = $this->makeDepartment('Light Dept');
        $heavyEmployee = $this->createEmployee(['Dept_id' => $deptHeavy->Dept_id]);
        $lightEmployee = $this->createEmployee(['Dept_id' => $deptLight->Dept_id]);

        foreach (['2026-06-01', '2026-06-02'] as $date) {
            Dtr::create(['employee_id' => $heavyEmployee->id, 'date' => $date, 'late_minutes' => 15, 'undertime_minutes' => 0, 'is_absent' => false]);
        }
        Dtr::create(['employee_id' => $lightEmployee->id, 'date' => '2026-06-01', 'late_minutes' => 5, 'undertime_minutes' => 0, 'is_absent' => false]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['month' => 6, 'year' => 2026]));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Heavy Dept') < strpos($content, 'Light Dept'));
    }

    public function test_department_with_no_dtr_data_this_month_still_appears_in_ranking(): void
    {
        $deptWithData = $this->makeDepartment('Dept With Data');
        $deptQuiet = $this->makeDepartment('Dept Quiet');
        $employee = $this->createEmployee(['Dept_id' => $deptWithData->Dept_id]);
        $this->createEmployee(['Dept_id' => $deptQuiet->Dept_id]);

        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'late_minutes' => 10, 'undertime_minutes' => 0, 'is_absent' => false]);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['month' => 6, 'year' => 2026]))
            ->assertStatus(200)
            ->assertSee('Dept With Data')
            ->assertSee('Dept Quiet');
    }

    public function test_department_employee_count_reflects_headcount_not_just_dtr_attendees(): void
    {
        $deptOther = $this->makeDepartment('Other Dept');
        $deptA = $this->makeDepartment('Dept A');
        $withDtr = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $tk = $this->createTimeKeeper(['Dept_id' => $deptOther->Dept_id]);

        Dtr::create(['employee_id' => $withDtr->id, 'date' => '2026-06-01', 'late_minutes' => 10, 'undertime_minutes' => 0, 'is_absent' => false]);

        $response = $this->actingAs($tk)
            ->get(route('attendance.time-logs-monitoring', ['dept_id' => $deptA->Dept_id, 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200);

        // 3 employees in Dept A, though only 1 has a DTR row this month.
        preg_match('/Dept A<\/span>\s*<\/td>\s*<td[^>]*>(\d+)<\/td>/', $response->getContent(), $matches);
        $this->assertSame('3', $matches[1] ?? null);
    }

    public function test_employee_late_ten_times_in_two_consecutive_months_is_flagged_habitual(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'Habitual']);

        $this->seedLateDays($employee->id, '2026-03', 10);
        $this->seedLateDays($employee->id, '2026-04', 10);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026]));

        $response->assertStatus(200)
            ->assertSee('Habitual')
            ->assertSee('Habitual Tardiness');
    }

    public function test_employee_late_ten_times_in_non_consecutive_different_semester_months_is_not_flagged(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'NotHabitual']);

        // March (semester 1) and August (semester 2) - not consecutive, not same semester.
        $this->seedLateDays($employee->id, '2026-03', 10);
        $this->seedLateDays($employee->id, '2026-08', 10);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026]));

        $response->assertStatus(200)->assertDontSee('Habitual Tardiness');
    }

    public function test_employee_late_ten_times_in_two_months_same_semester_is_flagged_habitual(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'SameSemester']);

        // January and April - same first semester, not consecutive.
        $this->seedLateDays($employee->id, '2026-01', 10);
        $this->seedLateDays($employee->id, '2026-04', 10);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026]))
            ->assertSee('Habitual Tardiness');
    }

    public function test_tardiness_cell_carries_employee_breakdown_for_the_month(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $late = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'LateOften']);
        $onTime = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'NeverLate']);

        Dtr::create(['employee_id' => $late->id, 'date' => '2026-06-01', 'late_minutes' => 10, 'undertime_minutes' => 0, 'is_absent' => false]);
        Dtr::create(['employee_id' => $late->id, 'date' => '2026-06-02', 'late_minutes' => 5, 'undertime_minutes' => 0, 'is_absent' => false]);
        Dtr::create(['employee_id' => $onTime->id, 'date' => '2026-06-01', 'late_minutes' => 0, 'undertime_minutes' => 0, 'is_absent' => false]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['month' => 6, 'year' => 2026]));

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('breakdown-cell', $content);
        $this->assertStringContainsString('LateOften', $content);
        $this->assertStringContainsString('"days":2', str_replace(' ', '', $content));
        $this->assertStringNotContainsString('NeverLate', $content);
    }

    public function test_undertime_cell_carries_employee_breakdown_for_the_month(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $short = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'UndertimeOften']);
        $fullDay = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'FullDayEveryTime']);

        Dtr::create(['employee_id' => $short->id, 'date' => '2026-06-01', 'late_minutes' => 0, 'undertime_minutes' => 30, 'is_absent' => false]);
        Dtr::create(['employee_id' => $short->id, 'date' => '2026-06-02', 'late_minutes' => 0, 'undertime_minutes' => 15, 'is_absent' => false]);
        Dtr::create(['employee_id' => $fullDay->id, 'date' => '2026-06-01', 'late_minutes' => 0, 'undertime_minutes' => 0, 'is_absent' => false]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['month' => 6, 'year' => 2026]));

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('data-label="Undertime"', $content);
        $this->assertStringContainsString('UndertimeOften', $content);
        $this->assertStringContainsString('"days":2', str_replace(' ', '', $content));
        $this->assertStringNotContainsString('FullDayEveryTime', $content);
    }

    public function test_employee_type_filter_scopes_habitual_violations(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $permanent = $this->createEmployee([
            'Dept_id' => $deptA->Dept_id, 'last_name' => 'PermanentHabitual', 'employee_type' => 'permanent',
        ]);
        $casual = $this->createEmployee([
            'Dept_id' => $deptA->Dept_id, 'last_name' => 'CasualHabitual', 'employee_type' => 'casual',
        ]);

        $this->seedLateDays($permanent->id, '2026-03', 10);
        $this->seedLateDays($permanent->id, '2026-04', 10);
        $this->seedLateDays($casual->id, '2026-03', 10);
        $this->seedLateDays($casual->id, '2026-04', 10);

        $tk = $this->createTimeKeeper();

        // Unfiltered: both flagged.
        $this->actingAs($tk)
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026]))
            ->assertSee('PermanentHabitual')
            ->assertSee('CasualHabitual');

        // Filtered to permanent only: casual employee no longer appears.
        $response = $this->actingAs($tk)
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026, 'employee_type' => 'permanent']));

        $response->assertSee('PermanentHabitual');
        $response->assertDontSee('CasualHabitual');
    }

    public function test_only_one_violation_month_is_not_flagged(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'OnlyOnce']);

        $this->seedLateDays($employee->id, '2026-05', 10);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.time-logs-monitoring', ['year' => 2026]))
            ->assertDontSee('Habitual Tardiness');
    }

    public function test_department_search_filters_the_ranking_table(): void
    {
        $deptOther = $this->makeDepartment('Other Dept');
        $deptA = $this->makeDepartment('Finance Office');
        $deptB = $this->makeDepartment('Human Resources');
        $tk = $this->createTimeKeeper(['Dept_id' => $deptOther->Dept_id]);

        $response = $this->actingAs($tk)
            ->get(route('attendance.time-logs-monitoring', ['dept_search' => 'Finance', 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200)->assertSee('Finance Office');

        // "Human Resources" still appears once, in the Department filter <select>,
        // but must not appear a second time as a row in the (now-filtered) ranking table.
        $this->assertSame(1, substr_count($response->getContent(), 'Human Resources'));
    }

    public function test_violation_search_filters_the_habitual_violations_table(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $first = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'Alpha']);
        $second = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'Beta']);

        $this->seedLateDays($first->id, '2026-01', 10);
        $this->seedLateDays($first->id, '2026-02', 10);
        $this->seedLateDays($second->id, '2026-01', 10);
        $this->seedLateDays($second->id, '2026-02', 10);

        $tk = $this->createTimeKeeper();

        $response = $this->actingAs($tk)
            ->get(route('attendance.time-logs-monitoring', ['violation_search' => 'Alpha', 'year' => 2026]));

        $response->assertStatus(200)
            ->assertSee('Alpha')
            ->assertDontSee('Beta');
    }

    public function test_habitual_violations_are_paginated(): void
    {
        $deptA = $this->makeDepartment('Dept A');

        for ($i = 1; $i <= 16; $i++) {
            $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => "Employee{$i}"]);
            $this->seedLateDays($employee->id, '2026-01', 10);
            $this->seedLateDays($employee->id, '2026-02', 10);
        }

        $tk = $this->createTimeKeeper();

        $page1 = $this->actingAs($tk)->get(route('attendance.time-logs-monitoring', ['year' => 2026]));
        $page1->assertStatus(200)->assertSee('Employee1');

        $page2 = $this->actingAs($tk)->get(route('attendance.time-logs-monitoring', ['year' => 2026, 'violation_page' => 2]));
        $page2->assertStatus(200);

        // 16 employees, 15 per page - page 2 must have exactly 1 remaining and differ from page 1's content.
        $this->assertNotEquals($page1->getContent(), $page2->getContent());
    }

    public function test_office_order_covered_day_does_not_count_toward_tardiness_or_undertime(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'OnOfficeOrder']);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-05',
            'late_minutes' => 25,
            'undertime_minutes' => 54,
            'is_absent' => false,
        ]);

        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => 'OO-2026-001',
            'subject' => 'Official business',
            'issued_date' => '2026-06-05',
            'effective_date' => '2026-06-05',
            'status' => 'Pending Recommendation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('office_order_employees')->insert([
            'office_order_id' => $officeOrderId,
            'emp_no' => $employee->EmpNo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = app(AttendanceMonitoringExportService::class)->getRows(collect([$deptA]), 6, 2026);
        $row = $rows->firstWhere('user_id', $employee->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['tardiness_count']);
        $this->assertSame(0, $row['undertime_count']);
        $this->assertSame(0, $row['tardiness_minutes']);
        $this->assertSame(0, $row['undertime_minutes']);
        $this->assertSame(0, $row['total_minutes']);
        $this->assertStringContainsString('Office Order', $row['remarks']);
        $this->assertStringNotContainsString('Tardy', $row['remarks']);
        $this->assertStringNotContainsString('Undertime', $row['remarks']);
    }

    public function test_tardiness_and_undertime_still_count_without_office_order_coverage(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'NoOfficeOrder']);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-05',
            'late_minutes' => 25,
            'undertime_minutes' => 54,
            'is_absent' => false,
        ]);

        $rows = app(AttendanceMonitoringExportService::class)->getRows(collect([$deptA]), 6, 2026);
        $row = $rows->firstWhere('user_id', $employee->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['tardiness_count']);
        $this->assertSame(1, $row['undertime_count']);
        $this->assertSame(25, $row['tardiness_minutes']);
        $this->assertSame(54, $row['undertime_minutes']);
        $this->assertSame(79, $row['total_minutes']);
        $this->assertStringContainsString('Tardy (25 mins)', $row['remarks']);
        $this->assertStringContainsString('Undertime (54 mins)', $row['remarks']);
    }
}
