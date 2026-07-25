<?php

namespace App\Jobs;

use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Services\PersonnelLogImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ImportAttendanceLogsJob implements ShouldQueue
{
    use Queueable;

    // One attempt - the biometric API can be slow and re-runs are safe
    // (unique constraint on attendance_logs prevents duplicates), but we
    // don't want automatic retries hammering the external API on failure.
    public int $tries = 1;

    // Allow up to 10 minutes for large employee sets.
    public int $timeout = 600;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public ?int $actorUserId = null,
        public ?int $deptId = null,
        public ?int $pageSize = null,
    ) {}

    public function handle(PersonnelLogImportService $importService): void
    {
        try {
            $result = $importService->importForDateRange($this->from, $this->to, $this->deptId, $this->pageSize);
        } catch (\Throwable $e) {
            $result = ['imported' => 0, 'skipped' => 0, 'messages' => [], 'error' => $e->getMessage()];
        }

        $failed = ! empty($result['error']);

        $deptName = $this->deptId
            ? (Department::find($this->deptId)?->Dept_name ?? "Department #{$this->deptId}")
            : null;

        $deptLabel = $deptName ?? 'ALL';

        // Auto-import runs every minute; a routine no-op (nothing imported, no
        // error) would otherwise re-log the same unmatched-EmpNo warnings to
        // hr_audit_trails on every tick forever, so the scheduler's own runs
        // (actorUserId is always null - see AutoImportAttendanceLogs) stay
        // silent unless something happened. A manually-triggered pull always
        // logs regardless of outcome, so the user gets feedback for their own
        // action even when the range genuinely has nothing new to import.
        if ($failed || $result['imported'] > 0 || $this->actorUserId !== null) {
            HRAuditTrail::create([
                'actor_user_id' => $this->actorUserId,
                'module' => 'attendance',
                'action' => 'attendance_import',
                'target_type' => 'attendance_logs',
                'target_id' => $this->deptId,
                'details' => [
                    'description' => "Imported {$result['imported']} punches for date range [{$this->from} to {$this->to}] department=[{$deptLabel}]",
                    'from' => $this->from,
                    'to' => $this->to,
                    'dept_id' => $this->deptId,
                    'dept_name' => $deptLabel,
                    'imported' => $result['imported'],
                    'skipped' => $result['skipped'],
                    'status' => $failed ? 'failed' : 'success',
                    'error' => $result['error'],
                    // Cap stored messages to avoid bloating the audit row.
                    'messages' => array_slice($result['messages'], 0, 100),
                ],
            ]);
        }

        if ($failed) {
            Log::error('Attendance import failed', [
                'actor_user_id' => $this->actorUserId,
                'from' => $this->from,
                'to' => $this->to,
                'dept_id' => $this->deptId,
                'error' => $result['error'],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        HRAuditTrail::create([
            'actor_user_id' => $this->actorUserId,
            'module' => 'attendance',
            'action' => 'attendance_import',
            'target_type' => 'attendance_logs',
            'target_id' => $this->deptId,
            'details' => [
                'description' => "Import job failed unexpectedly for [{$this->from}]",
                'from' => $this->from,
                'to' => $this->to,
                'dept_id' => $this->deptId,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ],
        ]);
    }
}
