<?php

namespace pragmatic\webtoolkit\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use craft\helpers\FileHelper;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\helpers\Inflector;
use yii\queue\Queue;

class SeoAssetsImportJob extends BaseJob
{
    public int $siteId = 0;

    /**
     * @var array<int,array<string,mixed>>
     */
    public array $items = [];

    public string $statusToken = '';

    public static function statusCacheKey(string $token): string
    {
        return 'pwt:seo:assets-import:status:' . $token;
    }

    public static function previewCacheKey(string $token): string
    {
        return 'pwt:seo:assets-import:preview:' . $token;
    }

    public static function statusFilePath(string $token): string
    {
        $runtime = Craft::$app->getPath()->getRuntimePath();
        return $runtime . DIRECTORY_SEPARATOR . 'pragmatic-web-toolkit' . DIRECTORY_SEPARATOR . 'seo-assets-import-status-' . $token . '.json';
    }

    public static function previewFilePath(string $token): string
    {
        $runtime = Craft::$app->getPath()->getRuntimePath();
        return $runtime . DIRECTORY_SEPARATOR . 'pragmatic-web-toolkit' . DIRECTORY_SEPARATOR . 'seo-assets-import-preview-' . $token . '.json';
    }

    public function execute($queue): void
    {
        $total = count($this->items);
        $processed = 0;
        $applied = 0;
        $errors = [];
        $elements = Craft::$app->getElements();
        $startedAt = date(DATE_ATOM);

        $this->persistStatus([
            'state' => 'running',
            'message' => 'Import in progress.',
            'total' => $total,
            'processed' => 0,
            'applied' => 0,
            'errors' => [],
            'startedAt' => $startedAt,
            'finishedAt' => null,
            'updatedAt' => $startedAt,
        ]);

        try {
            foreach ($this->items as $index => $item) {
                $processed = $index + 1;
                if (!is_array($item)) {
                    $errors[] = 'Invalid item at position ' . $processed . '.';
                    $this->setProgressSafe($queue, $processed, $total);
                    $this->persistStatus($this->buildRunningStatus($total, $processed, $applied, $errors, $startedAt));
                    continue;
                }

                $assetId = (int)($item['assetId'] ?? 0);
                $after = (array)($item['after'] ?? []);
                if ($assetId <= 0) {
                    $errors[] = 'Missing assetId at position ' . $processed . '.';
                    $this->setProgressSafe($queue, $processed, $total);
                    $this->persistStatus($this->buildRunningStatus($total, $processed, $applied, $errors, $startedAt));
                    continue;
                }

                $asset = $elements->getElementById($assetId, Asset::class, $this->siteId);
                if (!$asset instanceof Asset) {
                    $errors[] = "Asset #{$assetId} could not be loaded.";
                    $this->setProgressSafe($queue, $processed, $total);
                    $this->persistStatus($this->buildRunningStatus($total, $processed, $applied, $errors, $startedAt));
                    continue;
                }

                $title = trim((string)($after['title'] ?? ''));
                $alt = trim((string)($after['alt'] ?? ''));
                $aiInstructions = trim((string)($after['aiInstructions'] ?? ''));

                PragmaticWebToolkit::$plugin->seoAssetAiInstructions->saveInstructions($assetId, $this->siteId, $aiInstructions);

                $titleChanged = $title !== trim((string)$asset->title);
                $asset->title = $title;
                $this->setAssetAltValue($asset, $alt);

                if (!$elements->saveElement($asset, true, false, false)) {
                    $assetErrors = $asset->getFirstErrors();
                    if (!empty($assetErrors)) {
                        $errors[] = "Asset #{$assetId}: " . implode(' ', array_values($assetErrors));
                    } else {
                        $errors[] = "Asset #{$assetId} could not be saved.";
                    }

                    $this->setProgressSafe($queue, $processed, $total);
                    $this->persistStatus($this->buildRunningStatus($total, $processed, $applied, $errors, $startedAt));
                    continue;
                }

                if ($titleChanged) {
                    $renameError = $this->renameAssetFilenameFromTitle($asset, $title);
                    if ($renameError !== null) {
                        $errors[] = "Asset #{$assetId}: {$renameError}";
                    }
                }

                $applied++;
                $this->setProgressSafe($queue, $processed, $total);
                $this->persistStatus($this->buildRunningStatus($total, $processed, $applied, $errors, $startedAt));
            }

            $this->persistStatus([
                'state' => 'completed',
                'message' => 'Import completed.',
                'total' => $total,
                'processed' => $processed,
                'applied' => $applied,
                'skipped' => max(0, $total - $applied),
                'errors' => array_slice($errors, 0, 50),
                'startedAt' => $startedAt,
                'finishedAt' => date(DATE_ATOM),
                'updatedAt' => date(DATE_ATOM),
            ]);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            $this->persistStatus([
                'state' => 'failed',
                'message' => 'Import failed.',
                'total' => $total,
                'processed' => $processed,
                'applied' => $applied,
                'skipped' => max(0, $total - $applied),
                'errors' => array_slice($errors, 0, 50),
                'startedAt' => $startedAt,
                'finishedAt' => date(DATE_ATOM),
                'updatedAt' => date(DATE_ATOM),
            ]);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('pragmatic-web-toolkit', 'jobs.seo-assets-import-job.import-seo-assets-json');
    }

    /**
     * @param string[] $errors
     * @return array<string,mixed>
     */
    private function buildRunningStatus(int $total, int $processed, int $applied, array $errors, string $startedAt): array
    {
        return [
            'state' => 'running',
            'message' => 'Import in progress.',
            'total' => $total,
            'processed' => $processed,
            'applied' => $applied,
            'errors' => array_slice($errors, 0, 50),
            'startedAt' => $startedAt,
            'finishedAt' => null,
            'updatedAt' => date(DATE_ATOM),
        ];
    }

    private function persistStatus(array $status): void
    {
        if ($this->statusToken === '') {
            return;
        }

        $path = self::statusFilePath($this->statusToken);
        FileHelper::createDirectory(dirname($path));
        file_put_contents($path, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function setProgressSafe(Queue $queue, int $processed, int $total): void
    {
        if ($total <= 0) {
            $this->setProgress($queue, 1);
            return;
        }

        $this->setProgress($queue, min(1, $processed / $total));
    }

    private function setAssetAltValue(Asset $asset, string $value): void
    {
        if ($asset->canSetProperty('alt')) {
            $asset->alt = $value;
        }
    }

    private function renameAssetFilenameFromTitle(Asset $asset, string $title): ?string
    {
        $targetFilename = $this->buildSeoFilenameFromTitle($asset, $title);
        if ($targetFilename === $asset->filename) {
            return null;
        }

        $folder = $asset->getFolder();
        if ($folder === null) {
            return 'The asset folder could not be resolved for filename renaming.';
        }

        try {
            Craft::$app->getAssets()->moveAsset($asset, $folder, $targetFilename);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function buildSeoFilenameFromTitle(Asset $asset, string $title): string
    {
        $baseName = trim(Inflector::slug($title));
        if ($baseName === '') {
            $currentBaseName = pathinfo($asset->filename, PATHINFO_FILENAME);
            $baseName = trim(Inflector::slug($currentBaseName));
        }
        if ($baseName === '') {
            $baseName = 'asset-' . (int)$asset->id;
        }

        $extension = strtolower((string)$asset->extension);
        if ($extension === '') {
            $extension = strtolower((string)pathinfo($asset->filename, PATHINFO_EXTENSION));
        }

        $filename = $extension !== '' ? $baseName . '.' . $extension : $baseName;
        $folder = $asset->getFolder();
        if ($folder !== null) {
            try {
                $filename = Craft::$app->getAssets()->getNameReplacementInFolder($filename, $folder->id);
            } catch (\Throwable) {
                // Fall back to generated filename.
            }
        }

        return $filename;
    }
}
