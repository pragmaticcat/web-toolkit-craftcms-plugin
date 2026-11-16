<?php

namespace pragmatic\webtoolkit\console\commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use pragmatic\webtoolkit\PragmaticWebToolkit;

/**
 * Importa regles de redirecció des d’un CSV amb les columnes `source` i `destination`.
 */
class RedirectsImportCommand extends Command
{
    use CraftCommand;

    protected $signature = 'pragmatic-web-toolkit:redirects:import {file : CSV path} {--dry-run : Validate the CSV without saving rules}';

    protected $description = 'Imports redirect rules from a CSV file.';

    protected $aliases = ['pragmatic-web-toolkit/redirects/import'];

    public function handle(): int
    {
        $file = (string)$this->argument('file');
        if (!is_file($file) || !is_readable($file)) {
            $this->error("CSV file is missing or unreadable: {$file}");
            return 66; // EX_NOINPUT
        }

        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);
        if (!is_array($headers) || !in_array('source', $headers, true) || !in_array('destination', $headers, true)) {
            fclose($handle);
            $this->error('CSV must include source and destination columns.');
            return 65; // EX_DATAERR
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, array_pad($data, count($headers), '')) ?: [];
        }
        fclose($handle);

        $results = PragmaticWebToolkit::$plugin->redirects->importRows($rows, (bool)$this->option('dry-run'));
        $errors = 0;
        foreach ($results as $result) {
            $this->line(strtoupper($result['status']) . ': ' . ($result['row']['source'] ?? '') . ' — ' . $result['message']);
            $errors += $result['status'] === 'error' ? 1 : 0;
        }

        return $errors > 0 ? 65 : self::SUCCESS;
    }
}
