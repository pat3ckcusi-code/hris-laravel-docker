<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Proactive detection tool for the class of bug fixed in ShiftPunchGrouper
 * (a raw punch stranded in dtrs.unmatched_logs instead of being resolved
 * into a slot): Time Keeper/HR Manager should be able to spot and self-serve
 * a fix from the Import Attendance Logs page, without a developer having to
 * manually trace it via tinker/SQL.
 */
class AttendanceImportUnmatchedPunchesTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_dtr_row_with_unmatched_logs_appears_in_the_list(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['last_name' => 'Stranded']);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => now()->subDays(2)->toDateString(),
            'status' => 'incomplete',
            'unmatched_logs' => ['23:58:00'],
            'is_absent' => false,
        ]);

        $response = $this->actingAs($timeKeeper)->get(route('hr-manager.attendance.import'));

        $response->assertOk();
        $response->assertSee('Stranded');
    }

    /**
     * A genuinely legitimate missing_out (employee forgot to punch out) must
     * NOT be flagged here - it isn't evidence of a grouping bug, and
     * including status-only rows would bury real problems in noise.
     */
    public function test_dtr_row_with_empty_unmatched_logs_does_not_appear(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['last_name' => 'ForgotToPunch']);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => now()->subDays(2)->toDateString(),
            'status' => 'missing_out',
            'unmatched_logs' => null,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($timeKeeper)->get(route('hr-manager.attendance.import'));

        $response->assertOk();
        $response->assertDontSee('ForgotToPunch');
    }

    public function test_recompute_clears_a_resolved_case(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee();
        $date = '2026-08-10';

        // Simulates a stale/corrupted persisted row - the stored state
        // disagrees with what a fresh computation of the CURRENT
        // attendance_logs actually produces.
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => 'incomplete',
            'unmatched_logs' => ['07:55:00'],
            'is_absent' => false,
        ]);

        // A full, cleanly-matching punch set for the default 08:00/11:00/
        // 13:00/17:00 Standard Day schedule - resolves with zero unmatched
        // punches.
        foreach (['08:00:00', '11:00:00', '13:00:00', '17:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $employee->id,
                'emp_no' => $employee->EmpNo,
                'logdate' => $date,
                'logtime' => $time,
            ]);
        }

        $response = $this->actingAs($timeKeeper)->post(route('hr-manager.attendance.import.recompute-unmatched'), [
            'employee_id' => $employee->id,
            'date' => $date,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'attendance',
            'action' => 'dtr_unmatched_recomputed',
            'actor_user_id' => $timeKeeper->id,
        ]);

        $fresh = Dtr::where('employee_id', $employee->id)->whereDate('date', $date)->first();
        $this->assertTrue(empty($fresh->unmatched_logs));
    }

    public function test_department_head_cannot_access_recompute(): void
    {
        $dh = $this->createDepartmentHead();
        $employee = $this->createEmployee();

        $response = $this->actingAs($dh)->post(route('hr-manager.attendance.import.recompute-unmatched'), [
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
        ]);

        $response->assertForbidden();
    }
}
