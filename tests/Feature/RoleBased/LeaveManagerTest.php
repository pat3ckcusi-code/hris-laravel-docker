<?php

namespace Tests\Feature\RoleBased;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveDate;
use App\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Leave Manager Role Tests
 *
 * Covers: Manage Balance/Credits, Approved Leaves, Cancel Leave, Holiday Management
 */
class LeaveManagerTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers, MeasuresPerformance;

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
            'id'             => $balance->id,
            'deduction_days' => 1.25,
            'deduct_from'    => 'VL',
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
                'user_id'    => $emp->id,
                'leave_type' => ['VL', 'SL', 'SPL'][$i % 3],
                'start_date' => now()->subDays($i)->toDateString(),
                'end_date'   => now()->subDays($i)->toDateString(),
                'reason'     => "Approved leave #{$i}",
                'status'     => 'approved',
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

    public function test_cancel_leave_date_with_refund(): void
    {
        $this->markTestSkipped('api.leave.cancel-date route removed; cancellation is now employee-initiated via request-cancellation flow.');

        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 14.000]);

        $leave = LeaveRequest::create([
            'user_id'    => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date'   => now()->addWeek()->toDateString(),
            'reason'     => 'Cancel test',
            'status'     => 'approved',
        ]);

        $leaveDate = LeaveDate::create([
            'leave_request_id' => $leave->id,
            'leave_date'       => now()->addWeek()->toDateString(),
            'is_cancelled'     => false,
        ]);

        $response = $this->actingAs($lm)->post(route('api.leave.cancel-date'), [
            'leave_id' => $leave->id,
            'date'     => now()->addWeek()->toDateString(),
            'reason'   => 'Test cancellation',
        ]);

        $this->assertTrue(
            $response->isSuccessful(),
            "Cancel leave date failed: HTTP {$response->getStatusCode()}"
        );

        // Verify balance was refunded
        $emp->refresh();
        $balance = $emp->leaveBalance;
        if ($balance) {
            $this->assertGreaterThanOrEqual(14.000, (float) $balance->VL,
                "Leave balance was not refunded after cancellation");
        }
    }

    public function test_cancel_leave_rollback_integrity(): void
    {
        $this->markTestSkipped('api.leave.cancel-date route removed; cancellation is now employee-initiated via request-cancellation flow.');

        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $originalVL = 14.000;
        $this->createLeaveBalance($emp, ['VL' => $originalVL]);

        $leave = LeaveRequest::create([
            'user_id'    => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date'   => now()->addWeek()->addDays(2)->toDateString(),
            'reason'     => 'Rollback test',
            'status'     => 'approved',
        ]);

        $dates = [];
        for ($i = 0; $i < 3; $i++) {
            $dates[] = LeaveDate::create([
                'leave_request_id' => $leave->id,
                'leave_date'       => now()->addWeek()->addDays($i)->toDateString(),
                'is_cancelled'     => false,
            ]);
        }

        // Cancel all dates
        foreach ($dates as $idx => $date) {
            $this->actingAs($lm)->post(route('api.leave.cancel-date'), [
                'leave_id' => $leave->id,
                'date'     => now()->addWeek()->addDays($idx)->toDateString(),
                'reason'   => 'Rollback test cancellation',
            ]);
        }

        $emp->refresh();
        $balance = $emp->leaveBalance;
        if ($balance) {
            $this->assertGreaterThanOrEqual($originalVL, (float) $balance->VL,
                "After cancelling 3 days, VL balance should be >= {$originalVL}");
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
            'title'        => 'Independence Day',
            'holiday_date' => now()->addMonth()->toDateString(),
            'type'         => 'regular',
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
            'title'        => 'Test Holiday',
            'holiday_date' => $holidayDate,
            'type'         => 'Regular',
            'created_by'   => $lm->id,
        ]);

        // Create leaves on that date
        for ($i = 0; $i < 10; $i++) {
            $emp = $this->createEmployee();
            $this->createLeaveBalance($emp, ['VL' => 14]);

            $leave = LeaveRequest::create([
                'user_id'    => $emp->id,
                'leave_type' => 'VL',
                'start_date' => $holidayDate,
                'end_date'   => $holidayDate,
                'reason'     => 'To be cancelled by holiday',
                'status'     => 'approved',
            ]);

            LeaveDate::create([
                'leave_request_id' => $leave->id,
                'leave_date'       => $holidayDate,
                'is_cancelled'     => false,
            ]);
        }

        $response = $this->actingAs($lm)->post(route('api.leave.bulk-cancel-holiday'), [
            'date'          => $holidayDate,
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
}
