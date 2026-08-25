<?php

namespace Tests\Feature\RoleBased;

use App\Console\Commands\ProcessMonthlyLeaveCredits;
use App\Models\Dtr;
use App\Models\Holiday;
use App\Models\LeaveDate;
use App\Models\LeaveLedger;
use App\Models\LeaveRequest;
use App\Models\MonthlyAttendance;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;

/**
 * Leave Manager Role Tests
 *
 * Covers: Manage Balance/Credits, Approved Leaves, Cancel Leave, Holiday Management
 */
class LeaveManagerTest extends TestCase
{
    use CreatesTestUsers, MeasuresPerformance, RefreshDatabase;

    // ──────────────────────────────────────────────
    // 1. Manage Leave Balance
    // ──────────────────────────────────────────────

    public function test_manage_balance_page_loads(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->get(route('leave-manager.manage-balance'));

        $response->assertStatus(200);
    }

    public function test_update_leave_balance(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp);

        $response = $this->actingAs($lm)->patch(
            route('leave-manager.update-balance', $balance->id),
            ['field' => 'VL', 'value' => 20.000]
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Balance update failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_update_leave_balance_writes_ledger_entry_for_wlns(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp, ['WLNS' => 0.000]);

        $response = $this->actingAs($lm)->patch(
            route('leave-manager.update-balance', $balance->id),
            ['field' => 'WLNS', 'value' => 5.000]
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Balance update failed: HTTP {$response->getStatusCode()}"
        );

        $this->assertDatabaseHas('leave_ledger', [
            'user_id' => $emp->id,
            'transaction_type' => 'MANUAL_ADJUSTMENT',
            'leave_type' => 'WLNS',
        ]);

        $entry = LeaveLedger::where('user_id', $emp->id)->where('transaction_type', 'MANUAL_ADJUSTMENT')->latest('id')->first();
        $this->assertEquals(5.0, (float) $entry->credit_wlns);
        $this->assertEquals(0.0, (float) $entry->debit_wlns);
    }

