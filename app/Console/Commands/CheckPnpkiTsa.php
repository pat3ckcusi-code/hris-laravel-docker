<?php

namespace App\Console\Commands;

use App\Support\Rfc3161TimestampClient;
use Illuminate\Console\Command;

class CheckPnpkiTsa extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pnpki:tsa-check {file? : Path to a file to hash instead of a dummy payload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Standalone connectivity check for the configured PNPKI timestamp authority (RFC 3161).';

    public function handle(Rfc3161TimestampClient $client): int
    {
        $tsaUrl = config('services.pnpki.tsa_url');

        if (empty($tsaUrl)) {
            $this->error('PNPKI_TSA_URL is not configured.');

            return self::FAILURE;
        }

        $this->line("Checking TSA: {$tsaUrl}");

        $filePath = $this->argument('file');

        if ($filePath) {
            if (! is_file($filePath)) {
                $this->error("File not found: {$filePath}");

                return self::FAILURE;
            }

            $digest = hash_file('sha256', $filePath, true);
            $this->line("Hashing file: {$filePath}");
        } else {
            $digest = hash('sha256', 'pnpki:tsa-check '.now()->toIso8601String(), true);
            $this->line('No file given - hashing a dummy payload.');
        }

        $request = $client->buildRequest($digest);
        $result = $client->query($tsaUrl, $request);

        if ($result['unreachable']) {
            $this->error("UNREACHABLE: {$result['statusText']}");

            return self::FAILURE;
        }

        if (! $result['granted']) {
            $this->error("REJECTED: {$result['statusText']} (PKIStatus {$result['status']})");

            return self::FAILURE;
        }

        $this->info("GRANTED: {$result['statusText']} (PKIStatus {$result['status']})");

        return self::SUCCESS;
    }
}
