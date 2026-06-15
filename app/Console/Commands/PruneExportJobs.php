<?php

namespace App\Console\Commands;

use App\Models\ExportJob;
use Illuminate\Console\Command;

class PruneExportJobs extends Command
{
    protected $signature = 'export:prune';

    protected $description = 'Mark stale export jobs (stuck in processing/pending > 6 min) as failed';

    public function handle(): int
    {
        $count = ExportJob::whereIn('status', ['processing', 'pending'])
            ->where('updated_at', '<', now()->subSeconds(360))
            ->update(['status' => 'failed', 'error_message' => 'Export timed out. Please try again.']);

        $this->info("Pruned {$count} stale export job(s).");

        return self::SUCCESS;
    }
}
