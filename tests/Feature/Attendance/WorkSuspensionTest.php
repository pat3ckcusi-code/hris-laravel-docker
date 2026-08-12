<?php

namespace Tests\Feature\Attendance;

use App\Jobs\WorkSuspensionRecomputeJob;
use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\WorkSuspension;
use App\Services\AttendanceMonitoringExportService;
use App\Services\LwopAggregationService;
use App\Services\PersonnelLogImportService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A Time Keeper/HR Manager can declare a company-wide work suspension
 * (typhoon dismissal at a cutoff time, or a full-day urgent-event closure)
 * so employees aren't penalized for not working during the suspended
 * period. See WorkSchedule::applySuspension() for the core mechanism.
 */
class WorkSuspensionTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-07-20';

    // Standard day: workStart 08:00, morningEnd 11:00, lunchReturn 13:00, workEnd 17:00.
    private function standardDay(): WorkSchedule
    {
        return WorkSchedule::global();
    }

    // ── WorkSchedule::applySuspension() ─────────────────────────────────────

    public function test_cutoff_before_morning_end_excludes_all_four_slots(): void
    {
        [$schedule, $slots] = $this->standardDay()->applySuspension('09:00');

        $this->assertSame(['am_in', 'am_out', 'pm_in', 'pm_out'], array_keys($slots));
        $this->assertSame('17:00', $schedule->workEnd, 'Full-day exclusion does not need to alter the schedule.');
    }

    public function test_null_cutoff_is_treated_as_full_day(): void
    {
        [, $slots] = $this->standardDay()->applySuspension(null);

        $this->assertSame(['am_in', 'am_out', 'pm_in', 'pm_out'], array_keys($slots));
    }

    public function test_cutoff_between_morning_end_and_lunch_return_excludes_pm_slots_only(): void
    {
        [$schedule, $slots] = $this->standardDay()->applySuspension('12:00');

        $this->assertSame(['pm_in', 'pm_out'], array_keys($slots));
        $this->assertSame('17:00', $schedule->workEnd);
    }

    public function test_cutoff_after_lunch_return_caps_work_end_with_no_slot_exclusions(): void
    {
        [$schedule, $slots] = $this->standardDay()->applySuspension('15:00');

        $this->assertSame([], $slots);
        $this->assertSame('15:00', $schedule->workEnd);
        $this->assertSame('08:00', $schedule->workStart, 'Only workEnd is adjusted.');
    }

    public function test_cutoff_at_or_after_normal_work_end_has_no_effect(): void
    {
        [$schedule, $slots] = $this->standardDay()->applySuspension('18:00');

        $this->assertSame([], $slots);
        $this->assertSame('17:00', $schedule->workEnd);
    }

    // ── Controller: role gating ─────────────────────────────────────────────

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'suspension_date' => self::DATE,
            'suspension_time' => '15:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ], $overrides);
    }

    public function test_time_keeper_can_declare_a_work_suspension(): void
    {
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)
            ->post(route('attendance.work-suspensions.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('work_suspensions', [
            'suspension_date' => self::DATE,
            'reason' => 'Typhoon Egay',
        ]);
    }

    public function test_hr_manager_can_declare_a_work_suspension(): void
    {
        $hr = $this->createHRManager();

        $this->actingAs($hr)
            ->post(route('attendance.work-suspensions.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('work_suspensions', ['suspension_date' => self::DATE]);
    }

    public function test_department_head_cannot_manage_work_suspensions(): void
    {
        $dh = $this->createDepartmentHead();

        $this->actingAs($dh)
            ->post(route('attendance.work-suspensions.store'), $this->payload())
            ->assertStatus(403);

        $this->actingAs($dh)
            ->get(route('attendance.work-suspensions.index'))
            ->assertStatus(403);
    }

    public function test_time_keeper_can_view_the_index_page(): void
    {
        $tk = $this->createTimeKeeper();
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '15:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
            'created_by' => $tk->id,
        ]);

        $this->actingAs($tk)
            ->get(route('attendance.work-suspensions.index'))
            ->assertStatus(200)
            ->assertSee('Typhoon Egay')
            ->assertSee('Weather / Typhoon');
    }

    public function test_reason_over_255_characters_is_stored_without_truncation(): void
    {
        // Regression guard: `reason` used to be VARCHAR(255) while validation allowed
        // max:1000, so any reason over 255 chars threw SQLSTATE[22001] on save.
        $tk = $this->createTimeKeeper();
        $longReason = str_repeat('A', 600);

        $this->actingAs($tk)
            ->post(route('attendance.work-suspensions.store'), $this->payload(['reason' => $longReason]))
            ->assertRedirect();

        $this->assertDatabaseHas('work_suspensions', [
            'suspension_date' => self::DATE,
            'reason' => $longReason,
        ]);
    }

    public function test_duplicate_date_is_rejected(): void
    {
        $tk = $this->createTimeKeeper();
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Existing',
            'type' => 'weather',
            'created_by' => $tk->id,
        ]);

        $this->actingAs($tk)
            ->post(route('attendance.work-suspensions.store'), $this->payload())
            ->assertSessionHasErrors('suspension_date');
    }

    public function test_declaring_or_deleting_dispatches_the_recompute_job(): void
    {
        Queue::fake();
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->post(route('attendance.work-suspensions.store'), $this->payload());

        Queue::assertPushed(WorkSuspensionRecomputeJob::class, fn ($job) => $job->date === self::DATE);

        $suspension = WorkSuspension::firstWhere('suspension_date', self::DATE);
        $this->actingAs($tk)->delete(route('attendance.work-suspensions.destroy', $suspension));

        Queue::assertPushed(WorkSuspensionRecomputeJob::class, 2);
    }

    // ── End-to-end DTR effect ────────────────────────────────────────────────

    public function test_full_day_suspension_with_no_punches_produces_no_dtr_row_and_no_penalty(): void
    {
        $emp = $this->createEmployee();
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Urgent event',
            'type' => 'event',
        ]);

        // No attendance_logs at all - mirrors "nobody came in".
        app(PersonnelLogImportService::class)->recomputeDtr($emp, self::DATE, self::DATE);

        $this->assertNull(Dtr::where('employee_id', $emp->id)->whereDate('date', self::DATE)->first());
    }

    public function test_partial_suspension_zeroes_undertime_for_a_punch_at_the_cutoff(): void
    {
        $emp = $this->createEmployee();
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '15:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        foreach (['08:00:00', '11:00:00', '13:00:00', '15:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $emp->id,
                'emp_no' => $emp->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($emp, self::DATE, self::DATE);

        $dtr = Dtr::where('employee_id', $emp->id)->whereDate('date', self::DATE)->firstOrFail();
        $this->assertSame('15:00:00', $dtr->time_out_pm);
        $this->assertSame(0, $dtr->undertime_minutes);
    }

    public function test_partial_suspension_still_charges_undertime_for_a_punch_before_the_cutoff(): void
    {
        $emp = $this->createEmployee();
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '15:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        foreach (['08:00:00', '11:00:00', '13:00:00', '14:30:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $emp->id,
                'emp_no' => $emp->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($emp, self::DATE, self::DATE);

        $dtr = Dtr::where('employee_id', $emp->id)->whereDate('date', self::DATE)->firstOrFail();
        $this->assertSame('14:30:00', $dtr->time_out_pm);
        $this->assertSame(30, $dtr->undertime_minutes, 'Left 30 minutes before the 3:00 PM suspension cutoff.');
    }

    public function test_declaring_a_suspension_recomputes_already_imported_punches(): void
    {
        $emp = $this->createEmployee();

        foreach (['08:00:00', '11:00:00', '13:00:00', '14:30:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $emp->id,
                'emp_no' => $emp->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }
        app(PersonnelLogImportService::class)->recomputeDtr($emp, self::DATE, self::DATE);
        $before = Dtr::where('employee_id', $emp->id)->whereDate('date', self::DATE)->firstOrFail();
        $this->assertSame(150, $before->undertime_minutes, 'Left at 2:30 PM against a normal 5:00 PM end.');

        $tk = $this->createTimeKeeper();
        $this->actingAs($tk)->post(route('attendance.work-suspensions.store'), $this->payload([
            'suspension_time' => '14:30',
        ]));

        (new WorkSuspensionRecomputeJob(self::DATE))->handle(app(PersonnelLogImportService::class));

        $after = Dtr::where('employee_id', $emp->id)->whereDate('date', self::DATE)->firstOrFail();
        $this->assertSame(0, $after->undertime_minutes, 'A 2:30 PM cutoff means the 2:30 PM departure is now on-time.');
    }

    // ── LWOP aggregation ─────────────────────────────────────────────────────

    public function test_full_day_suspension_is_excluded_from_awol_classification(): void
    {
        $emp = $this->createEmployee();
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Urgent event',
            'type' => 'event',
        ]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse(self::DATE), Carbon::parse(self::DATE)
        );

        $this->assertFalse($classified->has(self::DATE), 'A fully suspended day is not a scheduled workday.');
    }

    // ── Frontline/essential-personnel exemption ─────────────────────────────

    public function test_is_frontline_exempt_true_when_employee_individually_flagged(): void
    {
        $emp = $this->createEmployee(['is_frontline' => true]);

        $this->assertTrue($emp->isFrontlineExempt());
    }

    public function test_is_frontline_exempt_true_when_department_flagged(): void
    {
        $emp = $this->createEmployee();
        $emp->department->update(['is_frontline' => true]);

        $this->assertTrue($emp->fresh()->isFrontlineExempt());
    }

    public function test_is_frontline_exempt_false_when_neither_flagged(): void
    {
        $emp = $this->createEmployee();

        $this->assertFalse($emp->isFrontlineExempt());
    }

    public function test_individually_flagged_frontline_employee_still_charged_undertime_during_full_day_suspension(): void
    {
        $emp = $this->createEmployee(['is_frontline' => true]);
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Urgent event',
            'type' => 'event',
        ]);

        foreach (['08:30:00', '11:00:00', '13:00:00', '16:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $emp->id,
                'emp_no' => $emp->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($emp, self::DATE, self::DATE);

        $dtr = Dtr::where('employee_id', $emp->id)->whereDate('date', self::DATE)->firstOrFail();
        $this->assertSame(30, $dtr->late_minutes, 'Frontline employees are not covered by the suspension.');
        $this->assertSame(60, $dtr->undertime_minutes);
    }

    public function test_frontline_department_employee_still_charged_undertime_for_a_punch_before_the_cutoff(): void
    {
        $emp = $this->createEmployee();
        $emp->department->update(['is_frontline' => true]);
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '15:00',
            'reason' => 'Typhoon Egay',
            'type' => 'weather',
        ]);

        foreach (['08:00:00', '11:00:00', '13:00:00', '14:30:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $emp->id,
                'emp_no' => $emp->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($emp, self::DATE, self::DATE);

        $dtr = Dtr::where('employee_id', $emp->id)->whereDate('date', self::DATE)->firstOrFail();
        $this->assertSame(150, $dtr->undertime_minutes, 'A frontline department is unaffected by the 3:00 PM cutoff.');
    }

    public function test_frontline_employee_blank_day_is_flagged_unfiled_leave_during_full_day_suspension(): void
    {
        $emp = $this->createEmployee(['is_frontline' => true, 'date_hired' => '2020-01-01']);
        $this->createLeaveBalance($emp);
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Urgent event',
            'type' => 'event',
        ]);

        $rows = app(AttendanceMonitoringExportService::class)->getRows(
            collect([$emp->department]), (int) Carbon::parse(self::DATE)->format('n'), (int) Carbon::parse(self::DATE)->format('Y')
        );

        $row = $rows->firstWhere('user_id', $emp->id);
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row['unfiled_count'] ?? 0, 'A frontline employee with no punches on a fully suspended day is still Unfiled Leave.');
    }

    public function test_frontline_employee_blank_day_counts_as_awol_during_full_day_suspension(): void
    {
        $emp = $this->createEmployee(['is_frontline' => true]);
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Urgent event',
            'type' => 'event',
        ]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse(self::DATE), Carbon::parse(self::DATE)
        );

        $this->assertTrue($classified->get(self::DATE), 'A frontline employee is still expected to work a fully suspended day.');
    }
}
