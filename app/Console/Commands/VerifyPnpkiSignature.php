<?php

namespace App\Console\Commands;

use App\Models\ESignatureRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class VerifyPnpkiSignature extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pnpki:verify
        {esignature_request : E-Signature Request ID}
        {--root-ca= : Path to the trusted DICT root CA PEM/CER file}
        {--intermediate=* : Path(s) to intermediate CA file(s), in signing order}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-open a signed e-signature request PDF and report signer identity, timestamp, and revocation status via pyHanko.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $esignatureRequest = ESignatureRequest::find($this->argument('esignature_request'));

        if (! $esignatureRequest) {
            $this->error('E-signature request not found.');

            return self::FAILURE;
        }

        if (! $esignatureRequest->isSigned()) {
            $this->warn('This request has no signed PDF yet (still processing, or signing failed).');

            return self::FAILURE;
        }

        $pdfPath = Storage::disk('esignature')->path($esignatureRequest->pdf_path);

        if (! is_file($pdfPath)) {
            $this->error("Signed PDF not found on disk at [{$pdfPath}].");

            return self::FAILURE;
        }

        // The trust chain is no longer server-side config - it's uploaded per
        // submission at signing time, same as the certificate, and never persisted.
        // Verifying an already-signed PDF later means the operator has to supply
        // the same root/intermediate CA files by hand.
        $rootCa = $this->option('root-ca');

        if (empty($rootCa) || ! is_file($rootCa)) {
            $this->error('Pass --root-ca=/path/to/dict-root-ca.pem (the trust chain is not stored server-side).');

            return self::FAILURE;
        }

        $command = [
            config('services.pnpki.pyhanko_bin'),
            'sign', 'validate', '--pretty-print', '--force-revinfo',
            '--trust', $rootCa,
        ];

        foreach ($this->option('intermediate') as $intermediate) {
            if (! is_file($intermediate)) {
                $this->error("Intermediate cert not found at [{$intermediate}].");

                return self::FAILURE;
            }

            $command[] = '--other-certs';
            $command[] = $intermediate;
        }

        $command[] = $pdfPath;

        $result = Process::timeout(30)->run($command);

        $this->line($result->output());

        if ($result->failed()) {
            $this->error('pyHanko reported this signature as invalid or not fully revocation-checked.');

            return self::FAILURE;
        }

        $this->info('Signature validated successfully.');

        return self::SUCCESS;
    }
}
