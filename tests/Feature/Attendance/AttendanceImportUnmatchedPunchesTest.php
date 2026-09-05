<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\Locator;
use App\Models\Shift;
use App\Models\User;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Proactive detection tool for the class of bug fixed in ShiftPunchGrouper
 * (a raw punch stranded in dtrs.unmatched_logs instead of being resolved
 * into a slot): Time Keeper/HR Manager should be able to spot and self-serve
 * a fix from the Import Attendance Logs page, without a developer having to
 * manually trace it via tinker/SQL.
 *
 * The list itself is lazily loaded via a dedicated JSON endpoint
 * (AttendanceImportController::unmatchedPunchesData()) rather than rendered
 * inline on the main page - see that method's docblock. Tests here hit that
 * endpoint directly and assert against its `html` field, instead of the main
 * page response.
 */
class AttendanceImportUnmatchedPunchesTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    /**
     * Every other test in this file hits unmatchedPunchesData() directly and
     * never renders the main page - a real incident (2026-09-05) slipped
     * through exactly that gap: a JS comment containing the literal text
     * "<x-hris.table-pagination>" was compiled by Blade as a real anonymous
     * component tag (Blade's component-tag compiler scans raw file content
     * for `<x-` with no awareness of JS/string/comment context), corrupting
     * everything after it and leaving attendance-import.blade.php a 500
     * ParseError for every visitor - undetected by any test here since none
     * of them actually compiled this Blade file. This smoke test closes that
     * gap generically, not just for this one incident.
     */
    public function test_main_page_renders_successfully(): void
    {
        $timeKeeper = $this->createTimeKeeper();

        $this->actingAs($timeKeeper)
            ->get(route('hr-manager.attendance.import'))
            ->assertOk()
            ->assertSee('Pull Biometric Punch Logs')
            ->assertSee('Diagnostics');
    }

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

        $response = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data'));

        $response->assertOk();
        $this->assertStringContainsString('Stranded', $response->json('html'));
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

        $response = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data'));

        $response->assertOk();
        $this->assertStringNotContainsString('ForgotToPunch', $response->json('html'));
    }

    /**
     * A plain createEmployee() falls back to WorkSchedule::global() (Standard
     * Day), which builds ot_in/ot_out candidate events that can tie with PM
     * Out on a late-day punch - an unrelated ambiguity that has nothing to do
     * with Locator recovery. An explicit ordinary Shift assignment sidesteps
     * it, matching real-world employee setups.
     */
    private function assignOrdinaryShift(User $user, string $date): void
    {
        $shift = Shift::create([
            'name' => 'Unmatched Punches Test Shift', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse($date)->subDay(), null, null, null, [0, 1, 2, 3, 4, 5, 6], false
        );
    }

    /**
     * A row fully explained by an approved Locator's [departure, arrival]
     * conflict window (see tests/Feature/Attendance/ExcludedSlotPunchRecoveryTest.php
     * for the display-side fix this mirrors) is not a grouping bug - Recompute
     * can never resolve it, so it must not clutter this list.
     */
    public function test_row_fully_explained_by_a_locator_conflict_does_not_appear(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['last_name' => 'Explainedbylocator']);
        $date = '2026-08-19';
        $this->assignOrdinaryShift($employee, $date);

        Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Personal',
            'location' => 'Test location',
            'travel_date' => $date,
            'intended_departure_time' => '11:00:00',
            'intended_arrival_time' => '13:00:00',
            'detail' => 'Test errand',
            'status' => 'approved',
        ]);

        foreach (['07:47:00', '12:03:00', '12:49:00', '17:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $employee->id, 'emp_no' => $employee->EmpNo, 'logdate' => $date, 'logtime' => $time,
            ]);
        }
        app(PersonnelLogImportService::class)->recomputeDtr($employee, $date, $date);

        $dtr = Dtr::where('employee_id', $employee->id)->whereDate('date', $date)->firstOrFail();
        $this->assertNotEmpty($dtr->unmatched_logs, 'Sanity check: the scenario must actually produce unmatched punches.');

        $response = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data', [
            'unmatched_from' => $date, 'unmatched_to' => $date,
        ]));

        $response->assertOk();
        $this->assertStringNotContainsString('Explainedbylocator', $response->json('html'));
    }

    /**
     * A day with one Locator-explained punch AND one genuinely stray punch
     * (23:58 - outside every plausible event window) must still appear: the
     * unexplained straggler is a real anomaly worth review, even though part
     * of the same row's unmatched_logs is already accounted for elsewhere.
     */
    public function test_row_only_partially_explained_by_a_locator_conflict_still_appears(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee(['last_name' => 'Partiallyexplained']);
        $date = '2026-08-19';
        $this->assignOrdinaryShift($employee, $date);

        Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Personal',
            'location' => 'Test location',
            'travel_date' => $date,
            'intended_departure_time' => '11:00:00',
            'intended_arrival_time' => '13:00:00',
            'detail' => 'Test errand',
            'status' => 'approved',
        ]);

        foreach (['07:47:00', '12:03:00', '12:49:00', '17:00:00', '23:58:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $employee->id, 'emp_no' => $employee->EmpNo, 'logdate' => $date, 'logtime' => $time,
            ]);
        }
        app(PersonnelLogImportService::class)->recomputeDtr($employee, $date, $date);

        $dtr = Dtr::where('employee_id', $employee->id)->whereDate('date', $date)->firstOrFail();
        $this->assertContains('23:58:00', $dtr->unmatched_logs, 'Sanity check: the stray punch must remain genuinely unmatched.');

        $response = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data', [
            'unmatched_from' => $date, 'unmatched_to' => $date,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('Partiallyexplained', $response->json('html'));
    }

    /**
     * The search bar matches the "Check Raw Biometric Feed" tool's own
     * name-or-EmpNo convention already on this same page, rather than
     * DtrExcuseController's name-only search elsewhere in the app - EmpNo is
     * a visible column in this table.
     */
    public function test_search_filters_by_name_or_empno(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $target = $this->createEmployee(['last_name' => 'Findme', 'first_name' => 'Target']);
        $other = $this->createEmployee(['last_name' => 'Different', 'first_name' => 'Other']);
        $date = '2026-08-19';

        foreach ([$target, $other] as $emp) {
            Dtr::create([
                'employee_id' => $emp->id,
                'date' => $date,
                'status' => 'incomplete',
                'unmatched_logs' => ['23:58:00'],
                'is_absent' => false,
            ]);
        }

        $byName = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data', [
            'unmatched_from' => $date, 'unmatched_to' => $date, 'unmatched_search' => 'Findme',
        ]));
        $byName->assertOk();
        $this->assertStringContainsString('Findme', $byName->json('html'));
        $this->assertStringNotContainsString('Different', $byName->json('html'));

        $byEmpNo = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data', [
            'unmatched_from' => $date, 'unmatched_to' => $date, 'unmatched_search' => $target->EmpNo,
        ]));
        $byEmpNo->assertOk();
        $this->assertStringContainsString('Findme', $byEmpNo->json('html'));
        $this->assertStringNotContainsString('Different', $byEmpNo->json('html'));
    }

    /**
     * Pagination replaces the old flat "first 300, narrow your range" cutoff
     * for a genuinely large but still-fully-fetched result set (well under
     * the 2000-row raw fetch cap): it's now paged through 25 at a time
     * instead of silently dropping anything beyond a fixed display count.
     * The separate fetch-cap honesty message (see the next test) must NOT
     * fire here - nothing was actually cut off at the raw-fetch level, only
     * split across pages.
     */
    public function test_results_are_paginated_25_per_page(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee();

        $start = Carbon::parse('2026-01-01');
        for ($i = 0; $i < 30; $i++) {
            Dtr::create([
                'employee_id' => $employee->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'status' => 'incomplete',
                'unmatched_logs' => ['23:58:00'],
                'is_absent' => false,
            ]);
        }

        $page1 = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data', [
            'unmatched_from' => $start->toDateString(),
            'unmatched_to' => $start->copy()->addDays(29)->toDateString(),
        ]));
        $page1->assertOk();
        $this->assertSame(30, $page1->json('badge_count'));
        $this->assertFalse($page1->json('badge_capped'));
        $this->assertStringNotContainsString('were checked', $page1->json('html'));
        $this->assertSame(25, substr_count($page1->json('html'), 'import-recompute-form'));
        $this->assertStringContainsString('page=2', $page1->json('html'));

        $page2 = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data', [
            'unmatched_from' => $start->toDateString(),
            'unmatched_to' => $start->copy()->addDays(29)->toDateString(),
            'page' => 2,
        ]));
        $page2->assertOk();
        $this->assertSame(5, substr_count($page2->json('html'), 'import-recompute-form'));
    }

    /**
     * Distinct from pagination above: this is the raw candidate fetch itself
     * (limit 2000, ordered by most recent date) being unable to pull in
     * every matching row - a real gap found in dev data (2,196 true matches
     * against the old 600-row cap). The message must reflect how many were
     * actually checked, not just repeat the true total with no context.
     * Seeded via a bulk raw insert (not Dtr::create() in a loop) since 2001+
     * rows through full Eloquent would make this test needlessly slow.
     */
    public function test_fetch_cap_message_fires_when_the_raw_fetch_itself_is_truncated(): void
    {
        $timeKeeper = $this->createTimeKeeper();
        $employee = $this->createEmployee();

        $start = Carbon::parse('2020-01-01');
        $rows = [];
        for ($i = 0; $i < 2001; $i++) {
            $rows[] = [
                'employee_id' => $employee->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'status' => 'incomplete',
                'unmatched_logs' => json_encode(['23:58:00']),
                'is_absent' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            \DB::table('dtrs')->insert($chunk);
        }

        $response = $this->actingAs($timeKeeper)->getJson(route('hr-manager.attendance.import.unmatched-data', [
            'unmatched_from' => $start->toDateString(),
            'unmatched_to' => $start->copy()->addDays(2000)->toDateString(),
        ]));

        $response->assertOk();
        $this->assertTrue($response->json('badge_capped'));
        $this->assertStringContainsString('Only the 2000 most recent of 2001 matching rows', $response->json('html'));
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
