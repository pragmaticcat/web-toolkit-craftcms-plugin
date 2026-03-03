<?php

namespace pragmatic\webtoolkit\domains\sync\jobs;

use Craft;
use craft\helpers\FileHelper;
use craft\queue\BaseJob;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Throwable;

class SyncImportJob extends BaseJob
{
    public int $logId = 0;
    public string $stagingPath = '';
    public string $packageName = '';

    public function execute($queue): void
    {
        $log = PragmaticWebToolkit::$plugin->syncTransferLog;
        $log->update($this->logId, [
            'status' => 'running',
            'startedAt' => new \DateTimeImmutable(),
            'progressLabel' => 'Validating package',
        ]);

        try {
            $preflight = PragmaticWebToolkit::$plugin->syncPackageInspector->inspectStagingPath($this->stagingPath, $this->packageName);
            if (!empty($preflight['errors'])) {
                throw new \RuntimeException(implode("\n", $preflight['errors']));
            }

            $log->update($this->logId, [
                'progressLabel' => 'Restoring database tables',
            ]);

            $result = PragmaticWebToolkit::$plugin->syncPackageImport->importStagedPackage(
                $this->stagingPath,
                function(string $label, float $progress = 0.0) use ($queue): void {
                    $this->setProgress($queue, max(0.0, min(1.0, $progress)), $label);
                }
            );

            $finalAttributes = [
                'status' => 'success',
                'summary' => array_merge($preflight['summary'], $result),
                'manifest' => $preflight['manifest']->toArray(),
                'warnings' => array_merge($preflight['warnings'], $preflight['manifest']->warnings),
                'finishedAt' => new \DateTimeImmutable(),
                'progressLabel' => 'Finished',
            ];

            if (!$log->update($this->logId, $finalAttributes)) {
                $log->create(
                    'import',
                    'success',
                    $this->packageName,
                    (array)$finalAttributes['summary'],
                    null,
                    [
                        'manifest' => (array)$finalAttributes['manifest'],
                        'warnings' => (array)$finalAttributes['warnings'],
                        'finishedAt' => $finalAttributes['finishedAt'],
                        'progressLabel' => 'Finished',
                    ]
                );
            }
        } catch (Throwable $e) {
            $errorAttributes = [
                'status' => 'failed',
                'errorMessage' => $e->getMessage(),
                'finishedAt' => new \DateTimeImmutable(),
                'progressLabel' => 'Failed',
            ];

            if (!$log->update($this->logId, $errorAttributes)) {
                $log->create(
                    'import',
                    'failed',
                    $this->packageName,
                    [],
                    $e->getMessage(),
                    [
                        'finishedAt' => $errorAttributes['finishedAt'],
                        'progressLabel' => 'Failed',
                    ]
                );
            }
            throw $e;
        } finally {
            if ($this->stagingPath !== '' && is_dir($this->stagingPath)) {
                FileHelper::removeDirectory($this->stagingPath);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Run Sync import package';
    }
}
