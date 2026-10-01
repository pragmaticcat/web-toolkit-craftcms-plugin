<?php

namespace pragmatic\webtoolkit\jobs;

use Craft;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\queue\BaseJob;
use yii\helpers\Inflector;

class RefreshSeoSlugsJob extends BaseJob
{
    public int $siteId = 0;
    public array $entryIds = [];
    public bool $cleanSpecialChars = true;
    public int $batchNumber = 1;
    public int $totalBatches = 1;

    public function execute($queue): void
    {
        $total = count($this->entryIds);
        $elements = Craft::$app->getElements();
        foreach ($this->entryIds as $index => $entryId) {
            $entry = $elements->getElementById((int)$entryId, Entry::class, $this->siteId);
            if ($entry instanceof Entry) {
                $title = (string)$entry->title;
                if ($this->cleanSpecialChars) {
                    $converted = method_exists(Inflector::class, 'transliterate') ? Inflector::transliterate($title) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
                    $title = is_string($converted) ? $converted : $title;
                }
                $entry->slug = ElementHelper::generateSlug($title);
                if ($elements->saveElement($entry, false, false, false)) {
                    $elements->updateElementSlugAndUri($entry, false, false);
                }
            }
            $this->setProgress($queue, $total ? min(1, ($index + 1) / $total) : 1, sprintf('%d de %d', $index + 1, $total));
        }
    }

    protected function defaultDescription(): ?string
    {
        return sprintf('Actualizando slugs SEO (lote %d de %d)', $this->batchNumber, $this->totalBatches);
    }
}
