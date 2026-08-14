<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Attendance\WeeklyPunchPairReconciliationService;
use App\Services\PersonnelLogImportService;
use App\Services\ResolvedScheduleService;
use App\Services\ShiftAssignmentService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A Field Work Pair shift's Monday-in/Friday-out ShiftAssignment pair leaves
 * Tue/Wed/Thu (and weekends) as an ordinary WorkSchedule::isWorkday()===false
 * day-of-week gap - functionally identical to any other assignment gap (no
 * absence consequence), but ResolvedScheduleService::buildMonth() must label
 * it distinctly ("No Punch Required") rather than collapsing it into the
 * generic "Rest Day" used for every other kind of gap, which wrongly implies
 * a day off. See WorkSchedule::isFieldWorkPairGapDay().
 */
class ResolvedScheduleServiceTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_field_work_pair_gap_days_are_labeled_no_punch_required(): void
    {
        $shift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'time_out' => '17:00',
            'is_active' => true, 'is_field_work_pair' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assignGroupedByPunchRequirement(
            $emp, $shift->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'), null,
            null, [1, 5], false, 'both', [1 => 'in_only', 5 => 'out_only']
        );

        $days = (new ResolvedScheduleService())->buildMonth($emp, Carbon::parse('2026-07-01'));

        // 2026-07-06 is a Monday, 2026-07-10 is a Friday - both governed days
        // must still resolve to the real shift, not the gap label.
        $monday = $days->get('2026-07-06');
        $this->assertSame('Field Work', $monday['label']);
        $this->assertFalse($monday['isFieldWorkPairGap']);
        $this->assertFalse($monday['isRestDay']);

        $friday = $days->get('2026-07-10');
        $this->assertSame('Field Work', $friday['label']);
        $this->assertFalse($friday['isFieldWorkPairGap']);
        $this->assertFalse($friday['isRestDay']);

        // Tue/Wed/Thu, and the following weekend - all ungoverned days within
        // the assignment's own date range - get the distinct label, not "Rest Day".
        foreach (['2026-07-07', '2026-07-08', '2026-07-09', '2026-07-11', '2026-07-12'] as $dateStr) {
            $day = $days->get($dateStr);
            $this->assertSame('No Punch Required', $day['label'], "$dateStr should be labeled No Punch Required.");
            $this->assertTrue($day['isFieldWorkPairGap'], "$dateStr should be flagged as a Field Work Pair gap.");
            $this->assertFalse($day['isRestDay'], "$dateStr should not also be flagged isRestDay.");
        }
    }

    public function test_ordinary_day_of_week_gap_still_labels_rest_day(): void
    {
        // A genuine concurrent-split employee (MWF only, on an ordinary
        // non-field-work-pair shift) - Tuesday is a plain day-of-week gap,
        // unrelated to the Field Work Shift feature, and must keep showing
        // "Rest Day" exactly as before this fix.
        $shift = Shift::create([
            'name' => 'Ordinary', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        ShiftAssignment::create([
            'user_id' => $emp->id,
            'shift_id' => $shift->id,
            'days_of_week' => [1, 3, 5],
            'work_days' => [1, 3, 5],
            'effective_from' => Carbon::parse('2026-07-01'),
            'effective_until' => Carbon::parse('2026-12-31'),
        ]);

        $days = (new ResolvedScheduleService())->buildMonth($emp, Carbon::parse('2026-07-01'));

        // 2026-07-07 is a Tuesday - not in [Mon, Wed, Fri].
        $tuesday = $days->get('2026-07-07');
        $this->assertSame('Rest Day', $tuesday['label']);
        $this->assertTrue($tuesday['isRestDay']);
        $this->assertFalse($tuesday['isFieldWorkPairGap']);
    }

    public function test_work_schedule_is_field_work_pair_gap_day_helper(): void
    {
        $shift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'time_out' => '17:00',
            'is_active' => true, 'is_field_work_pair' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assignGroupedByPunchRequirement(
            $emp, $shift->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'), null,
            null, [1, 5], false, 'both', [1 => 'in_only', 5 => 'out_only']
        );

        $this->assertFalse(WorkSchedule::isFieldWorkPairGapDay($emp, Carbon::parse('2026-07-06'))); // Monday
        $this->assertTrue(WorkSchedule::isFieldWorkPairGapDay($emp, Carbon::parse('2026-07-07'))); // Tuesday
        $this->assertFalse(WorkSchedule::isFieldWorkPairGapDay($emp, Carbon::parse('2026-07-10'))); // Friday
        $this->assertTrue(WorkSchedule::isFieldWorkPairGapDay($emp, Carbon::parse('2026-07-11'))); // Saturday
    }

    /**
     * Once WeeklyPunchPairReconciliationService voids a week (Friday
     * punched, Monday-Thursday never punched), the resulting
     * 'field_work_unconfirmed' overrides on Tue/Wed/Thu must render as a
     * real absence label, not "Standard Day" (resolutionSource()'s shiftName
     * resolves null for this type, which the old label match() fell back to)
     * or "Rest Day" - the day is neither. Found via manual review of the
     * exact worked-example row this state machine documents.
     */
    public function test_voided_field_work_pair_days_are_labeled_absent_unconfirmed(): void
    {
        $shift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-07-01'), null, null, [1], null, false, 'in_only');
        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-07-01'), null, null, [5], null, false, 'out_only');

        // 2026-07-06 is a Monday, 2026-07-10 is a Friday - Friday punched, Monday-Thursday never punched.
        AttendanceLog::create(['user_id' => $emp->id, 'emp_no' => $emp->EmpNo, 'logdate' => '2026-07-10', 'logtime' => '17:00:00']);
        app(PersonnelLogImportService::class)->recomputeDtr($emp, '2026-07-10', '2026-07-10');

        app(WeeklyPunchPairReconciliationService::class)->reconcile(Carbon::parse('2026-07-11'));

        $days = (new ResolvedScheduleService())->buildMonth($emp, Carbon::parse('2026-07-01'));

        foreach (['2026-07-07', '2026-07-08', '2026-07-09'] as $dateStr) {
            $day = $days->get($dateStr);
            $this->assertSame('Absent (Unconfirmed Field Work)', $day['label'], "$dateStr should be labeled as a voided absence.");
            $this->assertTrue($day['isVoidedAbsence'], "$dateStr should be flagged isVoidedAbsence.");
            $this->assertNull($day['hours'], "$dateStr has no real schedule hours to show.");
        }

        // Friday keeps its real resolved shift name, not the voided label.
        $friday = $days->get('2026-07-10');
        $this->assertFalse($friday['isVoidedAbsence']);
        $this->assertSame('Field Work', $friday['label']);
    }
}
