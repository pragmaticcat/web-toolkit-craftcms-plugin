<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use CraftCms\Cms\Element\Element;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\db\Query;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;

class MetaSettingsService
{
    private const TABLE = 'pragmatic_toolkit_seo_meta_site_settings';
    private const SECTION_TABLE = 'pragmatic_toolkit_seo_meta_section_settings';
    private static bool $tableReady = false;

    public function getSiteSettings(int $siteId): array
    {
        $this->ensureTable();
        $defaults = $this->defaults();

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->one();

        if (!$row) {
            return $defaults;
        }

        return [
            'ogType' => $this->sanitizeOgType($row['ogType'] ?? null),
            'robots' => $this->sanitizeRobots($row['robots'] ?? null),
            'robotsTxt' => $this->sanitizeRobotsTxt($row['robotsTxt'] ?? null),
            'canonicalSiteId' => !empty($row['canonicalSiteId']) ? (int)$row['canonicalSiteId'] : null,
            'googleSiteVerification' => trim((string)($row['googleSiteVerification'] ?? '')),
            'twitterSite' => trim((string)($row['twitterSite'] ?? '')),
            'twitterCreator' => trim((string)($row['twitterCreator'] ?? '')),
            'siteNameOverride' => trim((string)($row['siteNameOverride'] ?? '')),
            'defaultSiteDescription' => trim((string)($row['defaultSiteDescription'] ?? '')),
            'defaultSiteImageId' => !empty($row['defaultSiteImageId']) ? (int)$row['defaultSiteImageId'] : null,
            'defaultSiteImageDescription' => trim((string)($row['defaultSiteImageDescription'] ?? '')),
            'titleSiteName' => trim((string)($row['titleSiteName'] ?? '')),
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePosition($row['titleSiteNamePosition'] ?? null),
            'titleSeparator' => $this->sanitizeTitleSeparator($row['titleSeparator'] ?? null),
            'enableHreflang' => (bool)($row['enableHreflang'] ?? true),
            'excludeFromHreflang' => (bool)($row['excludeFromHreflang'] ?? false),
            'xDefaultSiteId' => !empty($row['xDefaultSiteId']) ? (int)$row['xDefaultSiteId'] : null,
            'schemaMode' => $this->sanitizeSchemaMode($row['schemaMode'] ?? null),
            'mainEntityType' => $this->sanitizeMainEntityType($row['mainEntityType'] ?? null),
            'enableArticleMeta' => (bool)($row['enableArticleMeta'] ?? true),
            'includeImageMeta' => (bool)($row['includeImageMeta'] ?? true),
            'strategyAudience' => trim((string)($row['strategyAudience'] ?? '')),
            'strategyBusinessGoals' => trim((string)($row['strategyBusinessGoals'] ?? '')),
            'strategyTone' => trim((string)($row['strategyTone'] ?? '')),
            'strategyBrandTerms' => trim((string)($row['strategyBrandTerms'] ?? '')),
            'strategyForbiddenTerms' => trim((string)($row['strategyForbiddenTerms'] ?? '')),
            'strategyPrimaryKeywords' => trim((string)($row['strategyPrimaryKeywords'] ?? '')),
            'strategySecondaryKeywords' => trim((string)($row['strategySecondaryKeywords'] ?? '')),
            'strategyCtaStyle' => trim((string)($row['strategyCtaStyle'] ?? '')),
            'strategyNotes' => trim((string)($row['strategyNotes'] ?? '')),
            'maxImageCandidates' => max(1, (int)($row['maxImageCandidates'] ?? 12)),
            'maxSourceTextChars' => max(500, (int)($row['maxSourceTextChars'] ?? 6000)),
        ];
    }

    /**
     * URL of the element on its site's configured canonical site, or null when the
     * site has none or the element isn't published there.
     */
    public function crossSiteCanonicalUrl(ElementInterface $element): ?string
    {
        $siteId = (int)($element->siteId ?? 0);
        $canonicalSiteId = $this->getSiteSettings($siteId)['canonicalSiteId'] ?? null;
        if (!$canonicalSiteId || $canonicalSiteId === $siteId) {
            return null;
        }

        $elementId = (int)($element->canonicalId ?: $element->id);
        if ($elementId <= 0) {
            return null;
        }

        $target = Elements::getElementById($elementId, $element::class, $canonicalSiteId);
        if (!$target instanceof Element || !in_array($target->getStatus(), [Element::STATUS_ENABLED, 'live'], true)) {
            return null;
        }

        $url = $target->getUrl();
        return $url ? (string)$url : null;
    }

