<?php

namespace Tests\Feature\Attendance;

use App\Services\DtrPunchResolver;
use App\Services\IntegrationApiService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftPunchGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A bulk import fetches the biometric API page by page. If a LATER page
 * fails (connection error or non-200), the method used to return
 * immediately - before recomputing DTR for employees whose punches were
 * already persisted from EARLIER pages. Because AttendanceLog::firstOrCreate()
 * is idempotent, a retried import never re-adds an already-persisted punch's
 * employee to the recompute set, permanently orphaning their dtrs row.
 */
class AttendanceImportPartialFailureTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_dtr_is_recomputed_for_punches_imported_before_a_later_page_fails(): void
    {
        $user = $this->createEmployee(['EmpNo' => '6001001']);

        $firstPage = [
            ['personnelid' => '6001001', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'],
        ];

        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');

        $callCount = 0;
        $api->method('fetchBulkLogs')->willReturnCallback(function () use (&$callCount, $firstPage) {
            $callCount++;

            if ($callCount === 1) {
                return [$firstPage, 200];
            }

            throw new \RuntimeException('cURL error 7: Failed to connect');
        });

        $service = new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);

        // pageSize=1 forces a second page fetch (count($firstPage) >= 1), which
        // is where the simulated connection failure happens.
        $result = $service->importForDateRange('2026-08-01', '2026-08-01', null, 1);

        $this->assertNotNull($result['error'], 'The run must still report the mid-pagination failure.');
        $this->assertStringContainsString('cURL error 7', $result['error']);

        // The punch from the successful first page must be persisted.
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'logdate' => '2026-08-01']);

        // And - the actual bug - its employee's DTR must still have been
        // recomputed despite the later page's failure, not silently skipped.
        $this->assertDatabaseHas('dtrs', ['employee_id' => $user->id, 'date' => '2026-08-01']);
        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'Updated DTR for EmpNo 6001001')),
            'Expected the DTR-updated message for the employee whose punch was imported before the failure.'
        );
    }

    /**
     * Regardless of WHY a dtrs row fell behind (the pagination bug above, a
     * crashed/restarted queue worker mid-job, some other future bug), the
     * only way HR/Time Keeper has to fix it is re-running "Pull Biometric
     * Punch Logs" for that date. That must actually work: recompute has to
     * be keyed off "who has punches in the requested range," not "who got a
     * brand-new punch this specific run" - otherwise AttendanceLog::
     * firstOrCreate()'s idempotency means an already-persisted punch can
     * never re-trigger the recompute that would fix its employee's stale row.
     */
    public function test_reimporting_an_already_persisted_punch_still_recomputes_a_stale_dtr_row(): void
    {
        $user = $this->createEmployee(['EmpNo' => '6003003']);

        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');

        // First run: only the AM-in punch exists. This creates a correct,
        // but necessarily incomplete, dtrs row (no PM-out yet).
        $api->method('fetchBulkLogs')->willReturn([
            [['personnelid' => '6003003', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN']],
            200,
        ]);

        $service = new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);
        $service->importForDateRange('2026-08-01', '2026-08-01');

        $this->assertDatabaseHas('dtrs', [
            'employee_id' => $user->id,
            'date' => '2026-08-01',
            'time_in_am' => '08:00:00',
            'time_out_pm' => null,
        ]);

        // Simulate the PM-out punch having been imported by some earlier run
        // whose recompute step got dropped (the exact symptom this test
        // guards against) - insert it directly into attendance_logs without
        // going through the service, so the dtrs row stays stale exactly
        // like it would have after a dropped recompute.
        \App\Models\AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => '6003003',
            'logdate' => '2026-08-01',
            'logtime' => '17:00:00',
            'logtype' => 'SYSTEM',
            'in_out' => 'OUT',
        ]);

        // Re-run the import for the same date. The API reports the same
        // AM-in punch it already reported before (already persisted, so
        // wasRecentlyCreated is false) - nothing new for THIS run to import.
        $result = $service->importForDateRange('2026-08-01', '2026-08-01');

        $this->assertSame(0, $result['imported'], 'Nothing new should be imported - the punch is already persisted.');

        // Despite zero new imports, the stale row must still have been
        // repaired using the PM-out punch that was already sitting in
        // attendance_logs.
        $this->assertDatabaseHas('dtrs', [
            'employee_id' => $user->id,
            'date' => '2026-08-01',
            'time_in_am' => '08:00:00',
            'time_out_pm' => '17:00:00',
        ]);
        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'Updated DTR for EmpNo 6003003')),
            'Expected the DTR-updated message even though this run imported nothing new.'
        );
    }
}
