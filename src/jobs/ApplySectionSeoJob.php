<?php

namespace pragmatic\webtoolkit\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;

class ApplySectionSeoJob extends BaseJob
{
    public int $siteId = 0;
    public int $sectionId = 0;
    /** @var int[] */
    public array $entryIds = [];
    public int $batchNumber = 1;
    public int $totalBatches = 1;

    /** @var array{title?:string,description?:string,imageId?:int|null,imageFieldHandle?:string} */
    public array $values = [];

    public function execute($queue): void
    {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $this->entryIds))));
        $total = count($entryIds);
        $elements = Craft::$app->getElements();

        foreach ($entryIds as $index => $entryId) {
            $entry = $elements->getElementById((int)$entryId, Entry::class, $this->siteId);
            if (!$entry instanceof Entry) {
                $this->setJobProgress($queue, $index + 1, $total);
                continue;
            }

            $seoField = null;
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof SeoField) {
                    $seoField = $field;
                    break;
                }
            }
            if (!$seoField instanceof SeoField) {
                $this->setJobProgress($queue, $index + 1, $total);
                continue;
            }

            $current = $entry->getFieldValue($seoField->handle);
            if (!$current instanceof SeoFieldValue) {
                $current = $seoField->normalizeValue($current, $entry);
            }
            if (!$current instanceof SeoFieldValue) {
                $current = new SeoFieldValue();
            }

            $entry->setFieldValue($seoField->handle, [
                'title' => trim((string)($this->values['title'] ?? '')),
                'description' => trim((string)($this->values['description'] ?? '')),
                'imageId' => !empty($this->values['imageId']) ? (int)$this->values['imageId'] : null,
                'imageFieldHandle' => trim((string)($this->values['imageFieldHandle'] ?? '')),
                'sitemapEnabled' => $current->sitemapEnabled,
                'sitemapIncludeImages' => $current->sitemapIncludeImages,
            ]);
            $elements->saveElement($entry, false, false, false);
            $this->setJobProgress($queue, $index + 1, $total);

            if (($index + 1) % 25 === 0) {
                gc_collect_cycles();
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return sprintf(
            'Aplicando valores SEO de la section (lote %d de %d)',
            $this->batchNumber,
            $this->totalBatches
        );
    }

    private function setJobProgress(mixed $queue, int $processed, int $total): void
    {
        if ($total === 0) {
            $this->setProgress($queue, 1, 'No hay entries para actualizar');
            return;
        }

        $this->setProgress(
            $queue,
            min(1, $processed / $total),
            sprintf('%d de %d entries', $processed, $total)
        );
    }
}