    public function saveSiteSettings(int $siteId, array $input): void
    {
        $this->ensureTable();
        $current = $this->getSiteSettings($siteId);
        $data = [
            'siteId' => $siteId,
            'ogType' => $this->sanitizeOgType($this->pick($input, 'ogType', $current['ogType'])),
            'robots' => $this->sanitizeRobots($this->pick($input, 'robots', $current['robots'])),
            'robotsTxt' => $this->sanitizeRobotsTxt($this->pick($input, 'robotsTxt', $current['robotsTxt'])),
            'canonicalSiteId' => $this->normalizeElementId($this->pick($input, 'canonicalSiteId', $current['canonicalSiteId'])) ?: null,
            'googleSiteVerification' => trim((string)$this->pick($input, 'googleSiteVerification', $current['googleSiteVerification'])),
            'twitterSite' => trim((string)$this->pick($input, 'twitterSite', $current['twitterSite'])),
            'twitterCreator' => trim((string)$this->pick($input, 'twitterCreator', $current['twitterCreator'])),
            'siteNameOverride' => trim((string)$this->pick($input, 'siteNameOverride', $current['siteNameOverride'])),
            'defaultSiteDescription' => trim((string)$this->pick($input, 'defaultSiteDescription', $current['defaultSiteDescription'])),
            'defaultSiteImageId' => $this->normalizeElementId($this->pick($input, 'defaultSiteImageId', $current['defaultSiteImageId'])),
            'defaultSiteImageDescription' => trim((string)$this->pick($input, 'defaultSiteImageDescription', $current['defaultSiteImageDescription'])),
            'titleSiteName' => trim((string)$this->pick($input, 'titleSiteName', $current['titleSiteName'])),
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePosition($this->pick($input, 'titleSiteNamePosition', $current['titleSiteNamePosition'])),
            'titleSeparator' => $this->sanitizeTitleSeparator($this->pick($input, 'titleSeparator', $current['titleSeparator'])),
            'enableHreflang' => $this->pickBool($input, 'enableHreflang', (bool)$current['enableHreflang']) ? 1 : 0,
            'excludeFromHreflang' => $this->pickBool($input, 'excludeFromHreflang', (bool)$current['excludeFromHreflang']) ? 1 : 0,
            'xDefaultSiteId' => !empty($this->pick($input, 'xDefaultSiteId', $current['xDefaultSiteId'])) ? (int)$this->pick($input, 'xDefaultSiteId', $current['xDefaultSiteId']) : null,
            'schemaMode' => $this->sanitizeSchemaMode($this->pick($input, 'schemaMode', $current['schemaMode'])),
            'mainEntityType' => $this->sanitizeMainEntityType($this->pick($input, 'mainEntityType', $current['mainEntityType'])),
            'enableArticleMeta' => $this->pickBool($input, 'enableArticleMeta', (bool)$current['enableArticleMeta']) ? 1 : 0,
            'includeImageMeta' => $this->pickBool($input, 'includeImageMeta', (bool)$current['includeImageMeta']) ? 1 : 0,
            'strategyAudience' => trim((string)$this->pick($input, 'strategyAudience', $current['strategyAudience'])),
            'strategyBusinessGoals' => trim((string)$this->pick($input, 'strategyBusinessGoals', $current['strategyBusinessGoals'])),
            'strategyTone' => trim((string)$this->pick($input, 'strategyTone', $current['strategyTone'])),
            'strategyBrandTerms' => trim((string)$this->pick($input, 'strategyBrandTerms', $current['strategyBrandTerms'])),
            'strategyForbiddenTerms' => trim((string)$this->pick($input, 'strategyForbiddenTerms', $current['strategyForbiddenTerms'])),
            'strategyPrimaryKeywords' => trim((string)$this->pick($input, 'strategyPrimaryKeywords', $current['strategyPrimaryKeywords'])),
            'strategySecondaryKeywords' => trim((string)$this->pick($input, 'strategySecondaryKeywords', $current['strategySecondaryKeywords'])),
            'strategyCtaStyle' => trim((string)$this->pick($input, 'strategyCtaStyle', $current['strategyCtaStyle'])),
            'strategyNotes' => trim((string)$this->pick($input, 'strategyNotes', $current['strategyNotes'])),
            'maxImageCandidates' => max(1, (int)$this->pick($input, 'maxImageCandidates', $current['maxImageCandidates'])),
            'maxSourceTextChars' => max(500, (int)$this->pick($input, 'maxSourceTextChars', $current['maxSourceTextChars'])),
        ];

        $now = DbHelper::prepareDateForDb(new \DateTime());
        Command::make()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid()->toString(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();
    }

    public function getSectionSettings(int $siteId, int $sectionId): array
    {
        $this->ensureTable();
        $defaults = $this->sectionDefaults();

        $row = (new Query())
            ->from(self::SECTION_TABLE)
            ->where([
                'siteId' => $siteId,
                'sectionId' => $sectionId,
            ])
            ->one();

        if (!$row) {
            return $defaults;
        }

        return [
            'enableSectionSeo' => (bool)($row['enableSectionSeo'] ?? false),
            'titleSiteName' => trim((string)($row['titleSiteName'] ?? '')),
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePositionOverride($row['titleSiteNamePosition'] ?? null),
            'titleSeparator' => trim((string)($row['titleSeparator'] ?? '')),
            'defaultSiteDescription' => trim((string)($row['defaultSiteDescription'] ?? '')),
            'defaultSiteImageId' => !empty($row['defaultSiteImageId']) ? (int)$row['defaultSiteImageId'] : null,
            'defaultSiteImageFieldHandle' => trim((string)($row['defaultSiteImageFieldHandle'] ?? '')),
            'defaultSiteImageDescription' => trim((string)($row['defaultSiteImageDescription'] ?? '')),
            'mainEntityType' => $this->sanitizeMainEntityType($row['mainEntityType'] ?? null),
        ];
    }

    public function saveSectionSettings(int $siteId, int $sectionId, array $input): void
    {
        $this->ensureTable();
        $current = $this->getSectionSettings($siteId, $sectionId);
        $data = [
            'siteId' => $siteId,
            'sectionId' => $sectionId,
            'enableSectionSeo' => 1,
            'titleSiteName' => trim((string)$this->pick($input, 'titleSiteName', $current['titleSiteName'])),
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePositionOverride($this->pick($input, 'titleSiteNamePosition', $current['titleSiteNamePosition'])),
            'titleSeparator' => trim((string)$this->pick($input, 'titleSeparator', $current['titleSeparator'])),
            'defaultSiteDescription' => trim((string)$this->pick($input, 'defaultSiteDescription', $current['defaultSiteDescription'])),
            'defaultSiteImageId' => $this->normalizeElementId($this->pick($input, 'defaultSiteImageId', $current['defaultSiteImageId'])),
            'defaultSiteImageFieldHandle' => trim((string)$this->pick($input, 'defaultSiteImageFieldHandle', $current['defaultSiteImageFieldHandle'])),
            'defaultSiteImageDescription' => trim((string)$this->pick($input, 'defaultSiteImageDescription', $current['defaultSiteImageDescription'])),
            'mainEntityType' => $this->sanitizeMainEntityType($this->pick($input, 'mainEntityType', $current['mainEntityType'])),
        ];

        $now = DbHelper::prepareDateForDb(new \DateTime());
        Command::make()->upsert(self::SECTION_TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid()->toString(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();
    }

    public function resolveSettingsForSection(int $siteId, ?int $sectionId = null): array
    {
        return [
            ...$this->getSiteSettings($siteId),
            ...$this->resolveEntryDefaultsForSection($siteId, $sectionId),
        ];
    }

    public function getEntryDefaults(int $siteId): array
    {
        $siteSettings = $this->getSiteSettings($siteId);

        return [
            'defaultEntryTitle' => '',
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePosition($siteSettings['titleSiteNamePosition'] ?? null),
            'titleSeparator' => $this->sanitizeTitleSeparator($siteSettings['titleSeparator'] ?? null),
            'defaultSiteDescription' => trim((string)($siteSettings['defaultSiteDescription'] ?? '')),
            'defaultSiteImageId' => !empty($siteSettings['defaultSiteImageId']) ? (int)$siteSettings['defaultSiteImageId'] : null,
            'defaultSiteImageDescription' => trim((string)($siteSettings['defaultSiteImageDescription'] ?? '')),
            'mainEntityType' => $this->sanitizeMainEntityType($siteSettings['mainEntityType'] ?? null),
        ];
    }

    public function resolveEntryDefaultsForSection(int $siteId, ?int $sectionId = null): array
    {
        $entryDefaults = $this->getEntryDefaults($siteId);
        if (!$sectionId || $sectionId <= 0) {
            return $entryDefaults;
        }

        $sectionSettings = $this->getSectionSettings($siteId, $sectionId);

        foreach ($this->sectionDefaults() as $key => $defaultValue) {
            $value = $sectionSettings[$key] ?? $defaultValue;
            if ($value === null || $value === '' || $value === $defaultValue) {
                continue;
            }

            if ($key === 'titleSiteName') {
                $entryDefaults['defaultEntryTitle'] = trim((string)$value);
                continue;
            }

            $entryDefaults[$key] = $value;
        }

        return $entryDefaults;
    }

    private function defaults(): array
    {
        return [
            'ogType' => 'auto',
            'robots' => '',
            'robotsTxt' => '',
            'canonicalSiteId' => null,
            'googleSiteVerification' => '',
            'twitterSite' => '',
            'twitterCreator' => '',
            'siteNameOverride' => '',
            'defaultSiteDescription' => '',
            'defaultSiteImageId' => null,
            'defaultSiteImageDescription' => '',
            'titleSiteName' => '',
            'titleSiteNamePosition' => 'after',
            'titleSeparator' => '|',
            'enableHreflang' => true,
            'excludeFromHreflang' => false,
            'xDefaultSiteId' => null,
            'schemaMode' => 'auto',
            'mainEntityType' => '',
            'enableArticleMeta' => true,
            'includeImageMeta' => true,
            'strategyAudience' => '',
            'strategyBusinessGoals' => '',
            'strategyTone' => '',
            'strategyBrandTerms' => '',
            'strategyForbiddenTerms' => '',
            'strategyPrimaryKeywords' => '',
            'strategySecondaryKeywords' => '',
            'strategyCtaStyle' => '',
            'strategyNotes' => '',
            'maxImageCandidates' => 12,
            'maxSourceTextChars' => 6000,
        ];
    }

    private function sectionDefaults(): array
    {
        return [
            'enableSectionSeo' => false,
            'titleSiteName' => '',
            'titleSiteNamePosition' => '',
            'titleSeparator' => '',
            'defaultSiteDescription' => '',
            'defaultSiteImageId' => null,
            'defaultSiteImageFieldHandle' => '',
            'defaultSiteImageDescription' => '',
            'mainEntityType' => '',
        ];
    }

    private function pick(array $input, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $input) ? $input[$key] : $default;
    }

    private function pickBool(array $input, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        return !empty($input[$key]);
    }

    private function sanitizeOgType(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['auto', 'article', 'website'], true) ? $value : 'auto';
    }

    private function sanitizeSchemaMode(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['auto', 'none', 'webpage', 'article'], true) ? $value : 'auto';
    }

    private function sanitizeMainEntityType(mixed $value): string
    {
        return mb_substr(trim((string)($value ?? '')), 0, 120);
    }

    private function sanitizeRobots(mixed $value): string
    {
        return trim((string)($value ?? ''));
    }

    private function sanitizeRobotsTxt(mixed $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string)($value ?? '')));
    }

    private function sanitizeTitleSiteNamePosition(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['never', 'before', 'after'], true) ? $value : 'after';
    }

    private function sanitizeTitleSiteNamePositionOverride(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        if ($value === '') {
            return '';
        }

        return in_array($value, ['never', 'before', 'after'], true) ? $value : '';
    }

    private function sanitizeTitleSeparator(mixed $value): string
    {
        $separator = trim((string)($value ?? ''));
        if ($separator === '') {
            return '|';
        }

        return mb_substr($separator, 0, 12);
    }

    private function normalizeElementId(mixed $value): ?int
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

    private function ensureTable(): void
    {
        // L’esquema el crea la migració Install.
    }
}
