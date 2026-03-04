<?php

namespace pragmatic\webtoolkit\domains\sync\jobs;

use Craft;
use craft\queue\BaseJob;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Throwable;

class SyncExportJob extends BaseJob
{
    public int $logId = 0;
    public string $exportMode = 'both';

    public function execute($queue): void
    {
        $log = PragmaticWebToolkit::$plugin->syncTransferLog;
        $log->update($this->logId, [
            'status' => 'running',
            'startedAt' => new \DateTimeImmutable(),
            'progressLabel' => 'Inspecting database',
        ]);

        try {
            $result = PragmaticWebToolkit::$plugin->syncPackageBuilder->buildPackage($this->exportMode, function(string $label, float $progress = 0.0) use ($queue, $log): void {
                $this->setProgress($queue, max(0.0, min(1.0, $progress)), $label);
                $log->update($this->logId, ['progressLabel' => $label]);
            });

            $settings = PragmaticWebToolkit::$plugin->syncSettings->get();
            $expiresAt = PragmaticWebToolkit::$plugin->syncExportArtifacts->expirationDate($settings);

            $log->update($this->logId, [
                'status' => 'success',
                'summary' => $result['summary'],
                'manifest' => $result['manifest'],
                'warnings' => $result['warnings'],
                'artifactPath' => $result['zipPath'],
                'artifactFilename' => $result['downloadName'],
                'artifactExpiresAt' => $expiresAt,
                'finishedAt' => new \DateTimeImmutable(),
                'progressLabel' => 'Finished',
            ]);
        } catch (Throwable $e) {
            $log->update($this->logId, [
                'status' => 'failed',
                'errorMessage' => $e->getMessage(),
                'finishedAt' => new \DateTimeImmutable(),
                'progressLabel' => 'Failed',
            ]);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Build Sync export package';
    }
}
