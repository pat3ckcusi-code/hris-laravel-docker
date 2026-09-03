<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceAdjustmentSubmission;
use App\Models\AttendanceAdjustmentSubmissionItem;
use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveLedger;
use App\Models\User;
use App\Services\AttendanceAdjustmentSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Leave Manager screen that consumes AttendanceAdjustmentSubmission(Item)
 * snapshots forwarded by AttendanceAdjustmentSummaryController (Timekeeper/HR
 * Manager side) and applies/dismisses the VL deduction those submissions
 * exist to justify - see AttendanceAdjustmentSummaryTest for the producer
 * side.
 */
class AttendanceAdjustmentReviewTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeItem(User $employee, array $overrides = []): AttendanceAdjustmentSubmissionItem
    {
        $submission = AttendanceAdjustmentSubmission::create([
            'submitted_by' => $this->createTimeKeeper()->id,
            'month' => 6,
            'year' => 2026,
            'department_ids' => [],
            'item_count' => 1,
            'skipped_count' => 0,
            'status' => 'submitted',
        ]);

        return AttendanceAdjustmentSubmissionItem::create(array_merge([
            'submission_id' => $submission->id,
            'user_id' => $employee->id,
            'month' => 6,
            'year' => 2026,
            'emp_no' => $employee->EmpNo,
            'name' => trim(($employee->last_name ?? '').', '.($employee->first_name ?? '')),
            'department' => 'Dept A',
            'unfiled_count' => 0,
            'tardiness_count' => 0,
            'tardiness_minutes' => 0,
            'undertime_count' => 0,
            'undertime_minutes' => 0,
        ], $overrides));
    }

    public function test_leave_manager_can_view_index(): void
    {
        $this->actingAs($this->createLeaveManager())
            ->get(route('leave-manager.attendance-deductions'))
            ->assertStatus(200);
    }

    public function test_time_keeper_cannot_view_index(): void
    {
        $this->actingAs($this->createTimeKeeper())
            ->get(route('leave-manager.attendance-deductions'))
            ->assertStatus(403);
    }

    public function test_employee_cannot_view_index(): void
    {
        $this->actingAs($this->createEmployee())
            ->get(route('leave-manager.attendance-deductions'))
            ->assertStatus(403);
    }

    public function test_deduct_applies_computed_vl_amount_and_writes_ledger_entry(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);
        // 2 unfiled days + (240 + 240) minutes / 480 = 2 + 1 = 3 VL days.
        $item = $this->makeItem($employee, ['unfiled_count' => 2, 'tardiness_minutes' => 240, 'undertime_minutes' => 240]);

        $response = $this->actingAs($this->createLeaveManager())
            ->postJson(route('api.leave-manager.attendance-deductions.deduct', $item));

        $response->assertStatus(200)->assertJson(['success' => true, 'deducted_days' => 3.0]);

        $this->assertEquals(12.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);

        $item->refresh();
        $this->assertSame('processed', $item->processed_status);
        $this->assertEquals(3.0, $item->deducted_days);
        $this->assertNotNull($item->processed_at);

        $this->assertTrue(
            LeaveLedger::where('user_id', $employee->id)
                ->where('transaction_type', 'ATTENDANCE_DEDUCTION')
                ->where('leave_type', 'VL')
                ->where('debit_vl', 3.0)
                ->exists()
        );

        $this->assertTrue(
            HRAuditTrail::where('module', 'leave')
                ->where('action', 'attendance_deficiency_vl_deducted')
                ->where('target_id', $item->id)
                ->exists()
        );
    }

    public function test_deduct_fails_with_insufficient_balance_and_item_stays_pending(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 1]);
        $item = $this->makeItem($employee, ['unfiled_count' => 5]);

        $response = $this->actingAs($this->createLeaveManager())
            ->postJson(route('api.leave-manager.attendance-deductions.deduct', $item));

        $response->assertStatus(422)->assertJsonFragment(['error' => 'Insufficient VL balance.']);

        $this->assertEquals(1.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);
        $this->assertSame('pending', $item->fresh()->processed_status);
    }

    public function test_deduct_rejects_an_already_processed_item(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);
        $item = $this->makeItem($employee, ['unfiled_count' => 1]);

        $leaveManager = $this->createLeaveManager();
        $this->actingAs($leaveManager)
            ->postJson(route('api.leave-manager.attendance-deductions.deduct', $item))
            ->assertStatus(200);

        $second = $this->actingAs($leaveManager)
            ->postJson(route('api.leave-manager.attendance-deductions.deduct', $item));

        $second->assertStatus(422)->assertJsonFragment(['error' => 'This item has already been processed.']);
    }

    /**
     * The outer `processed_status !== 'pending'` guard in
     * AttendanceAdjustmentSummaryService::deductForItem() runs BEFORE the
     * DB transaction/lock even starts, so it can't by itself close the
     * window where two concurrent calls (a UI double-click, or a
     * single-item deduct racing bulkDeduct() over the same item) each read
     * the item while it's still 'pending' and both pass that check. Simulate
     * that race directly: two independently-loaded Eloquent instances of the
     * SAME row, both still holding processed_status = 'pending' in memory -
     * exactly what two concurrent requests would each see before either one
     * writes back. Only a lock-and-recheck against a FRESH read inside the
     * transaction (what the fix adds) can catch the second one.
     */
    public function test_deduct_rejects_a_concurrent_double_deduct_even_with_a_stale_pending_item_instance(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);
        $item = $this->makeItem($employee, ['unfiled_count' => 1]);

        $staleInstanceA = AttendanceAdjustmentSubmissionItem::find($item->id);
        $staleInstanceB = AttendanceAdjustmentSubmissionItem::find($item->id);
        $this->assertSame('pending', $staleInstanceA->processed_status);
        $this->assertSame('pending', $staleInstanceB->processed_status);

        $leaveManager = $this->createLeaveManager();
        $service = app(AttendanceAdjustmentSummaryService::class);

        $service->deductForItem($staleInstanceA, $leaveManager);

        $threw = false;
        try {
            $service->deductForItem($staleInstanceB, $leaveManager);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('This item has already been processed.', $e->getMessage());
        }

        $this->assertTrue($threw, 'The second, concurrently-loaded call must be rejected instead of double-deducting.');
        $this->assertEquals(14.0, LeaveBalance::where('user_id', $employee->id)->first()->VL, 'VL must be deducted exactly once, not twice.');
        $this->assertSame(
            1,
            LeaveLedger::where('user_id', $employee->id)->where('transaction_type', 'ATTENDANCE_DEDUCTION')->count(),
            'Only one ledger entry must be written despite two concurrent attempts.'
        );
    }

    /** Same TOCTOU window as the deduct race above, applied to dismissItem() for consistency. */
    public function test_dismiss_rejects_a_concurrent_double_dismiss_even_with_a_stale_pending_item_instance(): void
    {
        $employee = $this->createEmployee();
        $item = $this->makeItem($employee, ['unfiled_count' => 1]);

        $staleInstanceA = AttendanceAdjustmentSubmissionItem::find($item->id);
        $staleInstanceB = AttendanceAdjustmentSubmissionItem::find($item->id);

        $leaveManager = $this->createLeaveManager();
        $service = app(AttendanceAdjustmentSummaryService::class);

        $service->dismissItem($staleInstanceA, 'First dismiss.', $leaveManager);

        $threw = false;
        try {
            $service->dismissItem($staleInstanceB, 'Second, concurrent dismiss.', $leaveManager);
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'The second, concurrently-loaded dismiss must be rejected.');
        $this->assertSame('First dismiss.', $item->fresh()->action_remarks);
    }

    public function test_dismiss_requires_remarks(): void
    {
        $employee = $this->createEmployee();
        $item = $this->makeItem($employee, ['unfiled_count' => 1]);

        $this->actingAs($this->createLeaveManager())
            ->postJson(route('api.leave-manager.attendance-deductions.dismiss', $item), [])
            ->assertStatus(422);

        $this->assertSame('pending', $item->fresh()->processed_status);
    }

    public function test_dismiss_marks_item_dismissed_with_no_balance_change(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);
        $item = $this->makeItem($employee, ['unfiled_count' => 1]);

        $response = $this->actingAs($this->createLeaveManager())
            ->postJson(route('api.leave-manager.attendance-deductions.dismiss', $item), ['remarks' => 'Already excused via ETA.']);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $item->refresh();
        $this->assertSame('dismissed', $item->processed_status);
        $this->assertSame('Already excused via ETA.', $item->action_remarks);
        $this->assertEquals(15.0, LeaveBalance::where('user_id', $employee->id)->first()->VL);
    }

    public function test_bulk_deduct_processes_valid_items_and_reports_errors_for_insufficient_balance(): void
    {
        $ok = $this->createEmployee(['last_name' => 'Sufficient']);
        $this->createLeaveBalance($ok, ['VL' => 15]);
        $okItem = $this->makeItem($ok, ['unfiled_count' => 1]);

        $short = $this->createEmployee(['last_name' => 'Short']);
        $this->createLeaveBalance($short, ['VL' => 0]);
        $shortItem = $this->makeItem($short, ['unfiled_count' => 1]);

        $response = $this->actingAs($this->createLeaveManager())
            ->postJson(route('api.leave-manager.attendance-deductions.bulk-deduct'), [
                'item_ids' => [$okItem->id, $shortItem->id],
            ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'processed_count' => 1]);
        $this->assertCount(1, $response->json('errors'));

        $this->assertSame('processed', $okItem->fresh()->processed_status);
        $this->assertSame('pending', $shortItem->fresh()->processed_status);
    }

    public function test_pending_list_excludes_already_processed_items(): void
    {
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 15]);
        $item = $this->makeItem($employee, ['unfiled_count' => 1]);

        $leaveManager = $this->createLeaveManager();
        $this->actingAs($leaveManager)->postJson(route('api.leave-manager.attendance-deductions.deduct', $item));

        $response = $this->actingAs($leaveManager)->get(route('leave-manager.attendance-deductions', ['month' => 6, 'year' => 2026]));

        $response->assertStatus(200);
        $response->assertDontSee($item->emp_no);
    }

    public function test_issue_filter_scopes_to_the_selected_deficiency_type(): void
    {
        $unfiledOnly = $this->createEmployee(['last_name' => 'UnfiledOnly']);
        $unfiledItem = $this->makeItem($unfiledOnly, ['unfiled_count' => 1]);

        $tardyOnly = $this->createEmployee(['last_name' => 'TardyOnly']);
        $tardyItem = $this->makeItem($tardyOnly, ['tardiness_count' => 1, 'tardiness_minutes' => 30]);

        $undertimeOnly = $this->createEmployee(['last_name' => 'UndertimeOnly']);
        $undertimeItem = $this->makeItem($undertimeOnly, ['undertime_count' => 1, 'undertime_minutes' => 30]);

        $leaveManager = $this->createLeaveManager();

        $response = $this->actingAs($leaveManager)
            ->get(route('leave-manager.attendance-deductions', ['month' => 6, 'year' => 2026, 'issue' => 'tardiness']));

        $response->assertStatus(200);
        $response->assertSee($tardyItem->emp_no);
        $response->assertDontSee($unfiledItem->emp_no);
        $response->assertDontSee($undertimeItem->emp_no);
    }

    public function test_omitted_issue_param_defaults_to_unfiled(): void
    {
        $unfiledOnly = $this->createEmployee(['last_name' => 'UnfiledOnly']);
        $unfiledItem = $this->makeItem($unfiledOnly, ['unfiled_count' => 1]);

        $tardyOnly = $this->createEmployee(['last_name' => 'TardyOnly']);
        $tardyItem = $this->makeItem($tardyOnly, ['tardiness_count' => 1, 'tardiness_minutes' => 30]);

        $response = $this->actingAs($this->createLeaveManager())
            ->get(route('leave-manager.attendance-deductions', ['month' => 6, 'year' => 2026]));

        $response->assertStatus(200);
        $response->assertSee($unfiledItem->emp_no);
        $response->assertDontSee($tardyItem->emp_no);
    }

    public function test_table_column_reflects_the_selected_issue_instead_of_always_showing_unfiled(): void
    {
        $employee = $this->createEmployee();
        $this->makeItem($employee, ['tardiness_count' => 1, 'tardiness_minutes' => 45]);

        $response = $this->actingAs($this->createLeaveManager())
            ->get(route('leave-manager.attendance-deductions', ['month' => 6, 'year' => 2026, 'issue' => 'tardiness']));

        $response->assertStatus(200);
        $response->assertSee('Tardiness');
        $response->assertDontSee('>Unfiled<', false);
        $response->assertSee('45 min');
    }
}
