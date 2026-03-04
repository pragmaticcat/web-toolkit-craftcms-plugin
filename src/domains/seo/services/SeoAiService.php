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
            'gemFeatureEnabled' => !array_key_exists('enableGemFeature', $siteSettings) || !empty($siteSettings['enableGemFeature']),
            'maxImageCandidates' => max(1, (int)($siteSettings['maxImageCandidates'] ?? 12)),
            'maxSourceTextChars' => max(500, (int)($siteSettings['maxSourceTextChars'] ?? 6000)),
        ];
    }

    public function buildAssetManualPrompt(Asset $asset, int $siteId): string
    {
        $package = $this->buildAssetPromptPackage($asset, $siteId);

        return $this->formatManualPrompt($siteId, $package['taskPrompt'], $package['payload'], $package['schema']);
    }

    public function buildContentManualPrompt(Entry $entry, string $fieldHandle, int $siteId): string
    {
        $package = $this->buildContentPromptPackage($entry, $fieldHandle, $siteId);

        return $this->formatManualPrompt($siteId, $package['taskPrompt'], $package['payload'], $package['schema']);
    }

    public function buildGemInstructions(int $siteId): string
    {
        $strategy = $this->buildStrategyContext($siteId);
        $strings = $this->promptStrings($siteId);
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $blocks = [
            $strings['gemIntro'],
            '',
            $strings['gemOutputLanguage'] . ': ' . ($site?->language ?? 'en'),
            $strings['gemJsonRule'],
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

    private function buildContentPromptPackage(Entry $entry, string $fieldHandle, int $siteId): array
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
                ],
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

    private function formatManualPrompt(int $siteId, string $taskPrompt, array $payload, array $schema): string
    {
        $strings = $this->promptStrings($siteId);
        $settings = $this->getAiSettings($siteId);
        $blocks = [];

        if (!empty($settings['gemFeatureEnabled'])) {
            $blocks[] = $strings['manualIntroWithGem'];
        } else {
            $blocks[] = $strings['manualIntroStandalone'];
            $blocks[] = '';
            $blocks[] = $strings['manualEmbeddedInstructionsLabel'] . ':';
            $blocks[] = $this->buildGemInstructions($siteId);
        }

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
                'gemIntro' => 'Actua com l\'assistent SEO d\'aquest projecte. Aplica sempre aquesta estratègia en totes les respostes.',
                'gemOutputLanguage' => 'Idioma de sortida preferit',
                'gemJsonRule' => 'Quan es demani un resultat estructurat, respon només amb JSON vàlid i sense text addicional.',
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
                'manualIntroWithGem' => 'Fes servir aquest prompt dins d\'un xat amb el teu Gem SEO configurat amb les instruccions d\'estratègia.',
                'manualIntroStandalone' => 'Fes servir aquest prompt directament dins de Gemini. Inclou tota l\'estratègia necessària en aquest únic missatge.',
                'manualEmbeddedInstructionsLabel' => 'Instruccions d\'estratègia incloses',
                'manualTaskLabel' => 'Tasca',
                'manualSchemaLabel' => 'Esquema JSON requerit',
                'manualContextLabel' => 'Context JSON',
            ];
        }

        if (str_starts_with($language, 'es')) {
            return [
                'invalidSeoField' => 'El campo SEO no es válido.',
                'gemIntro' => 'Actúa como el asistente SEO de este proyecto. Aplica siempre esta estrategia en todas las respuestas.',
                'gemOutputLanguage' => 'Idioma de salida preferido',
                'gemJsonRule' => 'Cuando se solicite un resultado estructurado, responde solo con JSON válido y sin texto adicional.',
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
                'manualIntroWithGem' => 'Usa este prompt dentro de un chat con tu Gem SEO configurado con las instrucciones de estrategia.',
                'manualIntroStandalone' => 'Usa este prompt directamente dentro de Gemini. Incluye toda la estrategia necesaria en este único mensaje.',
                'manualEmbeddedInstructionsLabel' => 'Instrucciones de estrategia incluidas',
                'manualTaskLabel' => 'Tarea',
                'manualSchemaLabel' => 'Esquema JSON requerido',
                'manualContextLabel' => 'Contexto JSON',
            ];
        }

        return [
            'invalidSeoField' => 'Invalid SEO field handle.',
            'gemIntro' => 'Act as the SEO assistant for this project. Always apply this strategy in every response.',
            'gemOutputLanguage' => 'Preferred output language',
            'gemJsonRule' => 'When a structured result is requested, return only valid JSON and no extra text.',
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
            'manualIntroWithGem' => 'Use this prompt inside a chat with your SEO Gem configured with the strategy instructions.',
            'manualIntroStandalone' => 'Use this prompt directly in Gemini. It includes all required strategy instructions in this single message.',
            'manualEmbeddedInstructionsLabel' => 'Embedded strategy instructions',
            'manualTaskLabel' => 'Task',
            'manualSchemaLabel' => 'Required JSON schema',
            'manualContextLabel' => 'Context JSON',
        ];
    }
}
