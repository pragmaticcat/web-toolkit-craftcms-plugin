<?php

namespace pragmatic\webtoolkit\jobs;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Support\Str;

class RefreshSeoSlugsJob extends BaseJob
{
    public int $siteId = 0;
    public array $entryIds = [];
    public bool $cleanSpecialChars = true;
    public int $batchNumber = 1;
    public int $totalBatches = 1;

    protected function execute(): void
    {
        $total = count($this->entryIds);
        $elements = app(\CraftCms\Cms\Element\Elements::class);
        foreach ($this->entryIds as $index => $entryId) {
            $entry = $elements->getElementById((int)$entryId, Entry::class, $this->siteId);
            if ($entry instanceof Entry) {
                $title = (string)$entry->title;
                if ($this->cleanSpecialChars) {
                    $converted = Str::ascii($title);
                    $title = is_string($converted) ? $converted : $title;
                }
                $entry->slug = ElementHelper::generateSlug($title);
                if ($elements->saveElement($entry, false, false, false)) {
                    $elements->updateElementSlugAndUri($entry, false, false);
                }
            }
            $this->reportProgress($total ? min(1, ($index + 1) / $total) : 1, sprintf('%d de %d', $index + 1, $total));
        }
    }

    protected function defaultDescription(): string
    {
        return sprintf('Actualizando slugs SEO (lote %d de %d)', $this->batchNumber, $this->totalBatches);
    }
}
