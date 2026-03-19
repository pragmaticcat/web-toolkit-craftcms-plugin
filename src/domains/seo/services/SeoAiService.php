<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\db\AssetQuery;
use craft\fields\PlainText;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;

class SeoAiService extends Component
{
    public function getAiSettings(int $siteId): array
    {
        $siteSettings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($siteId);

        return [
            'maxImageCandidates' => max(1, (int)($siteSettings['maxImageCandidates'] ?? 12)),
            'maxSourceTextChars' => max(500, (int)($siteSettings['maxSourceTextChars'] ?? 6000)),
        ];
    }

    public function buildAssetManualPrompt(Asset $asset, int $siteId): string
    {
        $strings = $this->promptStrings($siteId);
        $bundle = $this->buildAssetTransferBundle([$asset], $siteId);

        return $this->formatManualPrompt(
            $siteId,
            $strings['assetBatchTaskPrompt'],
            ['bundle' => $bundle],
            $this->assetTransferSchema()
        );
    }

    public function buildContentManualPrompt(Entry $entry, string $fieldHandle, int $siteId, string $aiInstructions = ''): string
    {
        $strings = $this->promptStrings($siteId);
        $bundle = $this->buildContentTransferBundle([[
            'entry' => $entry,
            'fieldHandle' => $fieldHandle,
            'aiInstructions' => $aiInstructions,
        ]], $siteId);
        $contextItems = $this->buildContentGenerationContextItems([[
            'entry' => $entry,
            'fieldHandle' => $fieldHandle,
            'aiInstructions' => $aiInstructions,
        ]], $siteId);

        return $this->formatManualPrompt(
            $siteId,
            $strings['contentBatchTaskPrompt'],
            [
                'bundle' => $bundle,
                'generationContext' => $contextItems,
            ],
            $this->contentTransferSchema()
        );
    }

    /**
     * @param array<int,array{entry:Entry,fieldHandle:string,aiInstructions?:string}> $rows
     */
    public function buildContentBatchManualPrompt(array $rows, int $siteId): string
    {
        $bundle = $this->buildContentTransferBundle($rows, $siteId);
        $strings = $this->promptStrings($siteId);
        $contextItems = $this->buildContentGenerationContextItems($rows, $siteId);

        $payload = [
            'bundle' => $bundle,
            'generationContext' => $contextItems,
        ];

        return $this->formatManualPrompt($siteId, $strings['contentBatchTaskPrompt'], $payload, $this->contentTransferSchema());
    }

    /**
     * @param array<int,array{entry:Entry,fieldHandle:string,aiInstructions?:string}> $rows
     */
    public function buildContentTransferBundle(array $rows, int $siteId): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $items = [];
        foreach ($rows as $row) {
            $entry = $row['entry'] ?? null;
            if (!$entry instanceof Entry) {
                continue;
            }

            $fieldHandle = trim((string)($row['fieldHandle'] ?? ''));
            if ($fieldHandle === '') {
                continue;
            }

            $value = $entry->getFieldValue($fieldHandle);
            $field = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
            if (!$value instanceof SeoFieldValue && $field instanceof SeoField) {
                $value = $field->normalizeValue($value, $entry);
            }
            if (!$value instanceof SeoFieldValue) {
                $value = new SeoFieldValue();
            }

            $items[] = [
                'entryId' => (int)$entry->id,
                'fieldHandle' => $fieldHandle,
                'aiInstructions' => trim((string)($row['aiInstructions'] ?? '')),
                'title' => trim((string)($value->title ?? '')),
                'description' => trim((string)($value->description ?? '')),
                'imageId' => $value->imageId ? (int)$value->imageId : null,
            ];
        }

