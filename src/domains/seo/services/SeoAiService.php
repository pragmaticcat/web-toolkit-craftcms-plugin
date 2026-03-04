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

    public function isEnabledForSite(int $siteId): bool
    {
        $settings = $this->getAiSettings($siteId);

        return !empty($settings['enabled']);
    }

    public function requiresManualPromptForSite(int $siteId): bool
    {
        $settings = $this->getAiSettings($siteId);

        return !empty($settings['enabled']) && $settings['apiKey'] === '';
    }

    public function availabilityErrorForSite(int $siteId): ?string
    {
        $settings = $this->getAiSettings($siteId);
        $strings = $this->promptStrings($siteId);
        if (empty($settings['enabled'])) {
            return $strings['aiDisabled'];
        }

        if ($settings['apiKey'] === '') {
            return $strings['apiKeyMissing'];
        }

        if ($settings['model'] === '') {
            return $strings['modelMissing'];
        }

        return null;
    }

    public function generateAssetSuggestion(Asset $asset, int $siteId): array
    {
        $settings = $this->getAiSettings($siteId);
        $this->assertAvailable($settings, $siteId);
        $package = $this->buildAssetPromptPackage($asset, $siteId);

        $result = $this->callGemini(
            $settings,
            $package['systemPrompt'],
            $package['payload'],
            $package['schema'],
            $siteId
        );

        return $this->validateAssetSuggestion($result, $siteId);
    }

    public function generateContentSuggestion(Entry $entry, string $fieldHandle, int $siteId): array
    {
        $settings = $this->getAiSettings($siteId);
        $this->assertAvailable($settings, $siteId);
        $package = $this->buildContentPromptPackage($entry, $fieldHandle, $siteId);

        $result = $this->callGemini(
            $settings,
            $package['systemPrompt'],
            $package['payload'],
            $package['schema'],
            $siteId
        );

        return $this->validateContentSuggestion($result, $package['candidateIds'], $siteId);
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

    public function getAiSettings(int $siteId): array
    {
        $siteSettings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($siteId);

        return [
            'enabled' => !empty($siteSettings['enableAiSuggestions']),
            'gemFeatureEnabled' => !array_key_exists('enableGemFeature', $siteSettings) || !empty($siteSettings['enableGemFeature']),
            'apiKey' => $this->resolveApiKey((string)($siteSettings['openAiApiKeyEnv'] ?? '')),
            'model' => trim((string)($siteSettings['openAiModel'] ?? 'gemini-2.5-flash')),
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

    public function validateContentSuggestion(array $data, array $candidateAssetIds, int $siteId): array
    {
        $strings = $this->promptStrings($siteId);
        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($title === '' || $description === '') {
            throw new \RuntimeException($strings['contentIncomplete']);
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

    public function validateAssetSuggestion(array $data, int $siteId): array
    {
        $strings = $this->promptStrings($siteId);
        $title = trim((string)($data['title'] ?? ''));
        $alt = trim((string)($data['alt'] ?? ''));
        if ($title === '' || $alt === '') {
            throw new \RuntimeException($strings['assetIncomplete']);
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'alt' => mb_substr($alt, 0, 500),
            'reasoning' => mb_substr(trim((string)($data['reasoning'] ?? '')), 0, 300),
        ];
    }

    private function assertAvailable(array $settings, int $siteId): void
    {
        $strings = $this->promptStrings($siteId);
        if (empty($settings['enabled'])) {
            throw new \RuntimeException($strings['aiDisabled']);
        }

        if (($settings['apiKey'] ?? '') === '') {
            throw new \RuntimeException($strings['apiKeyMissing']);
        }

        if (($settings['model'] ?? '') === '') {
            throw new \RuntimeException($strings['modelMissing']);
        }
    }

    private function buildAssetPromptPackage(Asset $asset, int $siteId): array
    {
        $strings = $this->promptStrings($siteId);

        return [
            'systemPrompt' => $this->buildGemInstructions($siteId) . "\n\n" . $strings['assetTaskSystem'],
            'taskPrompt' => $strings['assetTaskPrompt'],
            'payload' => [
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
            'systemPrompt' => $this->buildGemInstructions($siteId) . "\n\n" . $strings['contentTaskSystem'],
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

    private function callGemini(array $settings, string $systemPrompt, array $payload, array $schema, int $siteId): array
    {
        $client = Craft::createGuzzleClient();
        $response = $client->post('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($settings['model']) . ':generateContent', [
            'timeout' => 45,
            'headers' => [
                'x-goog-api-key' => $settings['apiKey'],
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemPrompt],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
                ],
            ],
        ]);

        $strings = $this->promptStrings($siteId);
        $decoded = json_decode((string)$response->getBody(), true);
        $content = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException($strings['emptyResponse']);
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw new \RuntimeException($strings['invalidJson']);
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

    private function promptStrings(int $siteId): array
    {
        $language = strtolower((string)(Craft::$app->getSites()->getSiteById($siteId)?->language ?? 'en'));
        if (str_starts_with($language, 'ca')) {
            return [
                'aiDisabled' => 'Els suggeriments d\'IA estan desactivats per aquest lloc.',
                'apiKeyMissing' => 'La clau API de Gemini no està configurada.',
                'modelMissing' => 'El model de Gemini no està configurat.',
                'contentIncomplete' => 'La IA ha retornat una proposta SEO incompleta.',
                'assetIncomplete' => 'La IA ha retornat metadades incompletes per a l\'asset.',
                'emptyResponse' => 'Gemini ha retornat una resposta buida.',
                'invalidJson' => 'Gemini ha retornat JSON no vàlid.',
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
                'assetTaskSystem' => 'Quan l\'usuari demani metadades d\'una imatge, genera un títol curt i un text alt descriptiu. No facis keyword stuffing ni inventis detalls no justificats. Respon només amb JSON.',
                'assetTaskPrompt' => 'Genera metadades SEO per a aquest asset. Retorna només JSON amb title, alt i reasoning.',
                'contentTaskSystem' => 'Quan l\'usuari demani SEO d\'un contingut, genera un title i una meta description concisos i útils per a cerca. Si hi ha imatges candidates, tria només entre aquestes. Respon només amb JSON.',
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
                'aiDisabled' => 'Las sugerencias de IA están desactivadas para este sitio.',
                'apiKeyMissing' => 'La API key de Gemini no está configurada.',
                'modelMissing' => 'El modelo de Gemini no está configurado.',
                'contentIncomplete' => 'La IA ha devuelto una sugerencia SEO incompleta.',
                'assetIncomplete' => 'La IA ha devuelto metadatos incompletos para el asset.',
                'emptyResponse' => 'Gemini ha devuelto una respuesta vacía.',
                'invalidJson' => 'Gemini ha devuelto un JSON no válido.',
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
                'assetTaskSystem' => 'Cuando el usuario pida metadatos de una imagen, genera un título corto y un texto alt descriptivo. No hagas keyword stuffing ni inventes detalles no soportados. Responde solo con JSON.',
                'assetTaskPrompt' => 'Genera metadatos SEO para este asset. Devuelve solo JSON con title, alt y reasoning.',
                'contentTaskSystem' => 'Cuando el usuario pida SEO de un contenido, genera un title y una meta description concisos y útiles para búsqueda. Si hay imágenes candidatas, elige solo entre ellas. Responde solo con JSON.',
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
            'aiDisabled' => 'AI suggestions are disabled for this site.',
            'apiKeyMissing' => 'The Gemini API key is not configured.',
            'modelMissing' => 'The Gemini model is not configured.',
            'contentIncomplete' => 'AI returned an incomplete SEO suggestion.',
            'assetIncomplete' => 'AI returned incomplete asset metadata.',
            'emptyResponse' => 'Gemini returned an empty response.',
            'invalidJson' => 'Gemini returned invalid JSON.',
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
            'assetTaskSystem' => 'When the user asks for image metadata, generate a short editor-friendly title and a descriptive alt text. Do not keyword-stuff or invent unsupported details. Return JSON only.',
            'assetTaskPrompt' => 'Generate SEO metadata for this asset. Return only JSON with title, alt and reasoning.',
            'contentTaskSystem' => 'When the user asks for content SEO, generate a concise search-friendly title and meta description. If image candidates are provided, choose only from them. Return JSON only.',
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
