<?php

namespace Tests\Feature\Attendance;

use App\Jobs\BulkShiftRecomputeJob;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\EmployeeShiftSchedule;
use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\WeeklyPunchPairReconciliationService;
use App\Services\AttendanceMonitoringExportService;
use App\Services\Form48ExportService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * The full "Field Work" weekly state machine: Monday in_only / Friday
 * out_only, resolved retroactively by WeeklyPunchPairReconciliationService
 * once a week has fully closed. See that class's docblock for the complete
 * rule this exercises.
 *
 * Every scenario uses the same real week: Monday 2026-08-03 through
 * Friday 2026-08-07.
 */
class PunchPairReconciliationTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const MONDAY = '2026-08-03';

    private const TUESDAY = '2026-08-04';

    private const WEDNESDAY = '2026-08-05';

    private const THURSDAY = '2026-08-06';

    private const FRIDAY = '2026-08-07';

    private function setUpFieldWorkEmployee(): User
    {
        $user = $this->createEmployee();
        $shift = Shift::create([
            'name' => 'Field Work',
            'time_in' => '08:00', 'break_out' => '12:00', 'break_in' => '13:00', 'time_out' => '17:00',
            'is_active' => true,
        ]);

        $service = app(ShiftAssignmentService::class);
        // Starts the Saturday right before the test week, so no earlier
        // Monday/Friday pair falls under this assignment and pollutes the
        // reconcile() sweep or a whole-month AttendanceMonitoringExportService
        // aggregate with unrelated unpunched weeks.
        $from = Carbon::parse('2026-08-01');
        // Monday=1, Friday=5 (Carbon's dayOfWeek numbering).
        $service->assign($user, $shift->id, $from, null, null, [1], null, false, 'in_only');
        $service->assign($user, $shift->id, $from, null, null, [5], null, false, 'out_only');

        return $user;
    }

    private function punchDay(User $user, string $dateStr, string $time): void
    {
        AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => $user->EmpNo,
            'logdate' => $dateStr,
            'logtime' => $time,
        ]);
        app(PersonnelLogImportService::class)->recomputeDtr($user, $dateStr, $dateStr);
    }

    private function reconcile(): array
    {
        return app(WeeklyPunchPairReconciliationService::class)->reconcile(Carbon::parse(self::FRIDAY)->addDay());
    }

    private function dtrFor(User $user, string $dateStr): ?Dtr
    {
        return Dtr::where('employee_id', $user->id)->whereDate('date', $dateStr)->first();
    }

    private function overrideFor(User $user, string $dateStr): ?EmployeeShiftSchedule
    {
        return EmployeeShiftSchedule::where('user_id', $user->id)->whereDate('date', $dateStr)->first();
    }

    // ── Both punched - the normal week ───────────────────────────────────────

    public function test_both_punched_leaves_everything_untouched(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::MONDAY, '08:00:00');
        $this->punchDay($user, self::FRIDAY, '17:00:00');

        $result = $this->reconcile();

        $this->assertSame(0, $result['weeks_reconciled']);
        $this->assertSame('present', $this->dtrFor($user, self::MONDAY)?->status);
        $this->assertSame('present', $this->dtrFor($user, self::FRIDAY)?->status);

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $this->assertNull($this->overrideFor($user, $dateStr));
            $this->assertFalse(WorkSchedule::isWorkday($user, Carbon::parse($dateStr)));
        }
    }

    // ── Friday missing voids the whole week ──────────────────────────────────

    public function test_no_punch_all_week_marks_monday_through_thursday_absent_with_real_consequences(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::FRIDAY, '17:00:00');
        // Monday-Thursday never punched.

        $this->reconcile();

        // Monday needs no override of its own - already governed by its own
        // in_only ShiftAssignment, so "no punch -> no row -> Absent" already holds.
        $this->assertNull($this->dtrFor($user, self::MONDAY));
        $this->assertNull($this->overrideFor($user, self::MONDAY));
        $this->assertTrue(WorkSchedule::isWorkday($user, Carbon::parse(self::MONDAY)));

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $override = $this->overrideFor($user, $dateStr);
            $this->assertNotNull($override, "override missing for $dateStr");
            $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $override->type);
            $this->assertTrue($override->is_reconciliation_generated);
            $this->assertTrue(WorkSchedule::isWorkday($user, Carbon::parse($dateStr)), "$dateStr should now be a real workday");
            $this->assertFalse(WorkSchedule::isRestDay($user, Carbon::parse($dateStr)));
        }

        $this->assertSame('present', $this->dtrFor($user, self::FRIDAY)?->status);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'attendance',
            'action' => 'field_work_pair_week_incomplete',
            'target_id' => $user->id,
        ]);
    }

    /**
     * The voided Tue/Wed/Thu overrides must render as a real "Absent" badge
     * on the DTR view, not fall into the generic "Rest Day" bucket
     * DtrController::data() uses for an ordinary EmployeeShiftSchedule
     * override - a bug found after the underlying state-machine tests above
     * (which only assert on WorkSchedule::isWorkday()/isRestDay() and the
     * raw override row, never the rendered display) shipped without also
     * covering what the Time Keeper actually sees.
     */
    public function test_no_punch_all_week_dtr_view_shows_absent_not_rest_day(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::FRIDAY, '17:00:00');
        // Monday-Thursday never punched.

        $this->reconcile();

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $rows = collect($response->json('data'));

        $mondayRow = $rows->firstWhere('date', Carbon::parse(self::MONDAY)->format('M d, Y (D)'));
        $this->assertNotNull($mondayRow);
        $this->assertStringContainsString('Absent', $mondayRow['status_badge']);

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $row = $rows->firstWhere('date', Carbon::parse($dateStr)->format('M d, Y (D)'));
            $this->assertNotNull($row, "$dateStr should have a row.");
            $this->assertStringContainsString('Absent', $row['status_badge'], "$dateStr should show Absent, not Rest Day.");
            $this->assertStringNotContainsString('Rest Day', $row['status_badge']);
        }

        $fridayRow = $rows->firstWhere('date', Carbon::parse(self::FRIDAY)->format('M d, Y (D)'));
        $this->assertNotNull($fridayRow);
        $this->assertStringContainsString('Present', $fridayRow['status_badge']);
    }

    public function test_monday_punch_is_voided_when_friday_is_missing(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::MONDAY, '08:00:00');
        // Friday never punched.

        $this->reconcile();

        $this->assertNull($this->dtrFor($user, self::MONDAY), 'Monday must be voided despite the real punch.');
        $this->assertNull($this->overrideFor($user, self::MONDAY));

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $override = $this->overrideFor($user, $dateStr);
            $this->assertNotNull($override);
            $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $override->type);
        }

        $this->assertNull($this->dtrFor($user, self::FRIDAY));
    }

    // ── Mid-week "first punch wins" check-in ─────────────────────────────────

    public function test_mid_week_check_in_day_becomes_present_and_earlier_days_become_absent(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::WEDNESDAY, '09:15:00');
        $this->punchDay($user, self::FRIDAY, '17:00:00');

        $this->reconcile();

        // Monday: no override needed (already governed), no punch -> absent.
        $this->assertNull($this->dtrFor($user, self::MONDAY));
        $this->assertNull($this->overrideFor($user, self::MONDAY));
        $this->assertTrue(WorkSchedule::isWorkday($user, Carbon::parse(self::MONDAY)));

        // Tuesday: before the check-in day - converted to a real absence.
        $tueOverride = $this->overrideFor($user, self::TUESDAY);
        $this->assertNotNull($tueOverride);
        $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $tueOverride->type);

        // Wednesday: the real check-in day - resolves Present/Late from its own punch.
        $wedOverride = $this->overrideFor($user, self::WEDNESDAY);
        $this->assertNotNull($wedOverride);
        $this->assertNull($wedOverride->type);
        $this->assertSame('in_only', $wedOverride->punch_requirement);
        $this->assertTrue($wedOverride->is_reconciliation_generated);

        $wedDtr = $this->dtrFor($user, self::WEDNESDAY);
        $this->assertNotNull($wedDtr);
        $this->assertSame('09:15:00', $wedDtr->time_in_am);
        $this->assertSame('late', $wedDtr->status);

        // Thursday: after the check-in day - stays excluded, untouched.
        $this->assertNull($this->overrideFor($user, self::THURSDAY));
        $this->assertFalse(WorkSchedule::isWorkday($user, Carbon::parse(self::THURSDAY)));

        // Friday keeps its own real status.
        $this->assertSame('present', $this->dtrFor($user, self::FRIDAY)?->status);
    }

    public function test_mid_week_check_in_is_voided_entirely_when_friday_is_missing(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::WEDNESDAY, '09:15:00');
        // Friday never punched.

        $this->reconcile();

        // Wednesday's real punch is voided too - the surviving check-in day
        // is never spared once Friday's confirmation never came.
        $this->assertNull($this->dtrFor($user, self::WEDNESDAY));
        $wedOverride = $this->overrideFor($user, self::WEDNESDAY);
        $this->assertNotNull($wedOverride);
        $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $wedOverride->type);

        foreach ([self::TUESDAY, self::THURSDAY] as $dateStr) {
            $override = $this->overrideFor($user, $dateStr);
            $this->assertNotNull($override);
            $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $override->type);
        }

        $this->assertNull($this->dtrFor($user, self::FRIDAY));
    }

    // ── Independent coverage is never overridden ─────────────────────────────

    public function test_independently_covered_date_is_never_converted(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        // Monday and Friday both unpunched - week is incomplete.

        $leave = LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'start_date' => self::TUESDAY,
            'end_date' => self::TUESDAY,
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => self::TUESDAY, 'is_cancelled' => false]);

        $this->reconcile();

        $this->assertNull($this->overrideFor($user, self::TUESDAY), 'A leave-covered date must never be converted.');

        foreach ([self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $this->assertNotNull($this->overrideFor($user, $dateStr), "$dateStr should still be converted (not covered)");
        }
    }

    public function test_pre_existing_manual_override_is_never_clobbered(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        // A Time Keeper had already manually marked Wednesday as a rest day
        // for an unrelated reason, before the pairing ever failed.
        EmployeeShiftSchedule::create([
            'user_id' => $user->id,
            'date' => self::WEDNESDAY,
            'shift_id' => null,
            'type' => 'rest',
            'is_reconciliation_generated' => false,
        ]);
        // Monday and Friday both unpunched.

        $this->reconcile();

        $manualRow = $this->overrideFor($user, self::WEDNESDAY);
        $this->assertNotNull($manualRow);
        $this->assertSame('rest', $manualRow->type, 'The manual override must survive untouched.');
    }

    // ── Idempotency / self-healing ────────────────────────────────────────────

    public function test_reconcile_is_idempotent(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::MONDAY, '08:00:00');
        // Friday never punched.

        $service = app(WeeklyPunchPairReconciliationService::class);

        $first = $service->reconcile(Carbon::parse(self::FRIDAY)->addDay());
        $this->assertSame(1, $first['weeks_reconciled']);

        $second = $service->reconcile(Carbon::parse(self::FRIDAY)->addDay());
        $this->assertSame(0, $second['weeks_reconciled'], 're-running an already-reconciled week must be a no-op');

        $this->assertSame(
            1,
            HRAuditTrail::where('module', 'attendance')
                ->where('action', 'field_work_pair_week_incomplete')
                ->where('target_id', $user->id)
                ->count()
        );
    }

    // ── Form48 export must never resurrect a voided punch ────────────────────

    public function test_form48_export_never_resurrects_a_voided_monday_punch(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::MONDAY, '08:00:00');
        // Friday never punched - voids Monday despite the real punch.

        $this->reconcile();

        $this->assertNull($this->dtrFor($user, self::MONDAY));
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'logdate' => self::MONDAY]);

        $records = app(Form48ExportService::class)->buildRecords($user->id, '2026-08-01', '2026-08-31');

        $this->assertArrayNotHasKey(3, $records, 'A voided Monday (day 3) must not resurrect from attendance_logs.');
    }

    public function test_form48_export_shows_the_real_check_in_day_and_preserved_friday(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::WEDNESDAY, '09:15:00');
        $this->punchDay($user, self::FRIDAY, '17:00:00');

        $this->reconcile();

        $records = app(Form48ExportService::class)->buildRecords($user->id, '2026-08-01', '2026-08-31');

        $this->assertArrayHasKey(5, $records);
        $this->assertSame('09:15:00', $records[5]['am_in']);

        $this->assertArrayHasKey(7, $records);
        $this->assertSame('17:00:00', $records[7]['pm_out']);
    }

    // ── Monitoring Matrix must not miscount an out_only punch as blank ───────

    public function test_monitoring_matrix_counts_a_real_out_only_friday_punch_as_present(): void
    {
        // Freeze "now" right after the test week, and hire the employee on
        // its Monday, so the whole-month absence loop only ever evaluates
        // this one week - otherwise every other unpunched Mon/Fri in August
        // would also show up in the aggregate counts below, unrelated to
        // what this test is actually checking.
        $this->travelTo(Carbon::parse('2026-08-08 08:00:00'));

        $user = $this->setUpFieldWorkEmployee();
        $user->forceFill(['date_hired' => self::MONDAY])->save();

        $this->punchDay($user, self::MONDAY, '08:00:00');
        $this->punchDay($user, self::FRIDAY, '17:00:00');
        // Monday-Thursday genuinely non-workdays this week (no reconciliation run yet).

        $departments = Department::where('Dept_id', $user->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(0, $row['unfiled_count']);
        $this->assertSame(0, $row['unofficial_exit_count']);
    }

    // ── Command wiring ────────────────────────────────────────────────────────

    public function test_command_runs_and_reconciles(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->punchDay($user, self::MONDAY, '08:00:00');
        // Friday never punched.

        $this->travelTo(Carbon::parse(self::FRIDAY)->addDay());

        $this->artisan('attendance:reconcile-punch-pairs')->assertExitCode(0);

        $this->assertNull($this->dtrFor($user, self::MONDAY));
    }

    // ── Eager, single-employee reconciliation (gap found via manual review) ────

    /**
     * recomputeFullRange() already makes a backdated assignment's real
     * punches resolve correctly the moment it's created - but until this,
     * nothing made the retroactive VOIDING step happen eagerly too, so an
     * already-elapsed week under a brand-new Field Work Pair assignment sat
     * showing the plain "No Punch Required" gap label (not a real Absence)
     * until the next 01:15 scheduled sweep. reconcileForUser() closes that.
     */
    public function test_reconcile_for_user_reconciles_a_single_already_elapsed_week_immediately(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        // No punches at all this week.

        $result = app(WeeklyPunchPairReconciliationService::class)
            ->reconcileForUser($user, Carbon::parse(self::MONDAY), Carbon::parse(self::FRIDAY)->addDay());

        $this->assertSame(1, $result['weeks_checked']);
        $this->assertSame(1, $result['weeks_reconciled']);

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $override = $this->overrideFor($user, $dateStr);
            $this->assertNotNull($override, "override missing for $dateStr");
            $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $override->type);
        }
    }

    /**
     * Regression guard for a bug caught mid-refactor: extracting the shared
     * per-user sweep loop out of reconcile() must not drop the dtr_exempt
     * guard along the way.
     */
    public function test_reconcile_skips_dtr_exempt_employee(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $user->forceFill(['dtr_exempt' => true])->save();
        // No punches at all this week.

        $result = app(WeeklyPunchPairReconciliationService::class)->reconcile(Carbon::parse(self::FRIDAY)->addDay());

        $this->assertSame(0, $result['weeks_reconciled']);
        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $this->assertNull($this->overrideFor($user, $dateStr));
        }
    }

    /**
     * BulkShiftRecomputeJob's own eager-reconciliation wiring, tested
     * directly against handle() rather than through the queue, to avoid
     * queue-infrastructure setup unrelated to what's being verified here.
     */
    public function test_bulk_shift_recompute_job_reconciles_when_since_date_given(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        // No punches at all this week.
        $this->travelTo(Carbon::parse(self::FRIDAY)->addDay());

        (new BulkShiftRecomputeJob([$user->id], self::MONDAY))
            ->handle(app(PersonnelLogImportService::class), app(WeeklyPunchPairReconciliationService::class));

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $override = $this->overrideFor($user, $dateStr);
            $this->assertNotNull($override, "override missing for $dateStr");
            $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $override->type);
        }
    }

    /** An ordinary bulk assignment (no reconcileSince) must never attempt reconciliation. */
    public function test_bulk_shift_recompute_job_skips_reconciliation_when_no_since_date_given(): void
    {
        $user = $this->setUpFieldWorkEmployee();
        $this->travelTo(Carbon::parse(self::FRIDAY)->addDay());

        (new BulkShiftRecomputeJob([$user->id]))
            ->handle(app(PersonnelLogImportService::class), app(WeeklyPunchPairReconciliationService::class));

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $this->assertNull($this->overrideFor($user, $dateStr));
        }
    }

    /**
     * The "+ Add Shift" HTTP path (EmployeeScheduleController::update(),
     * form_type=add) end to end: assigning an is_field_work_pair shift with
     * an effective_from backdated onto an already-elapsed, fully blank week
     * must reconcile that week immediately, with no separate command run.
     */
    public function test_add_shift_field_work_pair_immediately_reconciles_already_elapsed_weeks(): void
    {
        $this->travelTo(Carbon::parse(self::FRIDAY)->addDay()->setTime(9, 0));

        $tk = $this->createTimeKeeper();
        $fieldWorkShift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'time_out' => '17:00',
            'is_active' => true, 'is_field_work_pair' => true,
        ]);
        $emp = $this->createEmployee();
        // No punches at all for the week of Aug 3-7.

        $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'form_type' => 'add',
            'shift_id' => $fieldWorkShift->id,
            'effective_from' => self::MONDAY,
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        foreach ([self::TUESDAY, self::WEDNESDAY, self::THURSDAY] as $dateStr) {
            $override = $this->overrideFor($emp, $dateStr);
            $this->assertNotNull($override, "$dateStr should already be reconciled without a separate command run.");
            $this->assertSame(WeeklyPunchPairReconciliationService::VOID_TYPE, $override->type);
        }
    }
}
