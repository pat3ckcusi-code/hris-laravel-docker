<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\AttendanceMonitoringExportService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * dtrs.late_minutes/undertime_minutes is itself a sum of two independent
 * components (LateCalculator: am_in + pm_in; UndertimeCalculator: am_out +
 * pm_out). AttendanceMonitoringExportService's $isLateCovered/
 * $isUndertimeCovered used to zero the WHOLE stored value whenever EITHER of
 * its two relevant slots was covered - so a source explaining only part of a
 * day (e.g. an afternoon-only WorkSuspension) wrongly discarded a genuine,
 * unrelated component from the OTHER, uncovered slot.
 *
 * Real incident that surfaced this: EmpNo 2000459 (Viesca), 2026-08-18 - a
 * company-wide WorkSuspension effective 13:00 correctly excludes only
 * pm_in/pm_out (WorkSchedule::applySuspension()), but the employee's real,
 * unrelated AM arrival at 08:04 (4 minutes late, correctly stored as
 * dtrs.late_minutes=4) was silently dropped from the Monitoring Matrix's
 * tardiness_count/tardiness_minutes/remarks because pm_in being suspension-
 * covered zeroed the whole day via the OR-based check. Fixed by
 * DtrPunchResolver::realPenalties(), which decomposes late/undertime per
 * component and gates each independently by $coveredSlots - see its own
 * docblock for the full reasoning, including why a naive OR->AND fix isn't
 * sufficient (this file's own is-covered checks also fold in whole-day
 * sources like Leave that aren't baked into the stored dtrs value at all).
 */
class RealPenaltyCoverageTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-08-18';

    public function test_monitoring_matrix_keeps_genuine_am_tardiness_when_an_afternoon_only_suspension_covers_pm_only(): void
    {
        $dept = Department::create([
            'DeptCode' => 'REALPENALTY',
            'Dept_name' => 'Real Penalty Coverage Test Dept',
            'Designation' => 'Test',
        ]);
        $user = $this->createEmployee(['last_name' => 'RealPenaltySuspension', 'Dept_id' => $dept->Dept_id]);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '13:00:00',
            'reason' => 'Typhoon - afternoon dismissal',
            'type' => 'weather',
        ]);

        // Genuine AM lateness (global 8-5 schedule: workStart 08:00), unrelated to the
        // PM-only suspension. No PM punches at all - the employee complied with the
        // 1pm dismissal and never returned.
        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:04:00',
            'time_out_am' => '12:08:00',
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 4,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $departments = Department::where('Dept_id', $dept->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(1, $row['tardiness_count'], 'The genuine AM lateness must still be counted - the PM-only suspension does not explain it.');
        $this->assertSame(4, $row['tardiness_minutes']);
        $this->assertStringContainsString('18-Tardy (4 mins)', $row['remarks']);
        $this->assertSame(0, $row['undertime_count'], 'No PM punches exist, but the suspension fully covers pm_out - no undertime should be phantom-charged.');
        $this->assertSame(0, $row['undertime_minutes']);
    }

    public function test_monitoring_matrix_suppresses_tardiness_when_a_full_day_suspension_covers_every_slot(): void
    {
        $dept = Department::create([
            'DeptCode' => 'REALPENALTY2',
            'Dept_name' => 'Real Penalty Coverage Full Day Test Dept',
            'Designation' => 'Test',
        ]);
        $user = $this->createEmployee(['last_name' => 'RealPenaltyFullDaySuspension', 'Dept_id' => $dept->Dept_id]);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Typhoon - full day',
            'type' => 'weather',
        ]);

        // A stray late punch on an otherwise fully-suspended day must not surface as
        // tardiness - the whole day is authorized.
        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:04:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 4,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $departments = Department::where('Dept_id', $dept->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(0, $row['tardiness_count'], 'A full-day suspension covers every slot - the whole day must stay suppressed.');
        $this->assertSame(0, $row['tardiness_minutes']);
    }

    /**
     * A separate bug found while verifying the fix above against real company-wide
     * data: an evening shift ending late (e.g. 23:00, crossesMidnight=false since
     * workStart < workEnd - the schedule isn't DESIGNED to cross midnight) can still
     * have a genuine pm_out punch spill a little past 00:00 when the employee stays
     * unusually late (AttendanceMatcher's own late_out_hours tolerance matches it at
     * import time using the punch's real attendance_logs datetime). But dtrs.time_out_pm
     * only stores the bare clock value, and realPenalties()'s recompute (needed here
     * because a DtrExcuse covers only 'am_out', forcing the undertime metric into its
     * partial-coverage branch) has to reconstruct a Carbon from that bare value with no
     * date info to go on. Anchoring it to the SAME calendar day as its own reference
     * (workEnd) would put it ~23 hours before that reference instead of ~1 hour after
     * it, producing a wildly wrong multi-hour phantom undertime instead of the correct
     * 0. Real reported shape: EmpNo 2200647 (Atuel), 2026-08-01, workEnd 23:00, pm_out
     * punched 00:04 - confirmed via a company-wide scan comparing every August 2026 dtrs
     * row's stored late/undertime against a fresh recompute from its own stored punch
     * columns, which is how this was actually found (not the original Monitoring Matrix
     * report this fix set out to address).
     */
    public function test_monitoring_matrix_does_not_charge_phantom_undertime_for_an_evening_shift_departure_that_spills_past_midnight(): void
    {
        $dept = Department::create([
            'DeptCode' => 'REALPENALTY3',
            'Dept_name' => 'Real Penalty Coverage Midnight Spill Test Dept',
            'Designation' => 'Test',
        ]);
        $user = $this->createEmployee(['last_name' => 'MidnightSpillDeparture', 'Dept_id' => $dept->Dept_id]);

        $shift = Shift::create([
            'name' => 'Evening Shift', 'time_in' => '15:00', 'break_out' => '19:00',
            'break_in' => '20:00', 'time_out' => '23:00',
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse(self::DATE)->subDay(), null, null, null, [0, 1, 2, 3, 4, 5, 6], true
        );

        // am_out excused so the undertime metric (am_out/pm_out) has exactly one of
        // its two slots covered, forcing realPenalties() into its recompute branch -
        // am_out never actually contributes on this no_break schedule regardless, but
        // this is exactly the condition that used to reach the buggy Carbon
        // reconstruction for pm_out.
        DtrExcuse::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'excuse_type' => 'system_failure',
            'is_full_day' => false,
            'excuse_am_in' => false,
            'excuse_am_out' => true,
            'excuse_pm_in' => false,
            'excuse_pm_out' => false,
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '15:19:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => '00:04:00',
            'late_minutes' => 19,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $departments = Department::where('Dept_id', $dept->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(0, $row['undertime_count'], 'A pm_out punched shortly after midnight on a late-ending evening shift is a LATE departure (after workEnd), not undertime - it must never be misdated same-day and charged as ~23 hours of phantom undertime.');
        $this->assertSame(0, $row['undertime_minutes']);
        $this->assertStringNotContainsString('Undertime', $row['remarks']);
    }
}
