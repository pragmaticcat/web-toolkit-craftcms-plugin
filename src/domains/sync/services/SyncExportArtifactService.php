<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use craft\helpers\FileHelper;
use pragmatic\webtoolkit\domains\sync\models\SyncSettingsModel;

class SyncExportArtifactService
{
    public function artifactDirectory(): string
    {
        $path = rtrim(Craft::$app->getPath()->getTempPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pragmatic-sync' . DIRECTORY_SEPARATOR . 'exports';
        FileHelper::createDirectory($path);
        return $path;
    }

    public function artifactPathForFilename(string $filename): string
    {
        return $this->artifactDirectory() . DIRECTORY_SEPARATOR . $filename;
    }

    public function artifactExists(?string $path): bool
    {
        return is_string($path) && $path !== '' && is_file($path);
    }

    public function expirationDate(SyncSettingsModel $settings): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d hours', $settings->exportArtifactRetentionHours));
    }

    public function pruneExpiredArtifacts(): void
    {
        $dir = $this->artifactDirectory();
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < strtotime('-7 days')) {
                @unlink($path);
            }
        }
    }
}
