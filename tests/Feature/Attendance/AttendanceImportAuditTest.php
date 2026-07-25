<?php

namespace Tests\Feature\Attendance;

use App\Jobs\ImportAttendanceLogsJob;
use App\Services\PersonnelLogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * ImportAttendanceLogsJob is dispatched both by the every-minute
 * attendance:auto-import scheduler (actorUserId=null) and by a manual
 * "Pull Biometric Punch Logs" UI submission (actorUserId set). It only
 * writes an hr_audit_trails row when the run failed or imported something,
 * to stop the scheduler's routine no-op ticks from bloating the audit
 * table (see the 2026-07-21 cleanup migration). A manually-triggered pull
 * must always log regardless of outcome, or the user gets no feedback at
 * all when their selected range genuinely has nothing new to import.
 */
class AttendanceImportAuditTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function zeroImportResult(): array
    {
        return ['imported' => 0, 'skipped' => 5, 'messages' => [], 'error' => null];
    }

    public function test_manual_pull_with_zero_imports_still_writes_audit_row(): void
    {
        $timeKeeper = $this->createTimeKeeper();

        $service = $this->createMock(PersonnelLogImportService::class);
        $service->method('importForDateRange')->willReturn($this->zeroImportResult());

        $job = new ImportAttendanceLogsJob('2026-07-23', '2026-07-23', actorUserId: $timeKeeper->id);
        $job->handle($service);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'attendance',
            'action' => 'attendance_import',
            'actor_user_id' => $timeKeeper->id,
        ]);
    }

    public function test_scheduled_pull_with_zero_imports_writes_no_audit_row(): void
    {
        $service = $this->createMock(PersonnelLogImportService::class);
        $service->method('importForDateRange')->willReturn($this->zeroImportResult());

        $job = new ImportAttendanceLogsJob('2026-07-24', '2026-07-24', actorUserId: null);
        $job->handle($service);

        $this->assertDatabaseMissing('hr_audit_trails', [
            'module' => 'attendance',
            'action' => 'attendance_import',
        ]);
    }
}
