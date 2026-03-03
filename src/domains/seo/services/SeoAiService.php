<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\db\AssetQuery;
use craft\fields\PlainText;
use craft\helpers\App;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;

class SeoAiService extends Component
{
    public function isAvailableForSite(int $siteId): bool
    {
        $settings = $this->getAiSettings($siteId);

        return !empty($settings['enabled']) && $settings['apiKey'] !== '' && $settings['model'] !== '';
    }

    public function availabilityErrorForSite(int $siteId): ?string
    {
        $settings = $this->getAiSettings($siteId);
        if (empty($settings['enabled'])) {
            return 'AI suggestions are disabled for this site.';
        }

        if ($settings['apiKey'] === '') {
            return 'OpenAI API key is not configured.';
        }

        if ($settings['model'] === '') {
            return 'OpenAI model is not configured.';
        }

        return null;
    }

    public function generateAssetSuggestion(Asset $asset, int $siteId): array
    {
        $settings = $this->getAiSettings($siteId);
        $this->assertAvailable($settings);

        $payload = [
            'strategy' => $this->buildStrategyContext($siteId),
            'asset' => $this->buildAssetContext($asset),
            'currentMetadata' => [
                'title' => trim((string)$asset->title),
                'alt' => $this->getAssetAltValue($asset) ?? '',
            ],
        ];

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
                'alt' => ['type' => 'string'],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['title', 'alt', 'reasoning'],
        ];

        $result = $this->callOpenAi(
            $settings,
            'Generate SEO-friendly asset metadata. Return JSON only. ' .
            'The title should help editors identify the image. ' .
            'The alt text should describe the visible image naturally and concretely. ' .
            'Do not keyword-stuff. Do not invent unsupported details.',
            $payload,
            $schema,
            'seo_asset_metadata'
        );

        return $this->validateAssetSuggestion($result);
    }

    public function generateContentSuggestion(Entry $entry, string $fieldHandle, int $siteId): array
    {
        $settings = $this->getAiSettings($siteId);
        $this->assertAvailable($settings);

        $seoField = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
        if (!$seoField instanceof SeoField) {
            throw new \RuntimeException('Invalid SEO field handle.');
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

        $payload = [
            'strategy' => $this->buildStrategyContext($siteId),
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
            ],
            'imageCandidates' => $candidateAssets,
            'constraints' => [
                'titleMaxChars' => 60,
                'descriptionMaxChars' => 160,
                'mustUseExistingImageId' => true,
            ],
        ];

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'imageId' => ['type' => ['integer', 'null']],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['title', 'description', 'imageId', 'reasoning'],
        ];

        $result = $this->callOpenAi(
            $settings,
            'Generate an SEO title and meta description for a Craft CMS entry. ' .
            'Use the provided site strategy. Prefer concise, search-useful phrasing over generic marketing copy. ' .
            'Choose only from the provided image candidates and consider their saved title and alt text when deciding. ' .
            'Return JSON only.',
            $payload,
            $schema,
            'seo_content_suggestion'
        );

        return $this->validateContentSuggestion($result, $candidateIds);
    }

    public function getAiSettings(int $siteId): array
    {
        $siteSettings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($siteId);

        return [
            'enabled' => !empty($siteSettings['enableAiSuggestions']),
            'apiKey' => $this->resolveApiKey((string)($siteSettings['openAiApiKeyEnv'] ?? '')),
            'model' => trim((string)($siteSettings['openAiModel'] ?? '')),
            'maxImageCandidates' => max(1, (int)($siteSettings['maxImageCandidates'] ?? 12)),
            'maxSourceTextChars' => max(500, (int)($siteSettings['maxSourceTextChars'] ?? 6000)),
        ];
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

    public function validateContentSuggestion(array $data, array $candidateAssetIds): array
    {
        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($title === '' || $description === '') {
            throw new \RuntimeException('AI returned an incomplete SEO suggestion.');
        }

        $imageId = $data['imageId'] ?? null;
        if (!is_int($imageId) && !(is_string($imageId) && ctype_digit($imageId))) {
            $imageId = null;
        }
        $imageId = $imageId !== null ? (int)$imageId : null;
        if ($imageId !== null && !in_array($imageId, $candidateAssetIds, true)) {
            $imageId = null;
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'description' => mb_substr($description, 0, 500),
            'imageId' => $imageId,
            'reasoning' => mb_substr(trim((string)($data['reasoning'] ?? '')), 0, 300),
        ];
    }

    public function validateAssetSuggestion(array $data): array
    {
        $title = trim((string)($data['title'] ?? ''));
        $alt = trim((string)($data['alt'] ?? ''));
        if ($title === '' || $alt === '') {
            throw new \RuntimeException('AI returned incomplete asset metadata.');
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'alt' => mb_substr($alt, 0, 500),
            'reasoning' => mb_substr(trim((string)($data['reasoning'] ?? '')), 0, 300),
        ];
    }

    private function assertAvailable(array $settings): void
    {
        if (empty($settings['enabled'])) {
            throw new \RuntimeException('AI suggestions are disabled for this site.');
        }

        if (($settings['apiKey'] ?? '') === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        if (($settings['model'] ?? '') === '') {
            throw new \RuntimeException('OpenAI model is not configured.');
        }
    }

    private function callOpenAi(array $settings, string $systemPrompt, array $payload, array $schema, string $schemaName): array
    {
        $client = Craft::createGuzzleClient();
        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['apiKey'],
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $settings['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ],
        ]);

        $decoded = json_decode((string)$response->getBody(), true);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('OpenAI returned an empty response.');
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw new \RuntimeException('OpenAI returned invalid JSON.');
        }

        return $json;
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
        $chunks = [
            'Title: ' . trim((string)$entry->title),
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

    private function resolveApiKey(string $envReference): string
    {
        $reference = trim($envReference);
        if ($reference === '') {
            return '';
        }

        $parsed = App::parseEnv($reference);
        if (is_string($parsed) && $parsed !== '' && $parsed !== $reference) {
            return trim($parsed);
        }

        $normalized = ltrim($reference, '$');
        $resolved = App::env($normalized);
        if (!is_string($resolved)) {
            return '';
        }

        return trim($resolved);
    }
}
