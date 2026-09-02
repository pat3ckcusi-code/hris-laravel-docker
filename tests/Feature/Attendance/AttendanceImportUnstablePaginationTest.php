<?php

namespace Tests\Feature\Attendance;

use App\Services\DtrPunchResolver;
use App\Services\IntegrationApiService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftPunchGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * The biometric vendor's GetTimeLogsBulkData endpoint does not paginate its
 * result set reliably when 'start' > 0 across multiple calls - confirmed
 * 2026-09-02: a real employee's punches were silently dropped entirely
 * across every page of a multi-page walk, while other employees' records
 * appeared duplicated across page boundaries, with a normal 200 OK and no
 * error signal anywhere. The actual fix is raising the configured page size
 * well above realistic daily volume so a fetch completes in one call and
 * never touches the vendor's broken pagination - this test covers the
 * safety net for if that's ever insufficient again: a record repeating
 * across pages is the only detectable symptom, so it must be surfaced
 * loudly (logged and reported) rather than silently ignored.
 */
class AttendanceImportUnstablePaginationTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeService(array $page1, array $page2, array $page3 = []): PersonnelLogImportService
    {
        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');
        $api->method('fetchBulkLogs')->willReturnOnConsecutiveCalls(
            [$page1, 200],
            [$page2, 200],
            [$page3, 200],
        );

        return new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);
    }

    public function test_duplicate_record_across_pages_is_logged_and_reported(): void
    {
        Log::spy();

        $userA = $this->createEmployee(['EmpNo' => '5001001']);
        $userB = $this->createEmployee(['EmpNo' => '5001002']);
        $userC = $this->createEmployee(['EmpNo' => '5001003']);

        $recordA = ['personnelid' => '5001001', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'];
        $recordB = ['personnelid' => '5001002', 'logdate' => '2026-08-01', 'logtime' => '08:05', 'inout' => 'IN'];
        $recordC = ['personnelid' => '5001003', 'logdate' => '2026-08-01', 'logtime' => '08:10', 'inout' => 'IN'];

        // Page 1: A, B. Page 2: A again (duplicate - simulates the vendor's
        // actual observed unstable-pagination behavior) plus C. Page 3: empty.
        $service = $this->makeService([$recordA, $recordB], [$recordA, $recordC], []);

        $result = $service->importForDateRange('2026-08-01', '2026-08-01', null, 2);

        $this->assertNull($result['error']);
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $userA->id, 'logdate' => '2026-08-01']);
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $userB->id, 'logdate' => '2026-08-01']);
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $userC->id, 'logdate' => '2026-08-01']);

        // The duplicate must not be double-counted as a second import.
        $this->assertSame(3, $result['imported']);

        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'duplicate record') && str_contains($m, 'unstable')),
            'Expected a duplicate-pagination warning message.'
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'unstable') && str_contains($message, 'pagination'))
            ->once();
    }

    public function test_duplicate_within_a_single_page_is_reported_as_harmless(): void
    {
        Log::spy();

        $userA = $this->createEmployee(['EmpNo' => '5001001']);

        $recordA = ['personnelid' => '5001001', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'];

        // Both duplicates arrive in the SAME (only) page - confirmed 2026-09-02
        // that the vendor's feed can contain duplicate rows even with no
        // pagination involved at all. Nothing else can be missing here since
        // the whole day's data was already captured in this one response.
        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');
        $api->method('fetchBulkLogs')->willReturn([[$recordA, $recordA], 200]);
        $service = new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);

        $result = $service->importForDateRange('2026-08-01', '2026-08-01', null, 20000);

        $this->assertDatabaseHas('attendance_logs', ['user_id' => $userA->id, 'logdate' => '2026-08-01']);
        $this->assertSame(1, $result['imported']);

        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'duplicate record') && str_contains($m, 'harmless')),
            'Expected a harmless, single-page duplicate note (not an urgent warning).'
        );
        $this->assertFalse(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'WARNING')),
            'A single-page duplicate should not be escalated to the urgent multi-page warning.'
        );

        Log::shouldNotHaveReceived('error');
    }

    public function test_no_warning_when_pages_have_no_overlap(): void
    {
        Log::spy();

        $userA = $this->createEmployee(['EmpNo' => '5001001']);
        $userB = $this->createEmployee(['EmpNo' => '5001002']);

        $recordA = ['personnelid' => '5001001', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'];
        $recordB = ['personnelid' => '5001002', 'logdate' => '2026-08-01', 'logtime' => '08:05', 'inout' => 'IN'];

        $service = $this->makeService([$recordA], [$recordB], []);

        $result = $service->importForDateRange('2026-08-01', '2026-08-01', null, 1);

        $this->assertFalse(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'duplicate record')),
            'A clean, non-overlapping multi-page fetch should not report a pagination warning.'
        );

        Log::shouldNotHaveReceived('error');
    }
}
