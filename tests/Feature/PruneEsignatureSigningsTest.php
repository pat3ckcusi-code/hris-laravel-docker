<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\EsignatureSigning;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Covers all three cleanup passes of `esignature-signing:prune`
 * (App\Console\Commands\PruneEsignatureSignings): marking stale in-flight
 * signings failed, deleting old failed signings' rows+files, and (the new
 * pass) deleting a superseded completed signing's FILE - never its row,
 * never the current-latest completed signing's file - once a newer
 * completed signing exists for the same signable. No test file existed for
 * this command before.
 */
class PruneEsignatureSigningsTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function createEsignatureLeave($owner, array $overrides = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'user_id' => $owner->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'total_days' => 1,
            'paid_days' => 1,
            'lwop_days' => 0,
            'status' => 'pending',
            'date_filed' => now()->toDateString(),
            'printing_allowed' => true,
            'esignature_requested_at' => now(),
        ], $overrides));
    }

    private function createDocumentType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'name' => 'Test Certificate '.uniqid(),
            'requires_esignature' => true,
            'parts' => [
                'title' => 'Test Certificate',
                'salutation' => 'To Whom It May Concern:',
                'body' => ['text' => 'This certifies that {employee_name} is employed.', 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'],
                'signatories' => [['name' => 'HR MANAGER', 'designation' => 'HR Manager']],
                'footer' => ['text' => '', 'font' => 'Calibri', 'size' => 10, 'color' => '#000000'],
            ],
        ], $overrides));
    }

    private function createDocumentRequest(User $employee, DocumentType $type, array $overrides = []): DocumentRequest
    {
        return DocumentRequest::create(array_merge([
            'EmpNo' => $employee->EmpNo,
            'document_type' => $type->name,
            'purpose' => 'Testing',
            'status' => 'Accepted',
            'requested_on' => now(),
        ], $overrides));
    }

    /**
     * Writes a real (fake-disk) signed.pdf and creates a matching completed
     * EsignatureSigning row pointing at it, unless `signed_path` is
     * overridden (e.g. to null, for the defensive-guard test).
     */
    private function createCompletedSigning(string $signableType, int $signableId, User $requester, array $overrides = []): EsignatureSigning
    {
        $signedPath = array_key_exists('signed_path', $overrides)
            ? $overrides['signed_path']
            : 'signings/'.uniqid().'/signed.pdf';

        if ($signedPath) {
            Storage::disk('esignature')->put($signedPath, 'fake-signed-pdf-bytes-'.uniqid());
        }

        return EsignatureSigning::create(array_merge([
            'signable_type' => $signableType,
            'signable_id' => $signableId,
            'requested_by' => $requester->id,
            'status' => EsignatureSigning::STATUS_COMPLETED,
            'unsigned_path' => 'signings/'.uniqid().'/unsigned.pdf',
        ], $overrides, ['signed_path' => $signedPath]));
    }

    private function backdateUpdatedAt(EsignatureSigning $signing, Carbon $when): void
    {
        $signing->timestamps = false;
        $signing->updated_at = $when;
        $signing->save();
        $signing->timestamps = true;
    }

    // ── Pass 1: stale pending/processing → failed ───────────────────────

    public function test_prune_command_marks_stale_pending_signing_as_failed(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/stale/unsigned.pdf',
        ]);
        $this->backdateUpdatedAt($signing, now()->subSeconds(400));

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        $signing->refresh();
        $this->assertSame(EsignatureSigning::STATUS_FAILED, $signing->status);
        $this->assertSame('Signing timed out. Please try again.', $signing->error_message);
        $this->assertNotNull($signing->failed_at);
    }

    public function test_prune_command_ignores_a_recently_updated_pending_signing(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_PENDING,
            'unsigned_path' => 'signings/fresh/unsigned.pdf',
        ]);
        $this->backdateUpdatedAt($signing, now()->subSeconds(60));

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        $this->assertSame(EsignatureSigning::STATUS_PENDING, $signing->fresh()->status);
    }

    // ── Pass 2: old failed → row + directory deleted ────────────────────

    public function test_prune_command_deletes_old_failed_signing_row_and_directory(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $dir = 'signings/old-failed-'.uniqid();
        Storage::disk('esignature')->put("{$dir}/unsigned.pdf", 'fake-unsigned-bytes');
        Storage::disk('esignature')->put("{$dir}/signature_field.json", '{"rect":[0,0,1,1]}');

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_FAILED,
            'unsigned_path' => "{$dir}/unsigned.pdf",
            'error_message' => 'Some failure',
            'failed_at' => now()->subDays(31),
        ]);
        $this->backdateUpdatedAt($signing, now()->subDays(31));

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        $this->assertDatabaseMissing('esignature_signings', ['id' => $signing->id]);
        Storage::disk('esignature')->assertMissing("{$dir}/unsigned.pdf");
        Storage::disk('esignature')->assertMissing("{$dir}/signature_field.json");
    }

    public function test_prune_command_keeps_a_recently_failed_signing(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $dir = 'signings/recent-failed-'.uniqid();
        Storage::disk('esignature')->put("{$dir}/unsigned.pdf", 'fake-unsigned-bytes');

        $signing = EsignatureSigning::create([
            'signable_type' => LeaveRequest::class,
            'signable_id' => $leave->id,
            'requested_by' => $owner->id,
            'status' => EsignatureSigning::STATUS_FAILED,
            'unsigned_path' => "{$dir}/unsigned.pdf",
            'error_message' => 'Some failure',
            'failed_at' => now()->subDays(10),
        ]);
        $this->backdateUpdatedAt($signing, now()->subDays(10));

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        $this->assertDatabaseHas('esignature_signings', ['id' => $signing->id]);
        Storage::disk('esignature')->assertExists("{$dir}/unsigned.pdf");
    }

    // ── Pass 3: superseded completed signing files (the new behavior) ──

    public function test_prune_command_deletes_superseded_signed_files_but_keeps_latest_and_all_rows(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $dh = $this->createDepartmentHead();
        $hr = $this->createHRManager();
        $leave = $this->createEsignatureLeave($owner);

        $base = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $owner);
        $approver = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $dh, ['field_name' => 'ApproverSignature']);
        $certifying = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $hr, ['field_name' => 'CertifyingSignature']);

        $basePath = $base->signed_path;
        $approverPath = $approver->signed_path;
        $certifyingPath = $certifying->signed_path;

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        Storage::disk('esignature')->assertMissing($basePath);
        Storage::disk('esignature')->assertMissing($approverPath);
        Storage::disk('esignature')->assertExists($certifyingPath);

        $this->assertDatabaseCount('esignature_signings', 3);
        $this->assertSame(EsignatureSigning::STATUS_COMPLETED, $base->fresh()->status);
        $this->assertSame(EsignatureSigning::STATUS_COMPLETED, $approver->fresh()->status);
        $this->assertSame(EsignatureSigning::STATUS_COMPLETED, $certifying->fresh()->status);

        // The DB column is left stale-but-intact, same convention as unsigned_path.
        $this->assertSame($basePath, $base->fresh()->signed_path);
        $this->assertSame($approverPath, $approver->fresh()->signed_path);
    }

    public function test_prune_command_leaves_a_single_completed_signing_untouched(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);

        $signing = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $owner);
        $path = $signing->signed_path;

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        Storage::disk('esignature')->assertExists($path);
        $this->assertDatabaseCount('esignature_signings', 1);
    }

    public function test_prune_command_skips_a_superseded_signing_with_a_null_signed_path_without_error(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $dh = $this->createDepartmentHead();
        $leave = $this->createEsignatureLeave($owner);

        $first = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $owner);
        $firstPath = $first->signed_path;

        // Defensive anomaly: a completed row with no signed_path (shouldn't
        // occur via markCompleted(), but the command must not crash on it).
        $anomalous = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $dh, [
            'field_name' => 'ApproverSignature',
            'signed_path' => null,
        ]);

        $latest = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $dh, ['field_name' => 'CertifyingSignature']);
        $latestPath = $latest->signed_path;

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        Storage::disk('esignature')->assertMissing($firstPath);
        Storage::disk('esignature')->assertExists($latestPath);
        $this->assertDatabaseCount('esignature_signings', 3);
        $this->assertNull($anomalous->fresh()->signed_path);
    }

    public function test_prune_command_does_not_cross_contaminate_between_signable_types_sharing_the_same_id(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $leave = $this->createEsignatureLeave($owner);
        $type = $this->createDocumentType();
        $documentRequest = $this->createDocumentRequest($owner, $type);

        // Force this DocumentRequest to share the LeaveRequest's numeric id -
        // this is exactly the collision scenario a signable_id-only grouping
        // key (instead of "{type}:{id}") would wrongly merge. MySQL's
        // AUTO_INCREMENT counter isn't rolled back by RefreshDatabase's
        // per-test transaction, so natural ids can't be relied on to collide
        // across a whole test run; the current table is otherwise empty
        // (prior tests' rows were rolled back), so this rename is safe.
        DocumentRequest::where('id', $documentRequest->id)->update(['id' => $leave->id]);
        $documentRequest = DocumentRequest::findOrFail($leave->id);

        $leaveSigning = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $owner);
        $documentSigning = $this->createCompletedSigning(DocumentRequest::class, $documentRequest->id, $owner);

        $this->artisan('esignature-signing:prune')->assertSuccessful();

        Storage::disk('esignature')->assertExists($leaveSigning->signed_path);
        Storage::disk('esignature')->assertExists($documentSigning->signed_path);
    }

    public function test_prune_command_is_idempotent_across_repeated_runs(): void
    {
        Storage::fake('esignature');
        $owner = $this->createEmployee();
        $dh = $this->createDepartmentHead();
        $leave = $this->createEsignatureLeave($owner);

        $base = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $owner);
        $latest = $this->createCompletedSigning(LeaveRequest::class, $leave->id, $dh, ['field_name' => 'ApproverSignature']);
        $basePath = $base->signed_path;
        $latestPath = $latest->signed_path;

        $this->artisan('esignature-signing:prune')->assertSuccessful();
        $this->artisan('esignature-signing:prune')->assertSuccessful();

        Storage::disk('esignature')->assertMissing($basePath);
        Storage::disk('esignature')->assertExists($latestPath);
        $this->assertDatabaseCount('esignature_signings', 2);
    }
}
