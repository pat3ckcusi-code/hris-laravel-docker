<?php

namespace App\Console\Commands;

use App\Services\CscPlantillaImportService;
use Illuminate\Console\Command;

class ImportCscPlantilla extends Command
{
    protected $signature = 'plantilla:import-csc
        {path? : Path to the CSC Plantilla of Personnel .xls; defaults to storage/app/C S C  PLANTILLA   2026.xls}
        {--dry-run : Parse and match without persisting anything}
        {--csv : Write the unmatched/ambiguous report to storage/app/exports}';

    protected $description = 'Import CSC plantilla items and wire incumbents to users (SG/step and appointment/promotion dates from the file; salaries from the salary matrix)';

    public function handle(CscPlantillaImportService $service): int
    {
        $path = $this->argument('path') ?: storage_path('app/C S C  PLANTILLA   2026.xls');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[DRY RUN] ' : '').'Importing '.basename($path).' ...');

        $report = $service->import($path, (bool) $this->option('dry-run'));

        $this->table(['Metric', 'Count'], [
            ['Sheets processed', $report['sheets_processed']],
            ['Items parsed', $report['items_parsed']],
            ['Vacant items', $report['vacant_items']],
            ['Plantillas created', $report['plantillas_created']],
            ['Plantillas updated', $report['plantillas_updated']],
            ['Plantillas unchanged', $report['plantillas_unchanged']],
            ['Incumbents matched to users', $report['matched']],
            ['Assignments created', $report['assignments_created']],
            ['Assignments replaced', $report['assignments_replaced']],
            ['Assignments unchanged', $report['assignments_unchanged']],
            ['Stale assignments ended', $report['stale_assignments_ended']],
            ['Users salary grade/step synced', $report['users_synced']],
            ['Users appointment dates updated', $report['users_dates_updated']],
            ['Unmatched incumbents', count($report['unmatched_incumbents'])],
            ['Ambiguous matches', count($report['ambiguous'])],
            ['Duplicate matches', count($report['duplicate_matches'])],
            ['Users with designation, no assignment', count($report['users_designated_unassigned'])],
        ]);

        $this->printProblemList('Unmatched incumbents (no user found)', $report['unmatched_incumbents']);
        $this->printProblemList('Ambiguous matches (several users share the name)', $report['ambiguous']);
        $this->printProblemList('Duplicate matches (user already holds another item)', $report['duplicate_matches']);

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($this->option('csv')) {
            $this->writeCsv($report);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run - no changes were saved.');
        }

        return self::SUCCESS;
    }

    private function printProblemList(string $heading, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn($heading.': '.count($rows));

        foreach (array_slice($rows, 0, 50) as $row) {
            $extra = isset($row['candidates']) ? " [candidate user ids: {$row['candidates']}]"
                : (isset($row['reason']) ? " [{$row['reason']}]" : '');
            $this->line("  {$row['sheet']} row {$row['row']} item {$row['item_number']}: {$row['name']} - {$row['title']}{$extra}");
        }

        if (count($rows) > 50) {
            $this->line('  ... and '.(count($rows) - 50).' more (use --csv for the full list).');
        }
    }

    private function writeCsv(array $report): void
    {
        $directory = storage_path('app/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/plantilla-import-unmatched-'.now()->format('Ymd-His').'.csv';
        $handle = fopen($path, 'w');

        fputcsv($handle, ['type', 'sheet', 'row', 'item_number', 'position', 'name', 'details']);

        $sections = [
            'unmatched' => $report['unmatched_incumbents'],
            'ambiguous' => $report['ambiguous'],
            'duplicate' => $report['duplicate_matches'],
        ];

        foreach ($sections as $type => $rows) {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $type,
                    $row['sheet'],
                    $row['row'],
                    $row['item_number'],
                    $row['title'],
                    $row['name'],
                    $row['candidates'] ?? $row['reason'] ?? '',
                ]);
            }
        }

        foreach ($report['users_designated_unassigned'] as $row) {
            fputcsv($handle, ['designated_unassigned', '', '', '', $row['designation'], $row['name'], 'user id '.$row['id']]);
        }

        fclose($handle);

        $this->info("Report written to {$path}");
    }
}
