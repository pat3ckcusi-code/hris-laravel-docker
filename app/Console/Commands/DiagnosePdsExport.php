<?php

namespace App\Console\Commands;

use App\Models\Pds;
use App\Models\User;
use App\Services\PdsService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DiagnosePdsExport extends Command
{
    protected $signature = 'pds:diagnose {--user-id= : Run the export for a specific user ID}';

    protected $description = 'Validate that the PDS export pipeline works end-to-end';

    public function handle(PdsService $pdsService): int
    {
        $this->info('=== PDS Export Diagnostics ===');
        $ok = true;

        // 1. PHP extensions
        $this->line('');
        $this->info('[1] PHP extensions');
        foreach (['zip', 'mbstring', 'intl', 'pdo_mysql', 'gd', 'xml'] as $ext) {
            $loaded = extension_loaded($ext);
            $this->line(sprintf('  %-12s %s', $ext, $loaded ? '<fg=green>OK</>' : '<fg=red>MISSING</>'));
            if (! $loaded) {
                $ok = false;
            }
        }

        // 2. PHP limits
        $this->line('');
        $this->info('[2] PHP limits');
        $this->line('  memory_limit      = '.ini_get('memory_limit'));
        $this->line('  max_execution_time= '.ini_get('max_execution_time'));
        $this->line('  tmp_dir           = '.sys_get_temp_dir());
        $this->line('  tmp_writable      = '.(is_writable(sys_get_temp_dir()) ? '<fg=green>yes</>' : '<fg=red>NO</>'));

        // 3. Storage paths
        $this->line('');
        $this->info('[3] Storage paths');
        foreach ([
            storage_path('app/templates/PDS.xlsx') => 'PDS template',
            '/opt/app-templates/PDS.xlsx' => 'bundled fallback',
            storage_path('logs') => 'logs dir',
        ] as $path => $label) {
            $exists = file_exists($path);
            $this->line(sprintf('  %-20s %s  %s', $label, $exists ? '<fg=green>exists</>' : '<fg=yellow>missing</>', $path));
        }

        // 4. Database connectivity
        $this->line('');
        $this->info('[4] Database');
        try {
            $userCount = User::count();
            $pdsCount = Pds::count();
            $this->line("  users table       <fg=green>OK</> ({$userCount} rows)");
            $this->line("  pds table         <fg=green>OK</> ({$pdsCount} rows)");
        } catch (\Throwable $e) {
            $this->line('  <fg=red>DB ERROR: '.$e->getMessage().'</>');
            $ok = false;
        }

        // 5. Full export smoke-test
        $this->line('');
        $this->info('[5] Export smoke-test');
        $userId = $this->option('user-id');
        $user = $userId
            ? User::find($userId)
            : User::whereIn('access_level', ['employee', 'department head', 'hr manager'])->first();

        if (! $user) {
            $this->line('  <fg=yellow>No employee user found - skipping export test</>');
        } else {
            $this->line("  Testing with user #{$user->id} ({$user->name})");
            $before = memory_get_usage(true);
            try {
                ini_set('memory_limit', '256M');
                $spreadsheet = $pdsService->exportToExcel($user);

                $tmp = tempnam(sys_get_temp_dir(), 'pds_diag_');
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save($tmp);
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                $kb = round(filesize($tmp) / 1024, 1);
                $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
                @unlink($tmp);

                $this->line("  Export             <fg=green>OK</> ({$kb} KB, peak {$peak} MB)");
            } catch (\Throwable $e) {
                $this->line('  <fg=red>EXPORT FAILED: '.$e->getMessage().'</>');
                $this->line('  at '.$e->getFile().':'.$e->getLine());
                $ok = false;
            }
        }

        $this->line('');
        if ($ok) {
            $this->info('All checks passed.');
        } else {
            $this->error('One or more checks failed. Review the output above.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
