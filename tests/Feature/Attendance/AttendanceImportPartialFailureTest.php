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
}