        return [
            'version' => '1.0',
            'domain' => 'seo-content',
            'site' => [
                'id' => $siteId,
                'handle' => (string)($site?->handle ?? ''),
                'language' => (string)($site?->language ?? ''),
            ],
            'generatedAt' => (new \DateTime())->format(DATE_ATOM),
            'items' => $items,
        ];
    }

    public function buildAssetCommunicationItem(Asset $asset, int $siteId): array
    {
        $package = $this->buildAssetPromptPackage($asset, $siteId);

        return [
            'assetRef' => $this->buildAssetRef($asset),
            'communication' => [
                'taskPrompt' => $package['taskPrompt'],
                'schema' => $package['schema'],
                'payload' => $package['payload'],
            ],
            'values' => [
                'aiInstructions' => (string)($package['payload']['assetInstructions'] ?? ''),
                'title' => trim((string)$asset->title),
                'alt' => trim((string)($this->getAssetAltValue($asset) ?? '')),
            ],
        ];
    }

    /**
     * @param Asset[] $assets
     */
    public function buildAssetBatchManualPrompt(array $assets, int $siteId): string
    {
        $bundle = $this->buildAssetTransferBundle($assets, $siteId);
        $strings = $this->promptStrings($siteId);

        $payload = [
            'bundle' => $bundle,
        ];

        return $this->formatManualPrompt($siteId, $strings['assetBatchTaskPrompt'], $payload, $this->assetTransferSchema());
    }

    /**
     * @param Asset[] $assets
     */
    public function buildAssetBundle(array $assets, int $siteId): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $items = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $items[] = $this->buildAssetCommunicationItem($asset, $siteId);
        }

        return [
            'version' => '1.0',
            'domain' => 'seo-assets',
            'site' => [
                'id' => $siteId,
                'handle' => (string)($site?->handle ?? ''),
                'language' => (string)($site?->language ?? ''),
            ],
            'generatedAt' => (new \DateTime())->format(DATE_ATOM),
            'items' => $items,
        ];
    }

    /**
     * @param Asset[] $assets
     */
    public function buildAssetTransferBundle(array $assets, int $siteId): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $items = [];

        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $items[] = [
                'assetId' => (int)$asset->id,
                'aiInstructions' => PragmaticWebToolkit::$plugin->seoAssetAiInstructions->getInstructions((int)$asset->id, $siteId),
                'title' => trim((string)$asset->title),
                'alt' => trim((string)($this->getAssetAltValue($asset) ?? '')),
            ];
        }

        return [
            'version' => '2.0',
            'domain' => 'seo-assets',
            'site' => [
                'id' => $siteId,
                'handle' => (string)($site?->handle ?? ''),
                'language' => (string)($site?->language ?? ''),
            ],
            'generatedAt' => (new \DateTime())->format(DATE_ATOM),
            'items' => $items,
        ];
    }

    private function buildStrategyInstructions(int $siteId): string
    {
        $strategy = $this->buildStrategyContext($siteId);
        $strings = $this->promptStrings($siteId);
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $blocks = [
            $strings['strategyIntro'],
            '',
            $strings['preferredOutputLanguage'] . ': ' . ($site?->language ?? 'en'),
            $strings['jsonRule'],
        ];

        $fields = [
            $strings['fieldAudience'] => $strategy['audience'],
            $strings['fieldGoals'] => $strategy['businessGoals'],
            $strings['fieldTone'] => $strategy['tone'],
            $strings['fieldPrimaryKeywords'] => implode(', ', $strategy['primaryKeywords']),
            $strings['fieldSecondaryKeywords'] => implode(', ', $strategy['secondaryKeywords']),
            $strings['fieldBrandTerms'] => implode(', ', $strategy['brandTerms']),
            $strings['fieldForbiddenTerms'] => implode(', ', $strategy['forbiddenTerms']),
            $strings['fieldCtaStyle'] => $strategy['ctaStyle'],
            $strings['fieldNotes'] => $strategy['notes'],
        ];

        foreach ($fields as $label => $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            $blocks[] = '';
            $blocks[] = $label . ':';
            $blocks[] = $value;
        }

        return trim(implode("\n", $blocks));
    }

    public function buildStrategyContext(int $siteId): array
    {
        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($siteId);

        return [
            'audience' => trim((string)($settings['strategyAudience'] ?? '')),
            'businessGoals' => trim((string)($settings['strategyBusinessGoals'] ?? '')),
            'tone' => trim((string)($settings['strategyTone'] ?? '')),
            'primaryKeywords' => $this->splitList((string)($settings['strategyPrimaryKeywords'] ?? '')),
            'secondaryKeywords' => $this->splitList((string)($settings['strategySecondaryKeywords'] ?? '')),
            'brandTerms' => $this->splitList((string)($settings['strategyBrandTerms'] ?? '')),
            'forbiddenTerms' => $this->splitList((string)($settings['strategyForbiddenTerms'] ?? '')),
            'ctaStyle' => trim((string)($settings['strategyCtaStyle'] ?? '')),
            'notes' => trim((string)($settings['strategyNotes'] ?? '')),
        ];
    }

    private function buildAssetPromptPackage(Asset $asset, int $siteId): array
    {
        $strings = $this->promptStrings($siteId);
        $assetInstructions = PragmaticWebToolkit::$plugin->seoAssetAiInstructions->getInstructions((int)$asset->id, $siteId);

        return [
            'taskPrompt' => $strings['assetTaskPrompt'],
            'payload' => [
                'assetInstructions' => $assetInstructions,
                'asset' => $this->buildAssetContext($asset),
                'currentMetadata' => [
                    'title' => trim((string)$asset->title),
                    'alt' => $this->getAssetAltValue($asset) ?? '',
                ],
            ],
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'alt' => ['type' => 'string'],
                    'reasoning' => ['type' => 'string'],
                ],
                'required' => ['title', 'alt', 'reasoning'],
            ],
        ];
    }

    private function buildContentPromptPackage(Entry $entry, string $fieldHandle, int $siteId, string $aiInstructions = ''): array
    {
        $settings = $this->getAiSettings($siteId);
        $strings = $this->promptStrings($siteId);
        $seoField = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
        if (!$seoField instanceof SeoField) {
            throw new \RuntimeException($strings['invalidSeoField']);
        }

        $seoValue = $entry->getFieldValue($fieldHandle);
        if (!$seoValue instanceof SeoFieldValue) {
            $seoValue = $seoField->normalizeValue($seoValue, $entry);
        }
        if (!$seoValue instanceof SeoFieldValue) {
            $seoValue = new SeoFieldValue();
        }

        $candidateAssets = $this->collectCandidateAssets($entry, $siteId, (int)$settings['maxImageCandidates'], $seoValue);
        $candidateIds = array_map(static fn(array $candidate): int => (int)$candidate['id'], $candidateAssets);

        return [
            'taskPrompt' => $strings['contentTaskPrompt'],
            'payload' => [
                'entry' => [
                    'id' => (int)$entry->id,
                    'title' => (string)$entry->title,
                    'slug' => (string)$entry->slug,
                    'url' => (string)($entry->getUrl() ?? ''),
                    'section' => $entry->section?->name,
                    'sectionHandle' => $entry->section?->handle,
                    'entryType' => $entry->type?->name,
                    'entryTypeHandle' => $entry->type?->handle,
                    'postDate' => $entry->postDate?->format('c'),
                    'dateUpdated' => $entry->dateUpdated?->format('c'),
                ],
                'currentSeo' => [
                    'title' => $seoValue->title,
                    'description' => $seoValue->description,
                    'imageId' => $seoValue->imageId,
                ],
                'sourceContent' => [
                    'summaryText' => $this->extractEntrySourceText($entry, (int)$settings['maxSourceTextChars']),
                    'renderedPageText' => $this->extractUrlContentText((string)($entry->getUrl() ?? ''), (int)$settings['maxSourceTextChars']),
                ],
                'contentInstructions' => trim($aiInstructions),
                'imageCandidates' => $candidateAssets,
                'constraints' => [
                    'titleMaxChars' => 60,
                    'descriptionMaxChars' => 160,
                    'mustUseExistingImageId' => true,
                ],
            ],
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'imageId' => ['type' => 'integer', 'nullable' => true],
                    'reasoning' => ['type' => 'string'],
                ],
                'required' => ['title', 'description', 'reasoning'],
            ],
            'candidateIds' => $candidateIds,
        ];
    }

    /**
     * @param array<int,array{entry:Entry,fieldHandle:string,aiInstructions?:string}> $rows
     * @return array<int,array<string,mixed>>
     */
    private function buildContentGenerationContextItems(array $rows, int $siteId): array
    {
        $result = [];
        foreach ($rows as $row) {
            $entry = $row['entry'] ?? null;
            if (!$entry instanceof Entry) {
                continue;
            }

            $fieldHandle = trim((string)($row['fieldHandle'] ?? ''));
            if ($fieldHandle === '') {
                continue;
            }

            $aiInstructions = trim((string)($row['aiInstructions'] ?? ''));
            $package = $this->buildContentPromptPackage($entry, $fieldHandle, $siteId, $aiInstructions);
            $result[] = [
                'entryId' => (int)$entry->id,
                'fieldHandle' => $fieldHandle,
                'context' => $package['payload'],
            ];
        }

        return $result;
    }

    private function formatManualPrompt(int $siteId, string $taskPrompt, array $payload, array $schema): string
    {
        $strings = $this->promptStrings($siteId);
        $blocks = [];

        $blocks[] = $strings['manualEmbeddedInstructionsLabel'] . ':';
        $blocks[] = $this->buildStrategyInstructions($siteId);
        $blocks[] = '';

        $blocks[] = $strings['manualJsonDeliveryNote'];
        $blocks[] = '';
        $blocks[] = $strings['manualTaskLabel'] . ':';
        $blocks[] = $taskPrompt;
        $blocks[] = '';
        $blocks[] = $strings['manualSchemaLabel'] . ':';
        $blocks[] = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $blocks[] = '';
        $blocks[] = $strings['manualContextLabel'] . ':';
        $blocks[] = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return trim(implode("\n", $blocks));
    }

    private function contentTransferSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'string'],
                'domain' => ['type' => 'string'],
                'site' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'handle' => ['type' => 'string'],
                        'language' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'handle', 'language'],
                ],
                'generatedAt' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'entryId' => ['type' => 'integer'],
                            'fieldHandle' => ['type' => 'string'],
                            'aiInstructions' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'imageId' => ['type' => 'integer', 'nullable' => true],
                        ],
                        'required' => ['entryId', 'fieldHandle', 'aiInstructions', 'title', 'description'],
                    ],
                ],
            ],
            'required' => ['version', 'domain', 'site', 'generatedAt', 'items'],
        ];
    }

    private function assetTransferSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'string'],
                'domain' => ['type' => 'string'],
                'site' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'handle' => ['type' => 'string'],
                        'language' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'handle', 'language'],
                ],
                'generatedAt' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'assetId' => ['type' => 'integer'],
                            'aiInstructions' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'alt' => ['type' => 'string'],
                        ],
                        'required' => ['assetId', 'aiInstructions', 'title', 'alt'],
                    ],
                ],
            ],
            'required' => ['version', 'domain', 'site', 'generatedAt', 'items'],
        ];
    }

    /**
     * @return array<int, array{id:int,title:string,filename:string,url:string,alt:string}>
     */
    private function collectCandidateAssets(Entry $entry, int $siteId, int $limit, SeoFieldValue $seoValue): array
    {
        $assets = [];

        if ($seoValue->imageId) {
            $current = Asset::find()->id($seoValue->imageId)->siteId($siteId)->status(null)->one();
            if ($current instanceof Asset) {
                $assets[(int)$current->id] = $current;
            }
        }

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $entry->getFieldValue($field->handle);
            foreach ($this->extractAssetsFromValue($value) as $asset) {
                $assets[(int)$asset->id] = $asset;
                if (count($assets) >= $limit) {
                    break 2;
                }
            }
        }

        if (count($assets) < $limit) {
            $fallbackAssets = Asset::find()
                ->kind('image')
                ->siteId($siteId)
                ->status(null)
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit($limit)
                ->all();

            foreach ($fallbackAssets as $asset) {
                $assets[(int)$asset->id] = $asset;
                if (count($assets) >= $limit) {
                    break;
                }
            }
        }

        return array_map(fn(Asset $asset): array => $this->buildAssetCandidate($asset), array_slice(array_values($assets), 0, $limit));
    }

    /**
     * @return Asset[]
     */
    private function extractAssetsFromValue(mixed $value): array
    {
        if ($value instanceof Asset) {
            return [$value];
        }

        if ($value instanceof AssetQuery) {
            return array_values(array_filter($value->kind('image')->limit(10)->all(), static fn(mixed $asset): bool => $asset instanceof Asset));
        }

        if (is_iterable($value)) {
            $assets = [];
            foreach ($value as $item) {
                if ($item instanceof Asset) {
                    $assets[] = $item;
                }
            }

            return $assets;
        }

        return [];
    }

    private function buildAssetContext(Asset $asset): array
    {
        return [
            'id' => (int)$asset->id,
            'title' => trim((string)$asset->title),
            'filename' => (string)$asset->filename,
            'url' => $this->safeAssetUrl($asset),
            'kind' => (string)$asset->kind,
            'extension' => (string)$asset->extension,
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
        ];
    }

    private function buildAssetRef(Asset $asset): array
    {
        $volumeHandle = '';
        try {
            $volumeHandle = (string)($asset->getVolume()->handle ?? '');
        } catch (\Throwable) {
            $volumeHandle = '';
        }

        $folderPath = '';
        try {
            $folderPath = trim((string)($asset->getFolder()->path ?? ''), '/');
        } catch (\Throwable) {
            $folderPath = '';
        }

        return [
            'assetId' => (int)$asset->id,
            'filename' => (string)$asset->filename,
            'volumeHandle' => $volumeHandle,
            'folderPath' => $folderPath,
        ];
    }

    private function buildAssetCandidate(Asset $asset): array
    {
        return [
            'id' => (int)$asset->id,
            'title' => trim((string)$asset->title),
            'filename' => (string)$asset->filename,
            'url' => $this->safeAssetUrl($asset),
            'alt' => trim((string)($this->getAssetAltValue($asset) ?? '')),
        ];
    }

    private function safeAssetUrl(Asset $asset): string
    {
        try {
            return (string)($asset->getUrl() ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function getAssetAltValue(Asset $asset): ?string
    {
        if (method_exists($asset, 'getAltText')) {
            return (string)$asset->getAltText();
        }

        if ($asset->canGetProperty('alt')) {
            return (string)($asset->alt ?? '');
        }

        return null;
    }

    private function extractEntrySourceText(Entry $entry, int $limit): string
    {
        $strings = $this->promptStrings((int)($entry->siteId ?? Craft::$app->getSites()->getCurrentSite()->id));
        $chunks = [
            $strings['fieldEntryTitle'] . ': ' . trim((string)$entry->title),
        ];

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof SeoField || !$this->isSupportedTextField($field)) {
                continue;
            }

            $text = $this->extractTextFromValue($entry->getFieldValue($field->handle));
            if ($text === '') {
                continue;
            }

            $chunks[] = $field->name . ': ' . $text;
            if (mb_strlen(implode("\n\n", $chunks)) >= $limit) {
                break;
            }
        }

        return mb_substr(implode("\n\n", $chunks), 0, $limit);
    }

    private function extractUrlContentText(string $url, int $limit): string
    {
        $url = trim($url);
        if ($url === '' || $limit <= 0) {
            return '';
        }

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 8,
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => 'PragmaticWebToolkit/SEO',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);
            $response = $client->request('GET', $url);
            $contentType = strtolower((string)$response->getHeaderLine('Content-Type'));
            if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
                return '';
            }

            $html = (string)$response->getBody();
            if ($html === '') {
                return '';
            }

            // Keep only meaningful visible text from the rendered page.
            $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
            $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
            $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html) ?? $html;
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                return '';
            }

            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            return mb_substr(trim($text), 0, $limit);
        } catch (\Throwable) {
            return '';
        }
    }

    private function isSupportedTextField(FieldInterface $field): bool
    {
        if ($field instanceof PlainText) {
            return true;
        }

        return strtolower(get_class($field)) === 'craft\\ckeditor\\field';
    }

    private function extractTextFromValue(mixed $value): string
    {
        if (is_string($value)) {
            return $this->normalizeText($value);
        }

        if (is_scalar($value)) {
            return $this->normalizeText((string)$value);
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $text = $this->extractTextFromValue($item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return $this->normalizeText(implode(' ', $parts));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->normalizeText((string)$value);
        }

        return '';
    }

    private function normalizeText(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * @return string[]
     */
    private function splitList(string $value): array
    {
        $parts = preg_split('/[\n,]+/', $value) ?: [];

        return array_values(array_filter(array_map(static fn(string $item): string => trim($item), $parts), static fn(string $item): bool => $item !== ''));
    }

    private function promptStrings(int $siteId): array
    {
        $language = strtolower((string)(Craft::$app->getSites()->getSiteById($siteId)?->language ?? 'en'));
        if (str_starts_with($language, 'ca')) {
            return [
                'invalidSeoField' => 'El camp SEO no és vàlid.',
                'strategyIntro' => 'Aplica sempre aquesta estratègia en totes les respostes.',
                'preferredOutputLanguage' => 'Idioma de sortida preferit',
                'jsonRule' => 'Quan es demani un resultat estructurat, respon només amb JSON vàlid i sense text addicional.',
                'fieldAudience' => 'Audiència',
                'fieldGoals' => 'Objectius de negoci i SEO',
                'fieldTone' => 'To de veu',
                'fieldPrimaryKeywords' => 'Paraules clau principals',
                'fieldSecondaryKeywords' => 'Paraules clau secundàries',
                'fieldBrandTerms' => 'Termes de marca a incloure',
                'fieldForbiddenTerms' => 'Termes o afirmacions a evitar',
                'fieldCtaStyle' => 'Estil de CTA',
                'fieldNotes' => 'Notes addicionals',
                'fieldEntryTitle' => 'Títol de l\'entrada',
                'assetTaskPrompt' => 'Genera metadades SEO per a aquest asset. Retorna només JSON amb title, alt i reasoning.',
                'contentTaskPrompt' => 'Genera SEO per a aquesta entrada. Retorna només JSON amb title, description, imageId i reasoning.',
                'contentBatchTaskPrompt' => 'Genera SEO per a totes les entrades del bundle. Retorna només JSON amb exactament la mateixa estructura del bundle d\'entrada (version, domain, site, generatedAt, items). A cada item, completa entryId, fieldHandle, aiInstructions, title, description i imageId.',
                'manualEmbeddedInstructionsLabel' => 'Instruccions d\'estratègia incloses',
                'manualJsonDeliveryNote' => 'El JSON generat es mostrarà per facilitar el copy/paste i també estarà disponible per descarregar.',
                'manualTaskLabel' => 'Tasca',
                'manualSchemaLabel' => 'Esquema JSON requerit',
                'manualContextLabel' => 'Context JSON',
                'assetBatchTaskPrompt' => 'Genera metadades SEO per a tots els assets del bundle. Retorna només JSON amb exactament la mateixa estructura del bundle d\'entrada (version, domain, site, generatedAt, items). A cada item, completa només assetId, aiInstructions, title i alt.',
            ];
        }

        if (str_starts_with($language, 'es')) {
            return [
                'invalidSeoField' => 'El campo SEO no es válido.',
                'strategyIntro' => 'Aplica siempre esta estrategia en todas las respuestas.',
                'preferredOutputLanguage' => 'Idioma de salida preferido',
                'jsonRule' => 'Cuando se solicite un resultado estructurado, responde solo con JSON válido y sin texto adicional.',
                'fieldAudience' => 'Audiencia',
                'fieldGoals' => 'Objetivos de negocio y SEO',
                'fieldTone' => 'Tono de voz',
                'fieldPrimaryKeywords' => 'Palabras clave principales',
                'fieldSecondaryKeywords' => 'Palabras clave secundarias',
                'fieldBrandTerms' => 'Términos de marca a incluir',
                'fieldForbiddenTerms' => 'Términos o claims a evitar',
                'fieldCtaStyle' => 'Estilo de CTA',
                'fieldNotes' => 'Notas adicionales',
                'fieldEntryTitle' => 'Título de la entrada',
                'assetTaskPrompt' => 'Genera metadatos SEO para este asset. Devuelve solo JSON con title, alt y reasoning.',
                'contentTaskPrompt' => 'Genera SEO para esta entrada. Devuelve solo JSON con title, description, imageId y reasoning.',
                'contentBatchTaskPrompt' => 'Genera SEO para todas las entradas del bundle. Devuelve solo JSON con exactamente la misma estructura del bundle de entrada (version, domain, site, generatedAt, items). En cada item, completa entryId, fieldHandle, aiInstructions, title, description e imageId.',
                'manualEmbeddedInstructionsLabel' => 'Instrucciones de estrategia incluidas',
                'manualJsonDeliveryNote' => 'El JSON generado se mostrará para facilitar el copy/paste y también estará disponible para descargar.',
                'manualTaskLabel' => 'Tarea',
                'manualSchemaLabel' => 'Esquema JSON requerido',
                'manualContextLabel' => 'Contexto JSON',
                'assetBatchTaskPrompt' => 'Genera metadatos SEO para todos los assets del bundle. Devuelve solo JSON con exactamente la misma estructura del bundle de entrada (version, domain, site, generatedAt, items). En cada item, completa solo assetId, aiInstructions, title y alt.',
            ];
        }

        return [
            'invalidSeoField' => 'Invalid SEO field handle.',
            'strategyIntro' => 'Always apply this strategy in every response.',
            'preferredOutputLanguage' => 'Preferred output language',
            'jsonRule' => 'When a structured result is requested, return only valid JSON and no extra text.',
            'fieldAudience' => 'Audience',
            'fieldGoals' => 'Business and SEO goals',
            'fieldTone' => 'Tone of voice',
            'fieldPrimaryKeywords' => 'Primary keywords',
            'fieldSecondaryKeywords' => 'Secondary keywords',
            'fieldBrandTerms' => 'Brand terms to include',
            'fieldForbiddenTerms' => 'Terms or claims to avoid',
            'fieldCtaStyle' => 'CTA style',
            'fieldNotes' => 'Additional notes',
            'fieldEntryTitle' => 'Entry title',
            'assetTaskPrompt' => 'Generate SEO metadata for this asset. Return only JSON with title, alt and reasoning.',
            'contentTaskPrompt' => 'Generate SEO for this entry. Return only JSON with title, description, imageId and reasoning.',
            'contentBatchTaskPrompt' => 'Generate SEO for all entries in the bundle. Return only JSON with exactly the same structure as the input bundle (version, domain, site, generatedAt, items). For each item, fill entryId, fieldHandle, aiInstructions, title, description and imageId.',
            'manualEmbeddedInstructionsLabel' => 'Embedded strategy instructions',
            'manualJsonDeliveryNote' => 'The generated JSON will be shown for easy copy/paste and will also be available for download.',
            'manualTaskLabel' => 'Task',
            'manualSchemaLabel' => 'Required JSON schema',
            'manualContextLabel' => 'Context JSON',
            'assetBatchTaskPrompt' => 'Generate SEO metadata for all assets in the bundle. Return only JSON with exactly the same structure as the input bundle (version, domain, site, generatedAt, items). For each item, fill only assetId, aiInstructions, title, and alt.',
        ];
    }
}
