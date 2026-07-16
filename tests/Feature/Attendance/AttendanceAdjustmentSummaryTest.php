<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceAdjustmentSubmission;
use App\Models\AttendanceAdjustmentSubmissionItem;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\HRAuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Timekeeper-facing screen that reviews attendance deficiencies already
 * classified by AttendanceMonitoringExportService::getRows() and forwards a
 * filtered snapshot to the Leave Manager. These tests verify the additive
 * tardiness/undertime minute split, role gating, and the submission/dedup
 * workflow - not the underlying classification, which is already covered by
 * TimeLogsMonitoringTest.
 */
class AttendanceAdjustmentSummaryTest extends TestCase
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

    public function test_time_keeper_can_view_adjustment_summary_index(): void
    {
        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.adjustment-summary.index'))
            ->assertStatus(200);
    }

    public function test_hr_manager_can_view_adjustment_summary_index(): void
    {
        $this->actingAs($this->createHRManager())
            ->get(route('attendance.adjustment-summary.index'))
            ->assertStatus(200);
    }

    public function test_employee_cannot_view_adjustment_summary(): void
    {
        $this->actingAs($this->createEmployee())
            ->get(route('attendance.adjustment-summary.index'))
            ->assertStatus(403);
    }

    public function test_data_endpoint_returns_correct_tardiness_and_undertime_minutes_split(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'Splitcheck']);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'late_minutes' => 50,
            'undertime_minutes' => 20,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.adjustment-summary.data', [
                'department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026, 'draw' => 1, 'start' => 0, 'length' => 10,
            ]));

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('user_id', $employee->id);

        $this->assertNotNull($row);
        $this->assertSame(50, $row['tardiness_minutes']);
        $this->assertSame(20, $row['undertime_minutes']);
        $this->assertSame(70, $row['total_minutes']);
        $this->assertSame($employee->EmpNo, $row['emp_no']);
        $this->assertSame('Dept A', $row['department']);
    }

    public function test_min_count_filters_out_employees_below_threshold_for_selected_issue(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $lowTardy = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'LowTardy']);
        $highTardy = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'HighTardy']);

        // 3 late days (below threshold) vs 8 late days (above threshold).
        foreach (range(1, 3) as $d) {
            Dtr::create(['employee_id' => $lowTardy->id, 'date' => sprintf('2026-06-%02d', $d), 'late_minutes' => 10, 'undertime_minutes' => 0, 'is_absent' => false]);
        }
        foreach (range(1, 8) as $d) {
            Dtr::create(['employee_id' => $highTardy->id, 'date' => sprintf('2026-06-%02d', $d), 'late_minutes' => 10, 'undertime_minutes' => 0, 'is_absent' => false]);
        }

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.adjustment-summary.data', [
                'department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026, 'issue' => 'tardiness', 'min_count' => 7, 'draw' => 1, 'start' => 0, 'length' => 50,
            ]));

        $response->assertStatus(200);
        $userIds = collect($response->json('data'))->pluck('user_id');

        $this->assertTrue($userIds->contains($highTardy->id));
        $this->assertFalse($userIds->contains($lowTardy->id));
    }

    public function test_remarks_are_scoped_to_the_selected_issue(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'Mixed']);

        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'late_minutes' => 15, 'undertime_minutes' => 0, 'is_absent' => false]);
        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-06-02', 'late_minutes' => 0, 'undertime_minutes' => 20, 'is_absent' => false]);

        $tardyResponse = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.adjustment-summary.data', [
                'department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026, 'issue' => 'tardiness', 'min_count' => 0, 'draw' => 1, 'start' => 0, 'length' => 50,
            ]));
        $tardyRow = collect($tardyResponse->json('data'))->firstWhere('user_id', $employee->id);
        $this->assertStringContainsString('Tardy', $tardyRow['remarks']);
        $this->assertStringNotContainsString('Undertime', $tardyRow['remarks']);

        $undertimeResponse = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.adjustment-summary.data', [
                'department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026, 'issue' => 'undertime', 'min_count' => 0, 'draw' => 1, 'start' => 0, 'length' => 50,
            ]));
        $undertimeRow = collect($undertimeResponse->json('data'))->firstWhere('user_id', $employee->id);
        $this->assertStringContainsString('Undertime', $undertimeRow['remarks']);
        $this->assertStringNotContainsString('Tardy', $undertimeRow['remarks']);
    }

    public function test_omitted_issue_param_defaults_to_unfiled(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $highTardy = $this->createEmployee(['Dept_id' => $dept->Dept_id, 'last_name' => 'HighTardy', 'dtr_exempt' => true]);

        foreach (range(1, 8) as $d) {
            Dtr::create(['employee_id' => $highTardy->id, 'date' => sprintf('2026-06-%02d', $d), 'late_minutes' => 10, 'undertime_minutes' => 0, 'is_absent' => false]);
        }

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.adjustment-summary.data', [
                'department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026, 'min_count' => 7, 'draw' => 1, 'start' => 0, 'length' => 50,
            ]));

        $response->assertStatus(200);
        // High tardiness alone shouldn't clear the default 'unfiled' filter.
        $this->assertFalse(collect($response->json('data'))->pluck('user_id')->contains($highTardy->id));
    }

    public function test_submission_creates_header_and_items(): void
    {
        $deptOther = $this->makeDepartment('Other Dept');
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'late_minutes' => 15, 'undertime_minutes' => 0, 'is_absent' => false]);

        $response = $this->actingAs($this->createTimeKeeper(['Dept_id' => $deptOther->Dept_id]))
            ->postJson(route('attendance.adjustment-summary.submit'), [
                'department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026,
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, AttendanceAdjustmentSubmission::count());
        $this->assertSame(1, AttendanceAdjustmentSubmissionItem::count());

        $item = AttendanceAdjustmentSubmissionItem::first();
        $this->assertSame($employee->id, $item->user_id);
        $this->assertSame(15, $item->tardiness_minutes);
    }

    public function test_submission_skips_already_submitted_employee_same_month(): void
    {
        $deptOther = $this->makeDepartment('Other Dept');
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'late_minutes' => 15, 'undertime_minutes' => 0, 'is_absent' => false]);

        $tk = $this->createTimeKeeper(['Dept_id' => $deptOther->Dept_id]);
        $params = ['department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026];

        $first = $this->actingAs($tk)->postJson(route('attendance.adjustment-summary.submit'), $params);
        $first->assertStatus(200)->assertJson(['submitted_count' => 1, 'skipped_count' => 0]);

        $second = $this->actingAs($tk)->postJson(route('attendance.adjustment-summary.submit'), $params);
        $second->assertStatus(200)->assertJson(['submitted_count' => 0, 'skipped_count' => 1]);

        $this->assertSame(1, AttendanceAdjustmentSubmissionItem::where('user_id', $employee->id)->count());
    }

    public function test_submission_allows_resubmission_different_month(): void
    {
        $deptOther = $this->makeDepartment('Other Dept');
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'late_minutes' => 15, 'undertime_minutes' => 0, 'is_absent' => false]);
        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-07-01', 'late_minutes' => 10, 'undertime_minutes' => 0, 'is_absent' => false]);

        $tk = $this->createTimeKeeper(['Dept_id' => $deptOther->Dept_id]);

        $this->actingAs($tk)
            ->postJson(route('attendance.adjustment-summary.submit'), ['department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026])
            ->assertJson(['submitted_count' => 1, 'skipped_count' => 0]);

        $this->actingAs($tk)
            ->postJson(route('attendance.adjustment-summary.submit'), ['department_id' => $dept->Dept_id, 'month' => 7, 'year' => 2026])
            ->assertJson(['submitted_count' => 1, 'skipped_count' => 0]);

        $this->assertSame(2, AttendanceAdjustmentSubmissionItem::where('user_id', $employee->id)->count());
    }

    public function test_audit_trail_logged_on_submission(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $dept->Dept_id]);
        Dtr::create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'late_minutes' => 15, 'undertime_minutes' => 0, 'is_absent' => false]);

        $this->actingAs($this->createTimeKeeper())
            ->postJson(route('attendance.adjustment-summary.submit'), ['department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026]);

        $this->assertTrue(
            HRAuditTrail::where('module', 'attendance')->where('action', 'adjustment_summary_submitted')->exists()
        );
    }

    public function test_pdf_export_returns_pdf_response(): void
    {
        $dept = $this->makeDepartment('Dept A');
        $this->createEmployee(['Dept_id' => $dept->Dept_id]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.adjustment-summary.pdf', ['department_id' => $dept->Dept_id, 'month' => 6, 'year' => 2026]));

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }
}
