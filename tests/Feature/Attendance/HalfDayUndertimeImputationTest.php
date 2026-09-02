<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\User;
use App\Services\AttendanceMonitoringExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Real reported case: Aug 03, 2026 (Mon) - AM In/AM Out both "Missing", PM In
 * 12:50, PM Out 17:00, Late=0, Undertime=0, Status="Half Day PM" - on the DTR
 * page, Form 48, and the Monitoring Matrix. AttendanceStatusResolver already
 * correctly identifies this shape as half_day_am/half_day_pm, but nothing
 * downstream ever charged minutes for it: LateCalculator/UndertimeCalculator
 * only score punches that exist (nothing to score when a whole half is
 * missing), and the one fallback that DOES charge minutes for a missing
 * punch - DtrPunchResolver::imputedLateMinutes()/imputedUndertimeMinutes() -
 * was gated to fire only when ONE boundary punch of a segment is missing
 * while its sibling proves the segment happened; with BOTH AM (or PM)
 * punches missing there was no sibling to prove anything, so it silently
 * stayed at 0. Separately, the Monitoring Matrix's own independent
 * absence-scan logic flagged this exact shape as "Unofficial Exit (Incomplete
 * Punches)", which mischaracterizes a genuine half day as an exit problem.
 *
 * Fixed by adding two new components to imputedUndertimeMinutes()'s
 * with-break branch (whole AM missing + PM fully punched, and the mirror)
 * and carving the matching shape out of AttendanceMonitoringExportService's
 * Unofficial Exit classification so it falls through to the (now non-zero)
 * phantom-undertime pass instead.
 */
class HalfDayUndertimeImputationTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-08-03'; // the real reported date, a Monday

    private function dtrPageRow(User $user, string $date): ?array
    {
        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => Carbon::parse($date)->format('Y-m'),
            ]));

        $response->assertOk();

        return collect($response->json('data'))
            ->firstWhere('date', Carbon::parse($date)->format('M d, Y (D)'));
    }

    public function test_dtr_page_charges_undertime_for_whole_missing_am_half_when_pm_is_fully_punched(): void
    {
        Carbon::setTestNow(self::DATE.' 18:00:00');

        $user = $this->createEmployee(['last_name' => 'HalfDayPmReported']);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => '12:50:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        Carbon::setTestNow();

        $this->assertNotNull($row);
        // Global default schedule: workStart 08:00 -> morningEnd 11:00 = 180 minutes.
        $this->assertSame(180, $row['undertime_minutes']);
        $this->assertTrue($row['is_undertime']);
        $this->assertTrue($row['is_am_out_undertime']);
        $this->assertFalse($row['is_pm_out_undertime']);
        $this->assertSame(0, $row['late_minutes'], 'A missing arrival is charged as Undertime for the whole absent half, never as Late.');
        $this->assertFalse($row['is_late']);
    }

    public function test_dtr_page_charges_undertime_for_whole_missing_pm_half_when_am_is_fully_punched(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');

        $user = $this->createEmployee(['last_name' => 'HalfDayAmReported']);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '07:58:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        Carbon::setTestNow();

        $this->assertNotNull($row);
        // Global default schedule: lunchReturn 13:00 -> workEnd 17:00 = 240 minutes.
        $this->assertSame(240, $row['undertime_minutes']);
        $this->assertTrue($row['is_undertime']);
        $this->assertTrue($row['is_pm_out_undertime']);
        $this->assertFalse($row['is_am_out_undertime']);
    }

    public function test_dtr_page_undertime_not_charged_when_missing_am_half_is_fully_excused(): void
    {
        Carbon::setTestNow(self::DATE.' 18:00:00');

        $user = $this->createEmployee(['last_name' => 'HalfDayExcused']);

        DtrExcuse::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'excuse_type' => 'power_interruption',
            'is_full_day' => false,
            'excuse_am_in' => true,
            'excuse_am_out' => true,
            'excuse_pm_in' => false,
            'excuse_pm_out' => false,
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => '12:50:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        Carbon::setTestNow();

        $this->assertNotNull($row);
        $this->assertSame(0, $row['undertime_minutes'], 'The whole AM half is explained by the DtrExcuse - it must not also be phantom-charged as undertime.');
        $this->assertFalse($row['is_undertime']);
        $this->assertFalse($row['is_am_out_undertime']);
    }

    public function test_monitoring_matrix_counts_half_day_gap_as_undertime_not_unofficial_exit(): void
    {
        $dept = Department::create([
            'DeptCode' => 'HALFDAYTEST',
            'Dept_name' => 'Half Day Undertime Test Dept',
            'Designation' => 'Test',
        ]);
        $user = $this->createEmployee(['last_name' => 'HalfDayMatrix', 'Dept_id' => $dept->Dept_id]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => '12:50:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $departments = Department::where('Dept_id', $dept->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(1, $row['undertime_count']);
        $this->assertSame(180, $row['undertime_minutes']);
        $this->assertSame(0, $row['unofficial_exit_count'], 'A genuine half day is not an unofficial exit.');
        $this->assertStringContainsString('Undertime', $row['remarks']);
        $this->assertStringNotContainsString('Unofficial Exit', $row['remarks']);
    }

    public function test_monitoring_matrix_scattered_incomplete_pattern_still_classified_as_unofficial_exit(): void
    {
        $dept = Department::create([
            'DeptCode' => 'SCATTEREDTEST',
            'Dept_name' => 'Scattered Incomplete Test Dept',
            'Designation' => 'Test',
        ]);
        $user = $this->createEmployee(['last_name' => 'ScatteredMatrix', 'Dept_id' => $dept->Dept_id]);

        // AM In present / AM Out missing, PM In missing / PM Out present -
        // NOT a clean half (neither half is fully punched), so this must
        // stay classified exactly as before: Unofficial Exit (Incomplete
        // Punches), not the new half-day carve-out.
        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '07:59:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => '17:02:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $departments = Department::where('Dept_id', $dept->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(1, $row['unofficial_exit_count']);
        $this->assertStringContainsString('Incomplete Punches', $row['remarks']);
    }
}
