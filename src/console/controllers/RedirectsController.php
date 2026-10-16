<?php

namespace pragmatic\webtoolkit\console\controllers;

use Craft;
use craft\console\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\console\ExitCode;

/**
 * Console commands for importing redirect rules and pruning the 404 log.
 */
class RedirectsController extends Controller
{
    /**
     * Import redirect rules from a CSV file.
     *
     * @param string $file CSV path.
     * @param bool $dryRun Preview without writes.
     * @return int Console exit code.
     */
    public function actionImport(string $file, bool $dryRun = false): int
    {
        if (!is_file($file) || !is_readable($file)) {
            $this->stderr("CSV file is missing or unreadable: {$file}\n");
            return ExitCode::NOINPUT;
        }
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);
        if (!is_array($headers) || !in_array('source', $headers, true) || !in_array('destination', $headers, true)) {
            fclose($handle);
            $this->stderr("CSV must include source and destination columns.\n");
            return ExitCode::DATAERR;
        }
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, array_pad($data, count($headers), '')) ?: [];
        }
        fclose($handle);
        $results = PragmaticWebToolkit::$plugin->redirects->importRows($rows, $dryRun);
        $errors = 0;
        foreach ($results as $result) {
            $this->stdout(strtoupper($result['status']) . ': ' . ($result['row']['source'] ?? '') . ' — ' . $result['message'] . "\n");
            $errors += $result['status'] === 'error' ? 1 : 0;
        }
        return $errors > 0 ? ExitCode::DATAERR : ExitCode::OK;
    }

    /**
     * Prune expired 404 log rows.
     *
     * @return int Console exit code.
     */
    public function actionPrune404s(): int
    {
        $deleted = PragmaticWebToolkit::$plugin->redirects->prune404s();
        $this->stdout("Pruned {$deleted} expired 404 log rows.\n");
        return ExitCode::OK;
    }
}
