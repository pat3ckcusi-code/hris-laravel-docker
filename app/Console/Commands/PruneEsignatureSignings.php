<?php

namespace App\Console\Commands;

use App\Models\EsignatureSigning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneEsignatureSignings extends Command
{
    protected $signature = 'esignature-signing:prune';

    protected $description = 'Mark stale in-flight signings as failed, and delete old failed attempts and their files';

    /**
     * Deliberately never touches 'completed' rows/files - unlike a bulk
     * export, a signed leave PDF is served indefinitely (LeaveRequestController
     * ::printSingle()'s branch reads it back off disk on every future print),
     * so pruning a completed signing would silently and permanently regress a
     * reprint to the inferior Excel export with no visible error.
     */
    public function handle(): int
    {
        $staleCount = EsignatureSigning::whereIn('status', [EsignatureSigning::STATUS_PENDING, EsignatureSigning::STATUS_PROCESSING])
            ->where('updated_at', '<', now()->subSeconds(360))
            ->update(['status' => EsignatureSigning::STATUS_FAILED, 'error_message' => 'Signing timed out. Please try again.', 'failed_at' => now()]);

        $oldFailed = EsignatureSigning::where('status', EsignatureSigning::STATUS_FAILED)
            ->where('updated_at', '<', now()->subDays(30))
            ->get();

        foreach ($oldFailed as $signing) {
            $dir = dirname($signing->unsigned_path);
            Storage::disk('esignature')->deleteDirectory($dir);
            $signing->delete();
        }

        $this->info("Pruned {$staleCount} stale signing(s), deleted {$oldFailed->count()} old failed signing(s).");

        return self::SUCCESS;
    }
}
