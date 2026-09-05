<?php

namespace Tests\Feature\RoleBased;

use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Per-date (partial) leave cancellation and reschedule tests.
 *
 * Covers: the reschedule insert bug regression, partial-date cancellation through the
 * full DH -> AO -> Leave Manager chain, partial reschedule leaving the remaining
 * original date(s) approved, is_lwop-gated refund correctness, and the reschedule
 * single-flight unfreeze on rejection.
 */
class LeavePartialDateTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function createApprovedMultiDateLeave(User $emp, array $dateSpecs): LeaveRequest
    {
        $dates = array_column($dateSpecs, 'leave_date');
        sort($dates);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => $dates[0],
            'end_date' => end($dates),
            'total_days' => array_sum(array_column($dateSpecs, 'days')),
            'paid_days' => array_sum(array_column($dateSpecs, 'days')),
            'lwop_days' => 0,
            'reason' => 'Test',
            'status' => 'approved',
        ]);

        foreach ($dateSpecs as $spec) {
            LeaveDate::create(array_merge([
                'leave_request_id' => $leave->id,
                'is_cancelled' => false,
                'is_lwop' => false,
                'leave_type' => 'Vacation Leave',
                'days' => 1.0,
            ], $spec));
        }

        return $leave;
    }

    // ──────────────────────────────────────────────
    // Reschedule bug regression
    // ──────────────────────────────────────────────

    public function test_reschedule_request_persists_per_date_leave_type_and_days(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 15.000]);

        $d1 = now()->addWeeks(2)->startOfWeek()->toDateString();
        $d2 = now()->addWeeks(2)->startOfWeek()->addDay()->toDateString();
        $leave = $this->createApprovedMultiDateLeave($emp, [
            ['leave_date' => $d1],
            ['leave_date' => $d2],
        ]);
        $originalDateId = $leave->leaveDates()->where('leave_date', $d1)->first()->id;

        $newDate = now()->addWeeks(3)->toDateString();

        $response = $this->actingAs($emp)->post(route('employee.leave.reschedule', $leave->id), [
            'leave_types' => ['Vacation Leave'],
            'leave_dates' => $newDate,
            'allocation' => [$newDate => ['type' => 'Vacation Leave', 'days' => 1]],
            'leave_date_ids' => [$originalDateId],
            'reason' => 'Reschedule test',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $newLeave = LeaveRequest::where('rescheduled_from_id', $leave->id)->first();
        $this->assertNotNull($newLeave, 'A new rescheduled LeaveRequest should have been created');

        $newLeaveDate = $newLeave->leaveDates()->first();
        $this->assertNotNull($newLeaveDate);
        $this->assertSame('Vacation Leave', $newLeaveDate->leave_type);
        $this->assertEquals(1.0, (float) $newLeaveDate->days);

        $leave->refresh();
        $this->assertSame('Pending Reschedule', $leave->reschedule_status);

        $linkedDate = $leave->leaveDates()->where('leave_date', $d1)->first();
        $this->assertEquals($newLeave->id, $linkedDate->rescheduled_to_leave_request_id);

        $untouchedDate = $leave->leaveDates()->where('leave_date', $d2)->first();
        $this->assertNull($untouchedDate->rescheduled_to_leave_request_id);
    }

    // ──────────────────────────────────────────────
    // Partial cancellation chain
    // ──────────────────────────────────────────────

    public function test_dh_can_recommend_partial_cancellation_dates(): void
    {
        $dh = $this->createDepartmentHead();
        $emp = $this->createEmployee();

        $leave = $this->createApprovedMultiDateLeave($emp, [
            ['leave_date' => now()->addWeek()->toDateString()],
            ['leave_date' => now()->addWeek()->addDay()->toDateString()],
        ]);
        $date1 = $leave->leaveDates()->orderBy('leave_date')->first();
        $date1->update([
            'cancellation_status' => 'Pending Cancellation',
            'cancellation_reason' => 'Personal',
            'cancellation_requested_at' => now(),
            'cancellation_requested_by' => $emp->id,
        ]);

        $response = $this->actingAs($dh)->postJson(route('department-head.leave.recommend-cancellation-dates', $leave->id), [
            'leave_date_ids' => [$date1->id],
            'remarks' => 'Looks fine.',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $date1->refresh();
        $this->assertSame('DH Recommended', $date1->cancellation_status);
        $this->assertSame($dh->id, $date1->cancellation_dh_by);

        // The untouched second date must remain unaffected.
        $date2 = $leave->leaveDates()->orderBy('leave_date')->skip(1)->first();
        $this->assertNull($date2->cancellation_status);
    }

    public function test_partial_cancellation_leaves_other_dates_approved_and_recomputes_parent(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 10.000]);

        $keepDate = now()->addWeek()->toDateString();
        $cancelDate = now()->addWeek()->addDay()->toDateString();
        $leave = $this->createApprovedMultiDateLeave($emp, [
            ['leave_date' => $keepDate],
            ['leave_date' => $cancelDate],
        ]);

        $dateToCancel = $leave->leaveDates()->where('leave_date', $cancelDate)->first();
        $dateToCancel->update(['cancellation_status' => 'AO Endorsed']);

        $response = $this->actingAs($lm)->postJson(route('api.leave.approve-date-cancellation', $leave->id), [
            'leave_date_ids' => [$dateToCancel->id],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $leave->refresh();
        $this->assertSame('approved', $leave->status, 'Parent request must stay approved when a date remains active');
        $this->assertEquals(1, (float) $leave->total_days);
        $this->assertSame($keepDate, (string) $leave->start_date);
        $this->assertSame($keepDate, (string) $leave->end_date);

        $dateToCancel->refresh();
        $this->assertTrue((bool) $dateToCancel->is_cancelled);

        $keptDateRow = $leave->leaveDates()->where('leave_date', $keepDate)->first();
        $this->assertFalse((bool) $keptDateRow->is_cancelled);

        $emp->refresh();
        $this->assertEquals(11.0, (float) $emp->leaveBalance->VL, 'Only the cancelled date should be refunded');
    }

    public function test_partial_cancellation_of_last_remaining_date_collapses_to_full_cancel(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 10.000]);

        $onlyDate = now()->addWeek()->toDateString();
        $leave = $this->createApprovedMultiDateLeave($emp, [
            ['leave_date' => $onlyDate],
        ]);
        $dateRow = $leave->leaveDates()->first();
        $dateRow->update(['cancellation_status' => 'AO Endorsed']);

        $response = $this->actingAs($lm)->postJson(route('api.leave.approve-date-cancellation', $leave->id), [
            'leave_date_ids' => [$dateRow->id],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $leave->refresh();
        $this->assertSame('cancelled', $leave->status);
        $this->assertSame('Cancelled', $leave->cancellation_status);
    }

    public function test_partial_cancellation_refund_only_credits_non_lwop_dates(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 10.000]);

        $paidDate = now()->addWeek()->toDateString();
        $lwopDate = now()->addWeek()->addDay()->toDateString();
        $leave = $this->createApprovedMultiDateLeave($emp, [
            ['leave_date' => $paidDate, 'is_lwop' => false],
            ['leave_date' => $lwopDate, 'is_lwop' => true],
        ]);

        $leave->leaveDates()->update(['cancellation_status' => 'AO Endorsed']);
        $ids = $leave->leaveDates()->pluck('id')->all();

        $response = $this->actingAs($lm)->postJson(route('api.leave.approve-date-cancellation', $leave->id), [
            'leave_date_ids' => $ids,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $emp->refresh();
        $this->assertEquals(11.0, (float) $emp->leaveBalance->VL, 'Only the non-LWOP date should be refunded');
    }

    // ──────────────────────────────────────────────
    // Partial reschedule
    // ──────────────────────────────────────────────

    public function test_partial_reschedule_leaves_remaining_original_date_approved(): void
    {
        $emp = $this->createEmployee();
        $dh = $this->createDepartmentHead();
        $this->createLeaveBalance($emp, ['VL' => 15.000]);

        $keepDate = now()->addWeeks(2)->startOfWeek()->toDateString();
        $rescheduleDate = now()->addWeeks(2)->startOfWeek()->addDay()->toDateString();
        $leave = $this->createApprovedMultiDateLeave($emp, [
            ['leave_date' => $keepDate],
            ['leave_date' => $rescheduleDate],
        ]);
        $rescheduleDateId = $leave->leaveDates()->where('leave_date', $rescheduleDate)->first()->id;

        $newDate = now()->addWeeks(3)->toDateString();
        $this->actingAs($emp)->post(route('employee.leave.reschedule', $leave->id), [
            'leave_types' => ['Vacation Leave'],
            'leave_dates' => $newDate,
            'allocation' => [$newDate => ['type' => 'Vacation Leave', 'days' => 1]],
            'leave_date_ids' => [$rescheduleDateId],
            'reason' => 'Partial reschedule',
        ])->assertSessionDoesntHaveErrors();

        $newLeave = LeaveRequest::where('rescheduled_from_id', $leave->id)->firstOrFail();

        // Department Head approval is gated on printing_allowed; set it directly rather
        // than going through the Allow Printing flow, since that's not what this test covers.
        $newLeave->printing_allowed = true;
        $newLeave->save();

        $response = $this->actingAs($dh)->post(route('department-head.leave.approve', $newLeave->id));
        $response->assertRedirect();

        $leave->refresh();
        $this->assertSame('approved', $leave->status, 'Original must remain approved: one date was untouched');
        $this->assertEquals(1, (float) $leave->total_days);
        $this->assertNull($leave->reschedule_status, 'Single-flight gate should clear once this reschedule resolves');

        $keptDateRow = $leave->leaveDates()->where('leave_date', $keepDate)->first();
        $this->assertFalse((bool) $keptDateRow->is_cancelled);

        $rescheduledDateRow = $leave->leaveDates()->where('leave_date', $rescheduleDate)->first();
        $this->assertTrue((bool) $rescheduledDateRow->is_cancelled);

        $newLeave->refresh();
        $this->assertSame('approved', $newLeave->status);

        // The test fixture creates the original leave_dates directly (bypassing store()),
        // so no balance was ever deducted for the original filing. Rescheduling refunds
        // the one cancelled original date (+1) then deducts the one new approved date
        // (-1) — a pure transfer that nets back to the starting balance.
        $emp->refresh();
        $this->assertEquals(15.0, (float) $emp->leaveBalance->VL, 'Balance should net to a pure transfer, not a double deduction');
    }

    // ──────────────────────────────────────────────
    // Guards
    // ──────────────────────────────────────────────

    public function test_partial_cancellation_rejects_date_already_in_progress(): void
    {
        $emp = $this->createEmployee();

        $leave = $this->createApprovedMultiDateLeave($emp, [
            ['leave_date' => now()->addWeek()->toDateString()],
            ['leave_date' => now()->addWeek()->addDay()->toDateString()],
        ]);
        $date1 = $leave->leaveDates()->first();
        $date1->update(['cancellation_status' => 'Pending Cancellation']);

        $response = $this->actingAs($emp)->post(route('employee.leave.request-partial-cancellation', $leave->id), [
            'leave_date_ids' => [$date1->id],
            'reason' => 'Trying again',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ──────────────────────────────────────────────
    // Reject-path unfreeze
    // ──────────────────────────────────────────────

    public function test_mayor_reject_reschedule_unfreezes_original(): void
    {
        $dh = $this->createDepartmentHead();
        $mayor = $this->createMayor();
        $this->createLeaveBalance($dh, ['VL' => 15.000]);

        $date1 = now()->addWeeks(2)->startOfWeek()->toDateString();
        $leave = $this->createApprovedMultiDateLeave($dh, [
            ['leave_date' => $date1],
        ]);
        $dateId = $leave->leaveDates()->first()->id;

        $newDate = now()->addWeeks(3)->toDateString();
        $this->actingAs($dh)->post(route('employee.leave.reschedule', $leave->id), [
            'leave_types' => ['Vacation Leave'],
            'leave_dates' => $newDate,
            'allocation' => [$newDate => ['type' => 'Vacation Leave', 'days' => 1]],
            'leave_date_ids' => [$dateId],
            'reason' => 'Reschedule by DH',
        ])->assertSessionDoesntHaveErrors();

        $newLeave = LeaveRequest::where('rescheduled_from_id', $leave->id)->firstOrFail();

        $response = $this->actingAs($mayor)->post(route('mayor.leave.reject', $newLeave->id), [
            'rejection_notes' => 'Not approved',
        ]);
        $response->assertRedirect();

        $leave->refresh();
        $this->assertNull($leave->reschedule_status, 'Reschedule gate must clear so the employee can try again');

        $dateRow = $leave->leaveDates()->first();
        $this->assertNull($dateRow->rescheduled_to_leave_request_id);
    }
}
