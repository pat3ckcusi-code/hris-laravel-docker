<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredCache extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete already-expired rows from the database cache table';

    public function handle(): int
    {
        $count = DB::table('cache')->where('expiration', '<', now()->timestamp)->delete();

        $this->info("Pruned {$count} expired cache row(s).");

        return self::SUCCESS;
    }
}
