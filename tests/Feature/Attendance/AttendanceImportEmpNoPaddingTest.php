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
 * The exact/stripped-zero EmpNo matching in PersonnelLogImportService used to
 * only handle HRIS-padded + device-unpadded ("02009" vs "2009"). The reverse -
 * HRIS stores an unpadded EmpNo ("300858") but the biometric device sends a
 * zero-padded personnelid ("0300858") - fell through both lookup layers and
 * was silently reported as unmatched every single run, for as long as that
 * EmpNo stayed unpadded. Confirmed live in dev: 38 active users shaped this
 * way, 100% with zero attendance_logs rows ever.
 */
class AttendanceImportEmpNoPaddingTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeService(array $bulkLogs): PersonnelLogImportService
    {
        $api = $this->createMock(IntegrationApiService::class);
        $api->method('getToken')->willReturn('fake-token');
        $api->method('fetchBulkLogs')->willReturnOnConsecutiveCalls(
            [$bulkLogs, 200],
            [[], 200],
        );

        return new PersonnelLogImportService($api, new DtrPunchResolver, new ShiftPunchGrouper);
    }

    public function test_padded_device_personnelid_matches_unpadded_hris_empno(): void
    {
        $user = $this->createEmployee(['EmpNo' => '300858']);

        $bulkLogs = [
            ['personnelid' => '0300858', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'],
        ];

        $service = $this->makeService($bulkLogs);
        $result = $service->importForDateRange('2026-08-01', '2026-08-01');

        $this->assertNull($result['error']);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'logdate' => '2026-08-01',
        ]);

        $this->assertFalse(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'no matching HRIS EmpNo')),
            'A device-padded personnelid matching an unpadded HRIS EmpNo must not be reported as unmatched.'
        );
        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'Updated DTR for EmpNo 300858')),
            'Expected the matched employee\'s DTR update to be reported.'
        );
    }

    public function test_all_zero_personnelid_does_not_false_match(): void
    {
        // A user whose stripped EmpNo happens to be '0' must not be spuriously
        // matched by an all-zero incoming personnelid via the new fallback.
        $this->createEmployee(['EmpNo' => '300858']);

        $bulkLogs = [
            ['personnelid' => '0000000', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN', 'personnelfirstname' => 'All', 'personnellastname' => 'Zero'],
        ];

        $service = $this->makeService($bulkLogs);
        $result = $service->importForDateRange('2026-08-01', '2026-08-01');

        $this->assertDatabaseMissing('attendance_logs', ['emp_no' => '0000000']);
        $this->assertTrue(
            collect($result['messages'])->contains(fn (string $m) => str_contains($m, 'personnelid=0000000')),
            'An all-zero personnelid with no genuine HRIS match should still be reported as unmatched, not false-matched.'
        );
    }

    public function test_unmatched_summary_is_reported_before_per_user_dtr_messages(): void
    {
        // Regression guard for the message-ordering fix: ImportAttendanceLogsJob
        // caps stored messages to the first 100, so the unmatched-EmpNo summary
        // must come before the per-user "Updated DTR" messages, not after, or a
        // large run can truncate the one diagnostic that explains the failure.
        $this->createEmployee(['EmpNo' => '5001001']);

        $bulkLogs = [
            ['personnelid' => '5001001', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN'],
            ['personnelid' => '9999999', 'logdate' => '2026-08-01', 'logtime' => '08:00', 'inout' => 'IN', 'personnelfirstname' => 'Nobody', 'personnellastname' => 'Registered'],
        ];

        $service = $this->makeService($bulkLogs);
        $result = $service->importForDateRange('2026-08-01', '2026-08-01');

        $messages = $result['messages'];
        $unmatchedIndex = collect($messages)->search(fn (string $m) => str_contains($m, 'no matching HRIS EmpNo'));
        $dtrUpdateIndex = collect($messages)->search(fn (string $m) => str_contains($m, 'Updated DTR for EmpNo 5001001'));

        $this->assertNotFalse($unmatchedIndex, 'Expected an unmatched-EmpNo summary message.');
        $this->assertNotFalse($dtrUpdateIndex, 'Expected a per-user DTR update message.');
        $this->assertLessThan($dtrUpdateIndex, $unmatchedIndex, 'Unmatched-EmpNo summary must be reported before per-user DTR messages.');
    }
}
