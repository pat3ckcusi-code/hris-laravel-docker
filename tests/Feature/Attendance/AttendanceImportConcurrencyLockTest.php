<?php

namespace Tests\Feature\Attendance;

use App\Services\DtrPunchResolver;
use App\Services\IntegrationApiService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftPunchGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Both trigger points (the every-minute scheduler and a manual "Pull
 * Biometric Punch Logs" submission) always dispatch one ImportAttendanceLogsJob
 * per single calendar day. Two such jobs for the SAME date, running
 * concurrently under the app's two queue workers, previously had nothing
 * stopping their DTR upsert/orphan-cleanup steps from racing: one job's
 * stale punch snapshot could delete a dtrs row the other job just
 * legitimately wrote. importForDateRange() now acquires a per-date
 * Cache::lock() (config('attendance.import_lock')) before doing any real
 * work, serializing exactly this case.
 */
class AttendanceImportConcurrencyLockTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeService(): PersonnelLogImportService
    {
        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');
        $api->method('fetchBulkLogs')->willReturn([[], 200]);

        return new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);
    }

    public function test_second_import_for_same_date_is_skipped_while_first_holds_the_lock(): void
    {
        config(['attendance.import_lock.wait_seconds' => 1]);

        $heldLock = Cache::lock('attendance-import:2026-08-01', 650);
        $this->assertTrue($heldLock->get(), 'Test setup: failed to acquire the lock it means to hold.');

        try {
            $api = $this->createMock(IntegrationApiService::class);
            $api->expects($this->never())->method('getToken');
            $api->expects($this->never())->method('fetchBulkLogs');
            $service = new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);

            $result = $service->importForDateRange('2026-08-01', '2026-08-01');

            $this->assertSame(0, $result['imported']);
            $this->assertNotNull($result['error']);
            $this->assertStringContainsString('already in progress', $result['error']);
        } finally {
            $heldLock->release();
        }
    }

    public function test_import_succeeds_normally_after_the_lock_is_released(): void
    {
        $this->createEmployee(['EmpNo' => '6002002']);
        config(['attendance.import_lock.wait_seconds' => 1]);

        $heldLock = Cache::lock('attendance-import:2026-08-01', 650);
        $this->assertTrue($heldLock->get());
        $heldLock->release();

        $result = $this->makeService()->importForDateRange('2026-08-01', '2026-08-01');

        $this->assertNull($result['error']);
    }

    public function test_import_releases_its_own_lock_after_completing(): void
    {
        $this->createEmployee(['EmpNo' => '6002002']);

        $result = $this->makeService()->importForDateRange('2026-08-01', '2026-08-01');
        $this->assertNull($result['error']);

        // A fresh acquire attempt for the same date must succeed immediately -
        // proving the service released its own lock on the normal (non-
        // exception) path, not just when it throws.
        $freshLock = Cache::lock('attendance-import:2026-08-01', 650);
        $this->assertTrue($freshLock->get(), 'Expected the lock to be free after a completed import.');
        $freshLock->release();
    }
}
