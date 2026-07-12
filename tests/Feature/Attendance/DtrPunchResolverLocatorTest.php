<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\Locator;
use App\Models\User;
use App\Services\PersonnelLogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A real punch that happens outside a locator's declared [departure, arrival]
 * window must never be swallowed by the locator's slot coverage - it's the
 * genuine slot punch, not a missing one. Reported incident: employee punched
 * back in from lunch at 12:48 (a legitimate PM-in), then left at 13:00 for a
 * personal errand covered by an approved Locator; the resolver discarded the
 * 12:48 punch from PM-in and mis-slotted it into PM-out instead, leaving the
 * DTR showing "LOCATOR" for PM-in and 12:48 for PM-out.
 */
class DtrPunchResolverLocatorTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-07-09';

    private function punch(User $user, string $time): void
    {
        AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => $user->EmpNo,
            'logdate' => self::DATE,
            'logtime' => $time,
        ]);
    }

    private function locator(User $user, string $departure, string $arrival): Locator
    {
        return Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Personal',
            'location' => 'Test location',
            'travel_date' => self::DATE,
            'intended_departure_time' => $departure,
            'intended_arrival_time' => $arrival,
            'detail' => 'Test errand',
            'status' => 'approved',
        ]);
    }

    private function recompute(User $user): Dtr
    {
        app(PersonnelLogImportService::class)->recomputeDtr($user, self::DATE, self::DATE);

        return Dtr::where('employee_id', $user->id)->whereDate('date', self::DATE)->firstOrFail();
    }

    public function test_pm_in_punch_before_locator_departure_is_not_swallowed_into_pm_out(): void
    {
        $user = $this->createEmployee();
        $this->punch($user, '07:52:00');
        $this->punch($user, '12:03:00');
        $this->punch($user, '12:48:00');
        $this->locator($user, '13:00:00', '15:00:00');

        $dtr = $this->recompute($user);

        $this->assertSame('07:52:00', $dtr->time_in_am);
        $this->assertSame('12:03:00', $dtr->time_out_am);
        $this->assertSame('12:48:00', $dtr->time_in_pm, 'The 12:48 punch happened before the 13:00 departure - it is the real PM-in.');
        $this->assertNull($dtr->time_out_pm, 'No punch exists for the actual PM-out - it must not be borrowed from PM-in.');
    }

    public function test_am_in_punch_before_early_morning_locator_departure_is_not_swallowed(): void
    {
        $user = $this->createEmployee();
        $this->punch($user, '07:45:00');
        $this->locator($user, '08:00:00', '10:00:00');

        $dtr = $this->recompute($user);

        $this->assertSame('07:45:00', $dtr->time_in_am, 'Punched in at 07:45, before the 08:00 departure - the real AM-in.');
        $this->assertNull($dtr->time_out_am);
        $this->assertNull($dtr->time_in_pm);
        $this->assertNull($dtr->time_out_pm);
    }

    public function test_am_out_punch_before_locator_departure_is_not_swallowed_into_pm_in(): void
    {
        $user = $this->createEmployee();
        $this->punch($user, '07:52:00');
        $this->punch($user, '10:15:00');
        $this->punch($user, '13:05:00');
        $this->punch($user, '17:00:00');
        // dep<=12:00 && arr>=morningEnd(11:00) => covers am_out only.
        $this->locator($user, '10:30:00', '12:00:00');

        $dtr = $this->recompute($user);

        $this->assertSame('07:52:00', $dtr->time_in_am);
        $this->assertSame('10:15:00', $dtr->time_out_am, 'Punched out at 10:15, before the 10:30 departure - the real AM-out.');
        $this->assertSame('13:05:00', $dtr->time_in_pm);
        $this->assertSame('17:00:00', $dtr->time_out_pm);
    }

    public function test_pm_out_punch_after_locator_arrival_is_not_swallowed(): void
    {
        $user = $this->createEmployee();
        $this->punch($user, '07:52:00');
        $this->punch($user, '12:03:00');
        $this->punch($user, '12:50:00');
        $this->punch($user, '17:15:00');
        // arr(17:00) >= workEnd(17:00) => covers pm_out only.
        $this->locator($user, '15:00:00', '17:00:00');

        $dtr = $this->recompute($user);

        $this->assertSame('07:52:00', $dtr->time_in_am);
        $this->assertSame('12:03:00', $dtr->time_out_am);
        $this->assertSame('12:50:00', $dtr->time_in_pm);
        $this->assertSame('17:15:00', $dtr->time_out_pm, 'Punched out at 17:15, after the declared 17:00 arrival - the real PM-out.');
    }

    public function test_locator_still_suppresses_pm_in_when_no_punch_exists_before_departure(): void
    {
        $user = $this->createEmployee();
        $this->punch($user, '07:52:00');
        $this->punch($user, '12:03:00');
        $this->locator($user, '13:00:00', '15:00:00');

        $dtr = $this->recompute($user);

        $this->assertSame('07:52:00', $dtr->time_in_am);
        $this->assertSame('12:03:00', $dtr->time_out_am);
        $this->assertNull($dtr->time_in_pm, 'No return-from-lunch punch exists - PM-in stays genuinely empty (displays as LOCATOR).');
        $this->assertNull($dtr->time_out_pm);
    }

    public function test_punch_genuinely_inside_the_locator_window_is_still_excluded_from_its_natural_slot(): void
    {
        $user = $this->createEmployee();
        $this->punch($user, '07:52:00');
        $this->punch($user, '12:03:00');
        // Anomalous punch DURING the declared trip - still not a real PM-in.
        $this->punch($user, '14:00:00');
        $this->locator($user, '13:00:00', '15:00:00');

        $dtr = $this->recompute($user);

        $this->assertNull($dtr->time_in_pm, 'A punch inside the travel window is not treated as the genuine PM-in punch.');
    }
}