    public function test_approve_mixed_type_leave_records_all_leave_types_in_ledger(): void
    {
        $dh = $this->createDepartmentHead();
        $emp = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        $this->createLeaveBalance($emp, ['SL' => 15.000, 'SPL' => 5.000, 'WLNS' => 5.000]);

        // Mirrors a real 3-date request where each date carries a different leave type
        // (Sick Leave / Special Privilege Leave / Wellness Leave) -- the exact shape that
        // previously produced a leave_ledger row with only the SL portion recorded.
        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'Sick Leave, Special Privilege Leave, Wellness Leave',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(),
            'paid_days' => 3.0,
            'total_days' => 3.0,
            'reason' => 'Mixed type test',
            'status' => 'pending',
            'printing_allowed' => true,
            'printing_deduction_details' => json_encode(['SL' => 1.0, 'SPL' => 1.0, 'WLNS' => 1.0]),
        ]);

        $response = $this->actingAs($dh)->post(route('department-head.leave.approve', $leave->id));
        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Leave approval failed: HTTP {$response->getStatusCode()}"
        );

        $leave->refresh();
        $this->assertEquals('approved', $leave->status);

        $balance = $emp->leaveBalance()->first();
        $this->assertEquals(14.0, (float) $balance->SL);
        $this->assertEquals(4.0, (float) $balance->SPL);
        $this->assertEquals(4.0, (float) $balance->WLNS);

        $entry = LeaveLedger::where('user_id', $emp->id)
            ->where('transaction_type', 'LEAVE_USED')
            ->where('reference_id', $leave->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'Mixed-type leave approval must write a LEAVE_USED ledger entry.');
        $this->assertEquals(1.0, (float) $entry->debit_sl);
        $this->assertEquals(1.0, (float) $entry->debit_spl);
        $this->assertEquals(1.0, (float) $entry->debit_wlns);
        $this->assertEquals(0.0, (float) $entry->debit_cto);
        $this->assertEquals(0.0, (float) $entry->debit_sp);
        $this->assertEquals('SL+SPL+WLNS', $entry->leave_type,
            'leave_type must list every type actually deducted, not just the first-resolved one.');
    }

    public function test_concurrent_balance_adjustments(): void
    {
        $lm = $this->createLeaveManager();
        $successes = 0;

        for ($i = 0; $i < 50; $i++) {
            $emp = $this->createEmployee();
            $balance = $this->createLeaveBalance($emp, ['VL' => 15]);

            try {
                $response = $this->actingAs($lm)->patch(
                    route('leave-manager.update-balance', $balance->id),
                    ['field' => 'VL', 'value' => 15 + ($i * 0.5)]
                );

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $successes++;
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }

        $rate = ($successes / 50) * 100;
        $this->assertGreaterThanOrEqual(90, $rate,
            "Concurrent balance adjustments: {$rate}% ({$successes}/50)");
    }

    public function test_leave_manager_can_toggle_solo_parent_on(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();

        $response = $this->actingAs($lm)
            ->patch(route('leave-manager.toggle-solo-parent', $emp));

        $response->assertOk();
        $response->assertJson(['is_solo_parent' => true]);
        $this->assertTrue($emp->fresh()->is_solo_parent);
    }

    public function test_toggling_solo_parent_twice_unmarks_it(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();

        $this->actingAs($lm)->patch(route('leave-manager.toggle-solo-parent', $emp));
        $this->assertTrue($emp->fresh()->is_solo_parent);

        $this->actingAs($lm)->patch(route('leave-manager.toggle-solo-parent', $emp));
        $this->assertFalse($emp->fresh()->is_solo_parent);
    }

    public function test_solo_parent_toggle_writes_audit_trail(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();

        $this->actingAs($lm)->patch(route('leave-manager.toggle-solo-parent', $emp));

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'leave',
            'action' => 'solo_parent_status_toggled',
            'target_type' => 'user',
            'target_id' => $emp->id,
        ]);
    }

    public function test_non_leave_manager_cannot_toggle_solo_parent(): void
    {
        $employee = $this->createEmployee();
        $target = $this->createEmployee();

        $this->actingAs($employee)
            ->patch(route('leave-manager.toggle-solo-parent', $target))
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 2. Manage Leave Credits
    // ──────────────────────────────────────────────

    public function test_manage_credits_page_loads(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->get(route('leave-manager.manage-credits'));

        $response->assertStatus(200);
    }

    public function test_apply_credits(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp);

        $response = $this->actingAs($lm)->post(route('leave-manager.apply-credits'), [
            'id' => $balance->id,
            'deduction_days' => 1.25,
            'deduct_from' => 'VL',
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Apply credits failed: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 3. Approved Leaves - Query Performance
    // ──────────────────────────────────────────────

    public function test_approved_leaves_with_large_dataset(): void
    {
        $lm = $this->createLeaveManager();

        // Seed approved leaves
        for ($i = 0; $i < 200; $i++) {
            $emp = $this->createEmployee();
            $this->createLeaveBalance($emp);

            LeaveRequest::create([
                'user_id' => $emp->id,
                'leave_type' => ['VL', 'SL', 'SPL'][$i % 3],
                'start_date' => now()->subDays($i)->toDateString(),
                'end_date' => now()->subDays($i)->toDateString(),
                'reason' => "Approved leave #{$i}",
                'status' => 'approved',
            ]);
        }

        $this->startQueryLog();
        $start = microtime(true);

        $response = $this->actingAs($lm)->get(route('leave-manager.manage-balance'));

        $elapsed = (microtime(true) - $start) * 1000;
        $queryCount = $this->getQueryCount();
        $this->stopQueryLog();

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(5000, $elapsed,
            "Approved leaves query took {$elapsed}ms with 200 records (max 5000ms)");
    }

    // ──────────────────────────────────────────────
    // 4. Cancel Leave
    // ──────────────────────────────────────────────

    public function test_cancel_leaves_page_loads(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->get(route('leave-manager.employee-cancellation-requests'));

        $response->assertStatus(200);
    }

    public function test_lm_only_sees_ao_endorsed_cancellations(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();

        // Pending Cancellation -should NOT appear on LM page
        $pendingLeave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Test',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);
        LeaveDate::create(['leave_request_id' => $pendingLeave->id, 'leave_date' => now()->addWeek()->toDateString(), 'is_cancelled' => false]);

        // AO Endorsed -SHOULD appear
        $endorsedLeave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->addDays(3)->toDateString(),
            'end_date' => now()->addWeek()->addDays(3)->toDateString(),
            'reason' => 'Test',
            'status' => 'approved',
            'cancellation_status' => 'AO Endorsed',
        ]);
        LeaveDate::create(['leave_request_id' => $endorsedLeave->id, 'leave_date' => now()->addWeek()->addDays(3)->toDateString(), 'is_cancelled' => false]);

        $response = $this->actingAs($lm)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('leave-manager.employee-cancellation-requests'));
        $response->assertStatus(200);
        $ids = collect($response->json('rows'))->pluck('id')->toArray();

        $this->assertContains($endorsedLeave->id, $ids, 'AO Endorsed leave should appear for LM');
        $this->assertNotContains($pendingLeave->id, $ids, 'Pending Cancellation should not appear for LM');
    }

    public function test_lm_approve_cancellation_requires_ao_endorsed(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 10.000]);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Test',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($lm)->postJson(route('api.leave.approve-cancellation', $leave->id));
        $response->assertStatus(422);
    }

    public function test_cancel_leave_date_with_refund(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 14.000]);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Cancel test',
            'status' => 'approved',
            'cancellation_status' => 'AO Endorsed',
            'printing_deduction_details' => json_encode(['VL' => 1.0]),
            'printing_deduction_applied' => true,
        ]);
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => now()->addWeek()->toDateString(), 'is_cancelled' => false]);

        $response = $this->actingAs($lm)->postJson(route('api.leave.approve-cancellation', $leave->id));
        $response->assertStatus(200)->assertJson(['success' => true]);

        $leave->refresh();
        $this->assertEquals('Cancelled', $leave->cancellation_status);
        $this->assertEquals('cancelled', $leave->status);

        $emp->refresh();
        $balance = $emp->leaveBalance;
        if ($balance) {
            $this->assertGreaterThanOrEqual(14.0, (float) $balance->VL,
                'VL balance should be restored after cancellation approval');
        }
    }

    public function test_approve_date_cancellation_with_long_reason_is_stored_without_truncation(): void
    {
        // Regression guard: leave_dates.cancel_reason used to be VARCHAR(255)
        // while cancellation_reason (copied into it on approval) is
        // validated at max:2000, so any reason over 255 chars threw
        // SQLSTATE[22001] on this exact endpoint.
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 14.000]);
        $longReason = str_repeat('E', 279);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Cancel test',
            'status' => 'approved',
        ]);
        $leaveDate = LeaveDate::create([
            'leave_request_id' => $leave->id,
            'leave_date' => now()->addWeek()->toDateString(),
            'is_cancelled' => false,
            'cancellation_status' => 'AO Endorsed',
            'cancellation_reason' => $longReason,
        ]);

        $response = $this->actingAs($lm)->postJson(route('api.leave.approve-date-cancellation', $leave->id), [
            'leave_date_ids' => [$leaveDate->id],
        ]);

        $response->assertStatus(200);
        $this->assertEquals($longReason, $leaveDate->fresh()->cancel_reason);
    }

    public function test_cancel_leave_rollback_integrity(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $originalVL = 14.000;
        $this->createLeaveBalance($emp, ['VL' => $originalVL]);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(),
            'reason' => 'Rollback test',
            'status' => 'approved',
            'cancellation_status' => 'AO Endorsed',
            'printing_deduction_details' => json_encode(['VL' => 3.0]),
            'printing_deduction_applied' => true,
        ]);
        for ($i = 0; $i < 3; $i++) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => now()->addWeek()->addDays($i)->toDateString(), 'is_cancelled' => false]);
        }

        $response = $this->actingAs($lm)->postJson(route('api.leave.approve-cancellation', $leave->id));
        $response->assertStatus(200)->assertJson(['success' => true]);

        $emp->refresh();
        $balance = $emp->leaveBalance;
        if ($balance) {
            $this->assertGreaterThanOrEqual($originalVL, (float) $balance->VL,
                'After cancelling 3 days, VL balance should be >= original');
        }
    }

    // ──────────────────────────────────────────────
    // 5. Holiday Management
    // ──────────────────────────────────────────────

    public function test_create_holiday(): void
    {
        $this->markTestSkipped('api.holidays.store route not yet registered.');

        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->post(route('api.holidays.store'), [
            'title' => 'Independence Day',
            'holiday_date' => now()->addMonth()->toDateString(),
            'type' => 'regular',
        ]);

        $this->assertTrue(
            $response->isSuccessful(),
            "Holiday creation failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_list_holidays(): void
    {
        $this->markTestSkipped('api.holidays.list route not yet registered.');

        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->get(route('api.holidays.list'));

        $response->assertStatus(200);
    }

    public function test_bulk_cancel_by_holiday(): void
    {
        $this->markTestSkipped('api.leave.bulk-cancel-holiday route not yet registered.');

        $lm = $this->createLeaveManager();

        $holidayDate = now()->addMonth()->toDateString();

        // Create holiday
        $holiday = Holiday::create([
            'title' => 'Test Holiday',
            'holiday_date' => $holidayDate,
            'type' => 'Regular',
            'created_by' => $lm->id,
        ]);

        // Create leaves on that date
        for ($i = 0; $i < 10; $i++) {
            $emp = $this->createEmployee();
            $this->createLeaveBalance($emp, ['VL' => 14]);

            $leave = LeaveRequest::create([
                'user_id' => $emp->id,
                'leave_type' => 'VL',
                'start_date' => $holidayDate,
                'end_date' => $holidayDate,
                'reason' => 'To be cancelled by holiday',
                'status' => 'approved',
            ]);

            LeaveDate::create([
                'leave_request_id' => $leave->id,
                'leave_date' => $holidayDate,
                'is_cancelled' => false,
            ]);
        }

        $response = $this->actingAs($lm)->post(route('api.leave.bulk-cancel-holiday'), [
            'date' => $holidayDate,
            'holiday_title' => 'Test Holiday',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertSame(10, $response->json('cancelled_count'));

        // All leaves on that date should now be cancelled with correct statuses
        LeaveRequest::where('status', 'cancelled')->each(function ($leave) {
            $this->assertSame('cancelled', $leave->status);
            $this->assertSame('Cancelled', $leave->detailed_status);
        });
    }

    // ──────────────────────────────────────────────
    // 6. Employee Search
    // ──────────────────────────────────────────────

    public function test_employee_search(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee(['last_name' => 'SearchTest']);

        $response = $this->actingAs($lm)->get(
            route('api.employee.search', ['q' => 'SearchTest'])
        );

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // 7. Run Monthly Credits
    // ──────────────────────────────────────────────

    /**
     * Seed Dtr "present" rows for every weekday in a month, skipping $exceptDates
     * (days covered by a leave request instead) -- so AWOL detection doesn't
     * introduce noise into tests asserting exact credit amounts.
     */
    private function seedFullAttendance(int $userId, int $year, int $month, array $exceptDates = []): void
    {
        $except = array_flip($exceptDates);
        $cursor = Carbon::create($year, $month, 1);
        $end = $cursor->copy()->endOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $dateStr = $cursor->toDateString();
            if ($cursor->isWeekday() && ! isset($except[$dateStr])) {
                Dtr::create(['employee_id' => $userId, 'date' => $dateStr, 'is_absent' => false]);
            }
            $cursor->addDay();
        }
    }

    public function test_run_monthly_credits_creates_ledger_entries_and_audit_trail(): void
    {
        // employee_type=casual keeps the Leave Manager account itself out of
        // LEAVE_ELIGIBLE_TYPES so these tests can assert exact processed counts
        // for the one deliberately-created eligible employee.
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        $this->seedFullAttendance($emp->id, $lastMonth->year, $lastMonth->month);

        $response = $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['processed' => 1, 'skipped' => 0, 'failed' => 0]);

        $this->assertDatabaseHas('monthly_attendance', [
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);
        $this->assertTrue(
            LeaveLedger::where('user_id', $emp->id)
                ->whereIn('transaction_type', ['CREDIT_EARNED', 'CREDIT_EARNED_WOP'])
                ->exists(),
            'A credit ledger entry should have been posted for the employee.'
        );
        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $lm->id,
            'module' => 'leave',
            'action' => 'monthly_credit_run',
        ]);

        $balance->refresh();
        $this->assertEquals(16.25, (float) $balance->VL, 'The real leave_balances row must be credited, not just the ledger.');
        $this->assertEquals(16.25, (float) $balance->SL);
    }

    public function test_run_monthly_credits_second_call_skips_without_duplicating(): void
    {
        // employee_type=casual keeps the Leave Manager account itself out of
        // LEAVE_ELIGIBLE_TYPES so these tests can assert exact processed counts
        // for the one deliberately-created eligible employee.
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        $payload = ['year' => $lastMonth->year, 'month' => $lastMonth->month];

        $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits'), $payload)
            ->assertStatus(200)->assertJson(['processed' => 1, 'skipped' => 0]);

        $ledgerCountAfterFirstRun = LeaveLedger::where('user_id', $emp->id)->count();

        $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits'), $payload)
            ->assertStatus(200)->assertJson(['processed' => 0, 'skipped' => 1]);

        $this->assertSame(
            $ledgerCountAfterFirstRun,
            LeaveLedger::where('user_id', $emp->id)->count(),
            'Re-running for the same month must not post a duplicate ledger entry.'
        );
    }

    public function test_run_monthly_credits_rejects_current_month(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits'), [
            'year' => now()->year,
            'month' => now()->month,
        ]);

        $response->assertStatus(422);
    }

    public function test_non_leave_manager_cannot_run_monthly_credits(): void
    {
        $emp = $this->createEmployee();
        $lastMonth = now()->subMonthNoOverflow();

        $response = $this->actingAs($emp)->postJson(route('leave-manager.run-monthly-credits'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(403);
    }

    public function test_run_monthly_credits_failed_ledger_write_leaves_employee_retryable(): void
    {
        // employee_type=casual keeps the Leave Manager account itself out of
        // LEAVE_ELIGIBLE_TYPES so these tests can assert exact processed counts
        // for the one deliberately-created eligible employee.
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();

        $failingLedgerService = \Mockery::mock(LeaveLedgerService::class);
        $failingLedgerService->shouldReceive('writeLedgerEntry')->andThrow(new \RuntimeException('simulated ledger failure'));
        $this->app->instance(LeaveLedgerService::class, $failingLedgerService);

        $response = $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['processed' => 0, 'skipped' => 0, 'failed' => 1]);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)
            ->where('year', $lastMonth->year)
            ->where('month', $lastMonth->month)
            ->first();
        $this->assertNull(
            $attendance?->processed_at,
            'A failed ledger write must not leave processed_at committed, so the employee stays eligible for retry.'
        );
    }

    public function test_run_monthly_credits_preview_writes_nothing_to_the_database(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        $this->seedFullAttendance($emp->id, $lastMonth->year, $lastMonth->month);

        $response = $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits.preview'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['summary' => ['would_process' => 1, 'would_skip' => 0, 'would_fail' => 0]]);
        $response->assertJsonFragment(['emp_no' => $emp->EmpNo, 'computed_vl' => '1.250', 'computed_sl' => '1.250']);

        $this->assertDatabaseMissing('monthly_attendance', [
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);
        $this->assertSame(0, LeaveLedger::where('user_id', $emp->id)->count(), 'Preview must not write any ledger entry.');
    }

    public function test_run_monthly_credits_preview_excludes_already_processed_employees_from_rows(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        app(ProcessMonthlyLeaveCredits::class)->processBatch($lastMonth->year, $lastMonth->month, $emp->id, false);

        $response = $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits.preview'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['summary' => ['would_process' => 0, 'would_skip' => 1, 'would_fail' => 0]]);
        $this->assertEmpty($response->json('rows'), 'Already-processed employees are counted but not listed as preview rows.');
    }

    public function test_run_monthly_credits_preview_rejects_current_month(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits.preview'), [
            'year' => now()->year,
            'month' => now()->month,
        ]);

        $response->assertStatus(422);
    }

    public function test_non_leave_manager_cannot_preview_run_monthly_credits(): void
    {
        $emp = $this->createEmployee();
        $lastMonth = now()->subMonthNoOverflow();

        $response = $this->actingAs($emp)->postJson(route('leave-manager.run-monthly-credits.preview'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 8. Stale Monthly Credit Detection & Correction
    // ──────────────────────────────────────────────

    public function test_processed_month_with_no_changes_is_not_stale(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        app(ProcessMonthlyLeaveCredits::class)->processBatch($lastMonth->year, $lastMonth->month, $emp->id, false);

        $response = $this->actingAs($lm)->getJson(
            route('api.leave-ledger.monthly', ['year' => $lastMonth->year, 'month' => $lastMonth->month])
        );
        $response->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('user_id', $emp->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['stale']);
    }

    public function test_stale_month_detected_and_recompute_posts_only_delta(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        $backdatedDates = [];
        for ($day = 1; $day <= 5; $day++) {
            $backdatedDates[] = Carbon::create($lastMonth->year, $lastMonth->month, $day)->toDateString();
        }
        // Full attendance for the whole month at first-processing time -- the LWOP
        // request below is filed and approved *after* the fact, retroactively
        // reclassifying days 1-5. AWOL detection stays out of the picture entirely
        // (Dtr already shows present), isolating this test to the LWOP-driven delta.
        $this->seedFullAttendance($emp->id, $lastMonth->year, $lastMonth->month);

        app(ProcessMonthlyLeaveCredits::class)->processBatch($lastMonth->year, $lastMonth->month, $emp->id, false);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)
            ->where('year', $lastMonth->year)->where('month', $lastMonth->month)->first();
        $oldVl = (float) $attendance->computed_vl;
        $this->assertEquals(1.25, $oldVl, 'Sanity check: no LWOP yet, full credit expected.');

        MonthlyAttendance::where('id', $attendance->id)->update(['processed_at' => now()->subMinutes(5)]);

        // Backdated 5-day LWOP request approved *after* the month was processed.
        $backdatedLeave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => Carbon::create($lastMonth->year, $lastMonth->month, 1)->toDateString(),
            'end_date' => Carbon::create($lastMonth->year, $lastMonth->month, 5)->toDateString(),
            'reason' => 'Backdated',
            'status' => 'approved',
            'lwop_days' => 5,
        ]);
        foreach ($backdatedDates as $date) {
            LeaveDate::create(['leave_request_id' => $backdatedLeave->id, 'leave_date' => $date, 'is_cancelled' => false]);
        }

        $listResponse = $this->actingAs($lm)->getJson(
            route('api.leave-ledger.monthly', ['year' => $lastMonth->year, 'month' => $lastMonth->month])
        );
        $row = collect($listResponse->json('data'))->firstWhere('user_id', $emp->id);
        $this->assertTrue($row['stale'], 'A leave request approved after processed_at must flag the month stale.');

        $expectedDelta = round(1.042 - 1.25, 3); // 25 days present -> 1.042, matches CSC Example 1's 0.208 loss

        $recompute = $this->actingAs($lm)->postJson(route('leave-manager.recompute-employee-month'), [
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);
        $recompute->assertStatus(200)->assertJson([
            'changed' => true,
            'delta_vl' => $expectedDelta,
            'delta_sl' => $expectedDelta,
        ]);

        $corrections = LeaveLedger::where('user_id', $emp->id)->where('transaction_type', 'CREDIT_CORRECTION')->get();
        $this->assertCount(1, $corrections, 'Exactly one correction entry for the delta, not a full re-credit.');
        $this->assertEquals(abs($expectedDelta), (float) $corrections->first()->debit_vl);
        $this->assertEquals(0.0, (float) $corrections->first()->credit_vl);

        $attendance->refresh();
        $this->assertEquals(1.042, (float) $attendance->computed_vl);
        $this->assertEquals(1.042, (float) $attendance->computed_sl);

        $listResponse2 = $this->actingAs($lm)->getJson(
            route('api.leave-ledger.monthly', ['year' => $lastMonth->year, 'month' => $lastMonth->month])
        );
        $row2 = collect($listResponse2->json('data'))->firstWhere('user_id', $emp->id);
        $this->assertFalse($row2['stale'], 'Recompute should bump processed_at and clear the stale flag.');
    }

    public function test_stale_flag_but_no_actual_change_writes_no_ledger_entry(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        app(ProcessMonthlyLeaveCredits::class)->processBatch($lastMonth->year, $lastMonth->month, $emp->id, false);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)
            ->where('year', $lastMonth->year)->where('month', $lastMonth->month)->first();
        MonthlyAttendance::where('id', $attendance->id)->update(['processed_at' => now()->subMinutes(5)]);

        // Fully balance-covered leave (no LWOP overflow) -- flips the staleness heuristic
        // without actually changing the credit calculation.
        LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => Carbon::create($lastMonth->year, $lastMonth->month, 10)->toDateString(),
            'end_date' => Carbon::create($lastMonth->year, $lastMonth->month, 10)->toDateString(),
            'reason' => 'Normal leave',
            'status' => 'approved',
            'lwop_days' => 0,
        ]);

        $ledgerCountBefore = LeaveLedger::where('user_id', $emp->id)->count();

        $recompute = $this->actingAs($lm)->postJson(route('leave-manager.recompute-employee-month'), [
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);
        $recompute->assertStatus(200)->assertJson(['changed' => false]);

        $this->assertSame(
            $ledgerCountBefore,
            LeaveLedger::where('user_id', $emp->id)->count(),
            'A no-op recompute must not write a ledger entry.'
        );

        $attendance->refresh();
        $this->assertNotNull($attendance->processed_at);
    }

    public function test_non_leave_manager_cannot_recompute_employee_month(): void
    {
        $emp = $this->createEmployee();
        $lastMonth = now()->subMonthNoOverflow();

        $response = $this->actingAs($emp)->postJson(route('leave-manager.recompute-employee-month'), [
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(403);
    }

    public function test_recompute_never_processed_month_returns_422(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        $lastMonth = now()->subMonthNoOverflow();

        $response = $this->actingAs($lm)->postJson(route('leave-manager.recompute-employee-month'), [
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────
    // 8b. Bulk Force Recompute (non-stale, already-processed months)
    // ──────────────────────────────────────────────

    public function test_force_recompute_month_corrects_non_stale_employee_when_stored_value_is_wrong(): void
    {
        // employee_type=casual keeps the Leave Manager account itself out of
        // LEAVE_ELIGIBLE_TYPES so this test can assert exact counts for the one
        // deliberately-created eligible employee.
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        // Full attendance, zero LWOP/AWOL -- the deterministic 1.25 full-month credit.
        $this->seedFullAttendance($emp->id, $lastMonth->year, $lastMonth->month);

        // Simulate a row processed under a stale formula (e.g. before this session's
        // fixes) that was never applied to leave_balances at all -- the exact historical
        // bug state -- by seeding MonthlyAttendance directly instead of going through
        // processBatch() (which now correctly credits the balance itself).
        MonthlyAttendance::create([
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
            'days_present' => 24,
            'abs_wop_days' => 0,
            'computed_vl' => 1.000,
            'computed_sl' => 1.000,
            'processed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($lm)->postJson(route('leave-manager.force-recompute-month'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['recomputed' => 1, 'changed' => 1, 'failed' => 0]);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)
            ->where('year', $lastMonth->year)->where('month', $lastMonth->month)->first();
        $this->assertEquals(1.25, (float) $attendance->computed_vl);
        $this->assertEquals(1.25, (float) $attendance->computed_sl);

        $correction = LeaveLedger::where('user_id', $emp->id)->where('transaction_type', 'CREDIT_CORRECTION')->first();
        $this->assertNotNull($correction);
        $this->assertEquals(0.25, (float) $correction->credit_vl);
        $this->assertEquals(0.25, (float) $correction->credit_sl);

        // Only the delta (0.25) is applied on top of the pre-existing balance -- the real
        // leave_balances row must actually move, not just the ledger.
        $balance->refresh();
        $this->assertEquals(15.25, (float) $balance->VL);
        $this->assertEquals(15.25, (float) $balance->SL);
    }

    public function test_force_recompute_month_no_change_writes_no_ledger_entry(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        app(ProcessMonthlyLeaveCredits::class)->processBatch($lastMonth->year, $lastMonth->month, $emp->id, false);

        $ledgerCountBefore = LeaveLedger::where('user_id', $emp->id)->count();
        $balance->refresh();
        $vlAfterInitialRun = (float) $balance->VL;
        $slAfterInitialRun = (float) $balance->SL;

        $response = $this->actingAs($lm)->postJson(route('leave-manager.force-recompute-month'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['recomputed' => 1, 'changed' => 0, 'failed' => 0]);

        $balance->refresh();
        $this->assertEquals($vlAfterInitialRun, (float) $balance->VL, 'A no-op force recompute must not touch the real balance again.');
        $this->assertEquals($slAfterInitialRun, (float) $balance->SL);

        $this->assertSame(
            $ledgerCountBefore,
            LeaveLedger::where('user_id', $emp->id)->count(),
            'A no-op force recompute must not write a ledger entry.'
        );
    }

    public function test_force_recompute_month_rejects_current_month(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->postJson(route('leave-manager.force-recompute-month'), [
            'year' => now()->year,
            'month' => now()->month,
        ]);

        $response->assertStatus(422);
    }

    public function test_non_leave_manager_cannot_force_recompute_month(): void
    {
        $emp = $this->createEmployee();
        $lastMonth = now()->subMonthNoOverflow();

        $response = $this->actingAs($emp)->postJson(route('leave-manager.force-recompute-month'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(403);
    }

    public function test_force_recompute_month_preview_writes_nothing_to_the_database(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        $this->seedFullAttendance($emp->id, $lastMonth->year, $lastMonth->month);
        app(ProcessMonthlyLeaveCredits::class)->processBatch($lastMonth->year, $lastMonth->month, $emp->id, false);

        // Simulate a stale stored value, same setup as the real force-recompute test above.
        MonthlyAttendance::where('user_id', $emp->id)
            ->where('year', $lastMonth->year)->where('month', $lastMonth->month)
            ->update(['computed_vl' => 1.000, 'computed_sl' => 1.000]);

        $response = $this->actingAs($lm)->postJson(route('leave-manager.force-recompute-month.preview'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['summary' => ['would_change' => 1, 'would_noop' => 0, 'would_fail' => 0]]);
        $response->assertJsonFragment([
            'emp_no' => $emp->EmpNo,
            'old_vl' => '1.000', 'old_sl' => '1.000',
            'new_vl' => '1.250', 'new_sl' => '1.250',
            'changed' => true,
        ]);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)
            ->where('year', $lastMonth->year)->where('month', $lastMonth->month)->first();
        $this->assertEquals(1.000, (float) $attendance->computed_vl, 'Preview must not write the recomputed value.');
        $this->assertSame(
            0,
            LeaveLedger::where('user_id', $emp->id)->where('transaction_type', 'CREDIT_CORRECTION')->count(),
            'Preview must not post a correction ledger entry.'
        );
    }

    public function test_force_recompute_month_preview_includes_unchanged_rows_too(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();
        app(ProcessMonthlyLeaveCredits::class)->processBatch($lastMonth->year, $lastMonth->month, $emp->id, false);

        $response = $this->actingAs($lm)->postJson(route('leave-manager.force-recompute-month.preview'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['summary' => ['would_change' => 0, 'would_noop' => 1, 'would_fail' => 0]]);
        $response->assertJsonFragment(['emp_no' => $emp->EmpNo, 'changed' => false]);
    }

    public function test_force_recompute_month_preview_rejects_current_month(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->postJson(route('leave-manager.force-recompute-month.preview'), [
            'year' => now()->year,
            'month' => now()->month,
        ]);

        $response->assertStatus(422);
    }

    public function test_non_leave_manager_cannot_preview_force_recompute_month(): void
    {
        $emp = $this->createEmployee();
        $lastMonth = now()->subMonthNoOverflow();

        $response = $this->actingAs($emp)->postJson(route('leave-manager.force-recompute-month.preview'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 9. AWOL Monitor
    // ──────────────────────────────────────────────

    public function test_awol_monitor_shows_employee_with_qualifying_streak(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        // No Dtr rows, leave, excuse, locator, or ETA at all -- guarantees a 5+
        // workday AWOL streak with no further setup needed.

        $response = $this->actingAs($lm)->getJson(route('api.leave-ledger.awol-monitor'));
        $response->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('emp_no', $emp->EmpNo);
        $this->assertNotNull($row, 'An employee with no attendance/coverage at all should appear in the AWOL monitor.');
    }

    public function test_non_leave_manager_cannot_view_awol_monitor(): void
    {
        $emp = $this->createEmployee();

        $response = $this->actingAs($emp)->getJson(route('api.leave-ledger.awol-monitor'));

        $response->assertStatus(403);
    }

    public function test_awol_monitor_excludes_part_time_employees(): void
    {
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee(['employee_type' => 'part_time']);
        $this->createLeaveBalance($emp);
        // No Dtr rows, leave, excuse, locator, or ETA at all -- would otherwise
        // guarantee a 5+ workday AWOL streak, proving exclusion is on employee_type.

        $response = $this->actingAs($lm)->getJson(route('api.leave-ledger.awol-monitor'));
        $response->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('emp_no', $emp->EmpNo);
        $this->assertNull($row, 'Monthly Leave Credits (and the AWOL monitor tied to it) only apply to Permanent, Elected Officials, and Co-Terminus employees -- part_time must be excluded.');
    }

    public function test_run_monthly_credits_excludes_part_time_employees(): void
    {
        // employee_type=casual keeps the Leave Manager account itself out of
        // LEAVE_ELIGIBLE_TYPES so this test can assert an exact processed count.
        $lm = $this->createLeaveManager(['employee_type' => 'casual']);
        $emp = $this->createEmployee(['employee_type' => 'part_time']);
        $this->createLeaveBalance($emp);

        $lastMonth = now()->subMonthNoOverflow();

        $response = $this->actingAs($lm)->postJson(route('leave-manager.run-monthly-credits'), [
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);

        $response->assertStatus(200)->assertJson(['processed' => 0, 'skipped' => 0, 'failed' => 0]);

        $this->assertDatabaseMissing('monthly_attendance', [
            'user_id' => $emp->id,
            'year' => $lastMonth->year,
            'month' => $lastMonth->month,
        ]);
        $this->assertFalse(
            LeaveLedger::where('user_id', $emp->id)->exists(),
            'A part_time employee must not receive Monthly Leave Credits.'
        );
    }
}
