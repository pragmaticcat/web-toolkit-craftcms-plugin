<?php

namespace pragmatic\webtoolkit\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;

class SaveSeoEntriesJob extends BaseJob
{
    public int $siteId = 0;
    public string $mode = 'content';
    public array $items = [];
    public int $batchNumber = 1;
    public int $totalBatches = 1;

    public function execute($queue): void
    {
        $total = count($this->items);
        $elements = Craft::$app->getElements();
        foreach ($this->items as $index => $row) {
            $entryId = (int)($row['entryId'] ?? 0);
            $fieldHandle = trim((string)($row['fieldHandle'] ?? ''));
            $entry = $entryId ? $elements->getElementById($entryId, Entry::class, $this->siteId) : null;
            if (!$entry instanceof Entry || $fieldHandle === '') {
                $this->progress($queue, $index + 1, $total);
                continue;
            }

            $field = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
            $current = $entry->getFieldValue($fieldHandle);
            if (!$current instanceof SeoFieldValue && $field instanceof SeoField) {
                $current = $field->normalizeValue($current, $entry);
            }
            if (!$current instanceof SeoFieldValue) {
                $current = new SeoFieldValue();
            }

            if ($this->mode === 'sitemap') {
                $values = [
                    'title' => $current->title,
                    'description' => $current->description,
                    'imageId' => $current->imageId,
                    'imageFieldHandle' => $current->imageFieldHandle,
                    'sitemapEnabled' => !empty($row['sitemapEnabled']),
                    'sitemapIncludeImages' => !empty($row['sitemapIncludeImages']),
                ];
            } else {
                $input = (array)($row['values'] ?? $row['after'] ?? []);
                $values = [
                    'title' => trim((string)($input['title'] ?? '')),
                    'description' => trim((string)($input['description'] ?? '')),
                    'imageId' => $this->normalizeId($input['imageId'] ?? null),
                    'imageFieldHandle' => trim((string)($input['imageFieldHandle'] ?? '')),
                    'sitemapEnabled' => $current->sitemapEnabled,
                    'sitemapIncludeImages' => $current->sitemapIncludeImages,
                ];
                PragmaticWebToolkit::$plugin->seoContentAiInstructions->saveInstructions(
                    $entryId,
                    $fieldHandle,
                    $this->siteId,
                    trim((string)($row['aiInstructions'] ?? $input['aiInstructions'] ?? ''))
                );
            }

            $entry->setFieldValue($fieldHandle, $values);
            $elements->saveElement($entry, false, false, false);
            $this->progress($queue, $index + 1, $total);
        }
    }

    protected function defaultDescription(): ?string
    {
        return sprintf('Guardando SEO (%s, lote %d de %d)', $this->mode, $this->batchNumber, $this->totalBatches);
    }

    private function normalizeId(mixed $value): ?int
    {
        while (is_array($value)) {
            $value = reset($value);
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private function progress(mixed $queue, int $processed, int $total): void
    {
        $this->setProgress($queue, $total ? min(1, $processed / $total) : 1, sprintf('%d de %d', $processed, $total));
    }
}
