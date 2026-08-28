<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\Locator;
use App\Models\User;
use App\Services\AttendanceMonitoringExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * DtrPunchResolver::imputedLateMinutes()/imputedUndertimeMinutes() each sum
 * two independent AM/PM components. Every caller (DtrController's Locator/
 * Excuse/Suspension branches, AttendanceMonitoringExportService's phantom-
 * undertime loop) used to accept-or-reject the WHOLE combined result based
 * on checking coverage of only ONE of the four slots - so a source that
 * explains only one half of the day still let the other half's
 * independently-computed component through unfiltered.
 *
 * Real incident that surfaced this: EmpNo 2100472 (Cusi) had an approved
 * Locator covering only 'am_out' (a morning personal errand) and a
 * completely normal PM (on-time departure at 17:02, after the 17:00 shift
 * end) - the DTR page showed "180 undertime," entirely the am_out-missing
 * component the SAME Locator already explained, mislabeled as a PM Out
 * problem. Fixed by making both functions accept an explicit $coveredSlots
 * param so each component is gated independently - see
 * DtrPunchResolver::imputedLateMinutes()/imputedUndertimeMinutes()'s own
 * docblocks.
 *
 * A second, related fix in the same pass: DtrController's Excuse/Suspension
 * branches used to also zero the STORED dtrs.late_minutes/undertime_minutes
 * figure whenever either IN-side slot was covered, even though that stored
 * value is already correctly component-scoped at import time (filing/
 * editing an excuse or suspension always triggers a recompute that
 * re-derives the exclusion fresh) - a genuine, unrelated, uncovered figure
 * could get silently zeroed. That redundant gate was dropped.
 */
class ImputedUndertimeCoverageTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-08-06';

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

    // ── Locator ───────────────────────────────────────────────────────────────

    public function test_dtr_page_undertime_not_charged_for_a_locator_covered_am_out_gap(): void
    {
        $user = $this->createEmployee(['last_name' => 'LocatorAmOut']);

        // Departure 10:30/arrival 12:30 on the global 8-5 schedule covers
        // am_out only - mirrors the real reported case exactly.
        Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Personal',
            'location' => 'ORMECO',
            'travel_date' => self::DATE,
            'intended_departure_time' => '10:30:00',
            'intended_arrival_time' => '12:30:00',
            'detail' => 'pay bills',
            'status' => 'approved',
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '07:59:00',
            'time_out_am' => null,
            'time_in_pm' => '12:41:00',
            'time_out_pm' => '17:02:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['undertime_minutes'], 'The am_out gap is fully explained by the same Locator - it must not be charged as PM Out undertime.');
        $this->assertFalse($row['is_undertime']);
        $this->assertFalse($row['is_pm_out_undertime']);
    }

    public function test_dtr_page_late_not_charged_for_a_locator_covered_pm_in_gap(): void
    {
        $user = $this->createEmployee(['last_name' => 'LocatorPmIn']);

        // Departure 12:45/arrival 13:15 on the global 8-5 schedule covers
        // pm_in only.
        Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Personal',
            'location' => 'City Hall',
            'travel_date' => self::DATE,
            'intended_departure_time' => '12:45:00',
            'intended_arrival_time' => '13:15:00',
            'detail' => 'personal errand',
            'status' => 'approved',
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '07:58:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['late_minutes'], 'The pm_in gap is fully explained by the Locator - it must not be imputed as lateness.');
        $this->assertFalse($row['is_late']);
    }

    public function test_monitoring_matrix_undertime_not_phantom_charged_for_a_locator_covered_am_out_gap(): void
    {
        $dept = Department::create([
            'DeptCode' => 'IMPUTETEST',
            'Dept_name' => 'Imputed Undertime Test Dept',
            'Designation' => 'Test',
        ]);
        $user = $this->createEmployee(['last_name' => 'LocatorMatrix', 'Dept_id' => $dept->Dept_id]);

        Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Personal',
            'location' => 'ORMECO',
            'travel_date' => self::DATE,
            'intended_departure_time' => '10:30:00',
            'intended_arrival_time' => '12:30:00',
            'detail' => 'pay bills',
            'status' => 'approved',
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '07:59:00',
            'time_out_am' => null,
            'time_in_pm' => '12:41:00',
            'time_out_pm' => '17:02:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $departments = Department::where('Dept_id', $dept->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(0, $row['undertime_count'], 'The Locator-covered am_out gap must not be phantom-charged into the Monitoring Matrix (and, downstream, a real VL deduction).');
        $this->assertSame(0, $row['undertime_minutes']);
    }

    // ── DtrExcuse: stored-value redundant-zeroing fix ────────────────────────

    public function test_dtr_page_preserves_genuine_stored_lateness_when_only_the_other_in_slot_is_excused(): void
    {
        $user = $this->createEmployee(['last_name' => 'ExcuseStoredLate']);

        // Excuses only am_in - pm_in's own genuine, unrelated lateness (already
        // correctly reflected in the stored late_minutes at import time) must survive.
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
            'time_in_pm' => '13:20:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 20, // genuine PM In lateness, already correctly stored
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertSame(20, $row['late_minutes'], 'Excusing am_in must not silently zero a genuine, unrelated, already-stored pm_in lateness figure.');
    }

    // ── Per-cell attribution (component mis-highlighting bug) ───────────────
    //
    // DtrController used to set is_am_in_late/is_pm_out_undertime from the
    // WHOLE combined imputedLateMinutes()/imputedUndertimeMinutes() result,
    // so a pm_in-only (or am_out-only) gap wrongly painted AM In (or PM Out)
    // red instead of the actual cause. Real reported case: 07:58 AM In,
    // 12:01 AM Out, PM In missing, 17:02 PM Out - the page highlighted the
    // on-time AM In cell for a "240 late" that was entirely PM In's fault.

    public function test_dtr_page_highlights_pm_in_not_am_in_when_only_pm_in_is_imputed_late(): void
    {
        $user = $this->createEmployee(['last_name' => 'PmInMissingAttribution']);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '07:58:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertSame(240, $row['late_minutes']);
        $this->assertTrue($row['is_late']);
        $this->assertFalse($row['is_am_in_late'], 'AM In was on time (07:58) - the imputed lateness came entirely from the missing PM In, not AM In.');
        $this->assertTrue($row['is_pm_in_late']);
    }

    public function test_dtr_page_highlights_am_out_not_pm_out_when_only_am_out_is_imputed_undertime(): void
    {
        Carbon::setTestNow(self::DATE.' 18:00:00');

        $user = $this->createEmployee(['last_name' => 'AmOutMissingAttribution']);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '07:59:00',
            'time_out_am' => null,
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:02:00',
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
        $this->assertFalse($row['is_pm_out_undertime'], 'PM Out was after the 17:00 shift end (17:02) - the imputed undertime came entirely from the missing AM Out, not PM Out.');
    }

    public function test_dtr_page_does_not_highlight_am_in_when_am_in_is_genuinely_late_and_pm_in_is_also_imputed(): void
    {
        $user = $this->createEmployee(['last_name' => 'GenuineAmInLateWithImputedPmIn']);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:15:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => '17:00:00',
            'late_minutes' => 15,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertSame(15, $row['late_minutes'], 'The stored, genuine am_in lateness must win over imputation - the imputed closure never even runs.');
        $this->assertTrue($row['is_am_in_late'], 'Real-punch attribution (08:15 falls in the late window) must survive unchanged.');
        $this->assertFalse($row['is_pm_in_late'], 'No imputation ran (stored late_minutes was already > 0), so pm_in must not be flagged.');
    }

    public function test_dtr_page_am_in_and_pm_in_both_flagged_when_both_components_are_imputed(): void
    {
        $user = $this->createEmployee(['last_name' => 'BothInSlotsImputed']);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        // Global default schedule: am_in component (08:00->11:00 = 180) + pm_in component (13:00->17:00 = 240) = 420.
        $this->assertSame(420, $row['late_minutes']);
        $this->assertTrue($row['is_am_in_late'], 'am_in genuinely fired - must be flagged.');
        $this->assertTrue($row['is_pm_in_late'], 'pm_in genuinely fired too - neither component should suppress the other.');
    }
}
