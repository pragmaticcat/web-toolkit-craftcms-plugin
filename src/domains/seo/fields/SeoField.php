<?php

namespace pragmatic\webtoolkit\domains\seo\fields;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\Enums\TranslationMethod;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Field\Assets as AssetsField;
use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use GraphQL\Type\Definition\Type;
use pragmatic\webtoolkit\db\Query;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;

use function CraftCms\Cms\template;

class SeoField extends Field
{
    private const STORAGE_TABLE = 'pragmatic_toolkit_seo_blocks';
    private const STORAGE_UNIQUE_INDEX = 'pwt_seo_blocks_unique';
    private static bool $storageReady = false;

    protected TranslationMethod $_translationMethod = TranslationMethod::Site;
    public string $defaultTitle = '';
    public string $defaultDescription = '';
    public ?int $defaultImageId = null;
    public string $defaultImageDescription = '';

    public static function displayName(): string
    {
        return 'SEO';
    }

    public static function icon(): string
    {
        return 'globe';
    }

    public static function dbType(): array|string|null
    {
        return null;
    }

    public static function supportedTranslationMethods(): array
    {
        // Without declaring this, Craft forces the translation method to "none", and the plugin
        // saves the field on every request (clearing all caches). Values are stored per site.
        return [TranslationMethod::Site];
    }

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules['defaultTitle'] = ['string'];
        $rules['defaultDescription'] = ['string'];
        $rules['defaultImageDescription'] = ['string'];
        $rules['defaultImageId'] = ['nullable', 'integer'];
        return $rules;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof SeoFieldValue) {
            return $value;
        }

        // Values coming from element edit forms arrive as arrays; normalize them directly.
        if (is_array($value)) {
            $imageId = $this->normalizeImageId($value['imageId'] ?? null);
            return new SeoFieldValue([
                'title' => array_key_exists('title', $value) ? trim((string)$value['title']) : '',
                'description' => array_key_exists('description', $value) ? trim((string)$value['description']) : '',
                'imageId' => $imageId,
                'imageFieldHandle' => trim((string)($value['imageFieldHandle'] ?? '')),
                'sitemapEnabled' => array_key_exists('sitemapEnabled', $value) ? (bool)$value['sitemapEnabled'] : true,
                'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $value) ? (bool)$value['sitemapIncludeImages'] : true,
            ]);
        }

        $this->ensureStorageTable();

        if ($element && $element->id && $this->id) {
            $stored = $this->loadStoredValue((int)$element->id, (int)$element->siteId);
            if ($stored !== null) {
                return $stored;
            }
        }

        return new SeoFieldValue([
            'title' => $this->defaultTitle,
            'description' => $this->defaultDescription,
            'imageId' => $this->defaultImageId,
            'imageFieldHandle' => '',
            'sitemapEnabled' => true,
            'sitemapIncludeImages' => true,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        $this->ensureStorageTable();

        $data = $this->storageDataFromValue($value, $element);

        if ($element && $element->id && $this->id) {
            $this->persistStoredValue(
                (int)$element->id,
                (int)$element->siteId,
                $data
            );
        }

        return null;
    }

    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        parent::afterElementSave($element, $isNew);
        if (!$element->id || !$this->id) {
            return;
        }

        $value = $element->getFieldValue($this->handle);
        $data = $this->storageDataFromValue($value, $element);
        $this->persistStoredValue((int)$element->id, (int)$element->siteId, $data);
    }

    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        $normalized = $this->normalizeValue($value, $element);
        if (!$normalized instanceof SeoFieldValue) {
            return '';
        }

        return implode(' ', [
            $normalized->title,
            $normalized->description,
            $this->resolveImageDescription($normalized->imageId, $element->siteId ?? null),
        ]);
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        $normalized = $this->normalizeValue($value, $element);
        if (!$normalized instanceof SeoFieldValue) {
            $normalized = new SeoFieldValue();
        }

        $imageElement = null;
        if ($normalized->imageId) {
            $siteId = $element?->siteId ?? Sites::getCurrentSite()->id;
            $imageElement = Elements::getElementById($normalized->imageId, Asset::class, $siteId);
            if (!$imageElement) {
                $imageElement = Asset::find()->id($normalized->imageId)->status(null)->one();
            }
        }

        $sectionValues = ['title' => '', 'description' => '', 'imageId' => null, 'imageFieldHandle' => ''];
        $assetFieldOptions = [['label' => 'Ninguno', 'value' => '']];
        foreach ($element?->getFieldLayout()?->getCustomFields() ?? [] as $layoutField) {
            if ($layoutField instanceof AssetsField) {
                $assetFieldOptions[] = ['label' => $layoutField->name, 'value' => $layoutField->handle];
            }
        }
        $sectionImageElement = null;
        if ($element instanceof \CraftCms\Cms\Entry\Elements\Entry && $element->sectionId) {
            $sectionSettings = \pragmatic\webtoolkit\PragmaticWebToolkit::$plugin->seoMetaSettings
                ->getSectionSettings((int)$element->siteId, (int)$element->sectionId);
            $sectionValues = [
                'title' => trim((string)($sectionSettings['titleSiteName'] ?? '')),
                'description' => trim((string)($sectionSettings['defaultSiteDescription'] ?? '')),
                'imageId' => !empty($sectionSettings['defaultSiteImageId']) ? (int)$sectionSettings['defaultSiteImageId'] : null,
                'imageFieldHandle' => trim((string)($sectionSettings['defaultSiteImageFieldHandle'] ?? '')),
            ];
            if ($sectionValues['imageId']) {
                $sectionImageElement = Elements::getElementById(
                    $sectionValues['imageId'],
                    Asset::class,
                    (int)$element->siteId
                );
            }
        }

        return template('pragmatic-web-toolkit/seo/fields/seo_input', [
            'field' => $this,
            'value' => $normalized,
            'imageElement' => $imageElement,
            'sectionName' => $element instanceof \CraftCms\Cms\Entry\Elements\Entry && $element->section ? (string)$element->section->name : '',
            'element' => $element,
            'sectionValues' => $sectionValues,
            'sectionImageElement' => $sectionImageElement,
            'assetFieldOptions' => $assetFieldOptions,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return template('pragmatic-web-toolkit/seo/fields/seo_settings', [
            'field' => $this,
        ]);
    }

    public function getContentGqlType(): Type|array
    {
        return Type::string();
    }

    public function getContentGqlMutationArgumentType(): Type|array
    {
        return Type::string();
    }

    private function normalizeImageId(mixed $value): ?int
    {
        $id = $this->extractPositiveInt($value);
        return $id > 0 ? $id : null;
    }

    private function extractPositiveInt(mixed $value): int
    {
        if (is_array($value)) {
            foreach ($value as $candidate) {
                $id = $this->extractPositiveInt($candidate);
                if ($id > 0) {
                    return $id;
                }
            }
            return 0;
        }

        if ($value === null || $value === '' || $value === false) {
            return 0;
        }

        return max(0, (int)$value);
    }

    private function storageDataFromValue(mixed $value, ?ElementInterface $element = null): array
    {
        if ($value instanceof SeoFieldValue) {
            $imageId = $this->normalizeImageId($value->imageId);
            $data = [
                'title' => (string)$value->title,
                'description' => (string)$value->description,
                'imageId' => $imageId,
                'imageFieldHandle' => trim($value->imageFieldHandle),
            ];
            if ($value->sitemapEnabled !== null) {
                $data['sitemapEnabled'] = (bool)$value->sitemapEnabled;
            }
            if ($value->sitemapIncludeImages !== null) {
                $data['sitemapIncludeImages'] = (bool)$value->sitemapIncludeImages;
            }
            return $data;
        }

        if (is_array($value)) {
            $imageId = $this->normalizeImageId($value['imageId'] ?? null);
            $data = [
                'title' => (string)($value['title'] ?? ''),
                'description' => (string)($value['description'] ?? ''),
                'imageId' => $imageId,
                'imageFieldHandle' => trim((string)($value['imageFieldHandle'] ?? '')),
            ];
            if (array_key_exists('sitemapEnabled', $value)) {
                $data['sitemapEnabled'] = (bool)$value['sitemapEnabled'];
            }
            if (array_key_exists('sitemapIncludeImages', $value)) {
                $data['sitemapIncludeImages'] = (bool)$value['sitemapIncludeImages'];
            }
            return $data;
        }

        return [
            'title' => '',
            'description' => '',
            'imageId' => null,
            'imageFieldHandle' => '',
        ];
    }

    private function resolveImageDescription(?int $imageId, ?int $siteId): string
    {
        if (!$imageId) {
            return '';
        }

        $siteId = $siteId ?: (int)Sites::getCurrentSite()->id;
        $asset = Elements::getElementById($imageId, Asset::class, $siteId);
        if (!$asset) {
            $asset = Asset::find()->id($imageId)->status(null)->one();
        }

        if ($asset instanceof Asset) {
            if (method_exists($asset, 'getAltText')) {
                $alt = trim((string)$asset->getAltText());
                if ($alt !== '') {
                    return $alt;
                }
            }

            if ($asset->canGetProperty('alt')) {
                $alt = trim((string)($asset->alt ?? ''));
                if ($alt !== '') {
                    return $alt;
                }
            }

            $title = trim((string)$asset->title);
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    private function ensureStorageTable(): void
    {
        // L’esquema el crea la migració Install.
    }

    private function ensureStorageIndexes(): void
    {
        // L’esquema el crea la migració Install.
    }

    private function loadStoredValue(int $elementId, int $siteId): ?SeoFieldValue
    {
        $canonicalId = $this->resolveCanonicalId($elementId);
        $row = (new Query())
            ->from(self::STORAGE_TABLE)
            ->where([
                'canonicalId' => $canonicalId,
                'siteId' => $siteId,
                'fieldId' => (int)$this->id,
            ])
            ->one();

        $fallbackSiteId = (int)Sites::getPrimarySite()->id;
        if (!$row && $siteId !== $fallbackSiteId) {
            $row = (new Query())
                ->from(self::STORAGE_TABLE)
                ->where([
                    'canonicalId' => $canonicalId,
                    'siteId' => $fallbackSiteId,
                    'fieldId' => (int)$this->id,
                ])
                ->one();
        }

        $optionsRow = (new Query())
            ->from(self::STORAGE_TABLE)
            ->where([
                'canonicalId' => $canonicalId,
                'siteId' => 0,
                'fieldId' => (int)$this->id,
            ])
            ->one();

        if (!$row && !$optionsRow) {
            return null;
        }

        return new SeoFieldValue([
            'title' => (string)($row['title'] ?? $this->defaultTitle),
            'description' => (string)($row['description'] ?? $this->defaultDescription),
            'imageId' => !empty($row['imageId']) ? (int)$row['imageId'] : $this->defaultImageId,
            'imageFieldHandle' => trim((string)($row['imageFieldHandle'] ?? '')),
            'sitemapEnabled' => array_key_exists('sitemapEnabled', (array)$optionsRow) ? ($optionsRow['sitemapEnabled'] === null ? null : (bool)$optionsRow['sitemapEnabled']) : null,
            'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', (array)$optionsRow) ? ($optionsRow['sitemapIncludeImages'] === null ? null : (bool)$optionsRow['sitemapIncludeImages']) : null,
        ]);
    }

    private function persistStoredValue(int $elementId, int $siteId, array $data): void
    {
        $canonicalId = $this->resolveCanonicalId($elementId);
        $now = DbHelper::prepareDateForDb(new \DateTime());

        Command::make()->upsert(self::STORAGE_TABLE, [
            'canonicalId' => $canonicalId,
            'siteId' => $siteId,
            'fieldId' => (int)$this->id,
            'title' => (string)($data['title'] ?? ''),
            'description' => (string)($data['description'] ?? ''),
            'imageId' => $this->normalizeImageId($data['imageId'] ?? null),
            'imageFieldHandle' => trim((string)($data['imageFieldHandle'] ?? '')),
            'imageDescription' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid()->toString(),
        ], [
            'title' => (string)($data['title'] ?? ''),
            'description' => (string)($data['description'] ?? ''),
            'imageId' => $this->normalizeImageId($data['imageId'] ?? null),
            'imageFieldHandle' => trim((string)($data['imageFieldHandle'] ?? '')),
            'imageDescription' => null,
            'dateUpdated' => $now,
        ])->execute();

        if (
            array_key_exists('sitemapEnabled', $data) ||
            array_key_exists('sitemapIncludeImages', $data)
        ) {
            Command::make()->upsert(self::STORAGE_TABLE, [
                'canonicalId' => $canonicalId,
                'siteId' => 0,
                'fieldId' => (int)$this->id,
                'title' => null,
                'description' => null,
                'imageId' => null,
                'imageDescription' => null,
                'sitemapEnabled' => array_key_exists('sitemapEnabled', $data) ? $data['sitemapEnabled'] : null,
                'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $data) ? $data['sitemapIncludeImages'] : null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => Str::uuid()->toString(),
            ], [
                'sitemapEnabled' => array_key_exists('sitemapEnabled', $data) ? $data['sitemapEnabled'] : null,
                'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $data) ? $data['sitemapIncludeImages'] : null,
                'dateUpdated' => $now,
            ])->execute();
        }
    }

    private function resolveCanonicalId(int $elementId): int
    {
        $canonicalId = (new Query())
            ->select(['canonicalId'])
            ->from('elements')
            ->where(['id' => $elementId])
            ->scalar();

        return $canonicalId ? (int)$canonicalId : $elementId;
    }
}
