<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\db\Query;

/**
 * Decides which entries belong in the sitemap and what robots.txt serves, so the
 * public sitemap and the monitoring inventory always agree.
 */
class SitemapService
{
    public const ENTRYTYPE_TABLE = '{{%pragmatic_toolkit_seo_sitemap_entrytypes}}';

    /**
     * Sitemap settings for every entry type with an SEO field in a section that has URLs on the site.
     *
     * @param bool $persistDefaults Store default settings for entry types that have none yet.
     * @return array<int,array{entryTypeId:int,sectionId:int,sectionHandle:string,sectionName:string,entryTypeName:string,seoHandle:string,settings:array{enabled:bool,includeImages:bool}}>
     */
    public function entryTypeRows(int $siteId, bool $persistDefaults = true): array
    {
        $db = Craft::$app->getDb();
        if ($persistDefaults) {
            $this->ensureEntryTypeTable();
        }

        $savedSettings = $db->tableExists(self::ENTRYTYPE_TABLE)
            ? (new Query())
                ->select(['entryTypeId', 'enabled', 'includeImages'])
                ->from(self::ENTRYTYPE_TABLE)
                ->indexBy('entryTypeId')
                ->all()
            : [];

        $rows = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!$this->sectionHasUrlsForSite($section, $siteId)) {
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $seoHandle = null;
                foreach ($entryType->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                    if ($field instanceof SeoField) {
                        $seoHandle = $field->handle;
                        break;
                    }
                }

                if (!$seoHandle) {
                    continue;
                }

                $saved = $savedSettings[$entryType->id] ?? null;
                $settings = [
                    'enabled' => $saved ? (bool)$saved['enabled'] : true,
                    'includeImages' => $saved ? (bool)$saved['includeImages'] : false,
                ];

                if ($saved === null && $persistDefaults) {
                    $this->saveEntryTypeSettings((int)$entryType->id, $settings);
                }

                $rows[] = [
                    'entryTypeId' => (int)$entryType->id,
                    'sectionId' => (int)$section->id,
                    'sectionHandle' => (string)$section->handle,
                    'sectionName' => (string)$section->name,
                    'entryTypeName' => (string)$entryType->name,
                    'seoHandle' => $seoHandle,
                    'settings' => $settings,
                ];
            }
        }

        return $rows;
    }

    /**
     * Live entries of a section that belong in the site's sitemap, keyed by their absolute URL.
     *
     * @param array<int,array<string,mixed>> $entryTypeRows
     * @return array<string,array{entry:Entry,seoValue:mixed,seoHandle:string,typeRow:array<string,mixed>}>
     */
    public function sectionEntries(int $siteId, int $sectionId, array $entryTypeRows, string $baseUrl): array
    {
        $entriesByLoc = [];

        foreach ($entryTypeRows as $typeRow) {
            if (empty($typeRow['settings']['enabled']) || (int)($typeRow['sectionId'] ?? 0) !== $sectionId) {
                continue;
            }

            $entries = $this->safeEntriesFromQuery(Entry::find()
                ->typeId((int)$typeRow['entryTypeId'])
                ->siteId($siteId)
                ->status('live')
                ->limit(null), $siteId);

            foreach ($entries as $entry) {
                $seoHandle = (string)$typeRow['seoHandle'];
                $seoValue = $this->seoValue($entry, $seoHandle);
                if (!$this->isEnabledForEntry($seoValue, $typeRow)) {
                    continue;
                }

                $loc = $this->sitemapLoc($entry, $baseUrl);
                if ($loc === null || isset($entriesByLoc[$loc])) {
                    continue;
                }

                $entriesByLoc[$loc] = [
                    'entry' => $entry,
                    'seoValue' => $seoValue,
                    'seoHandle' => $seoHandle,
                    'typeRow' => $typeRow,
                ];
            }
        }

        return $entriesByLoc;
    }

    /**
     * Whether a live entry is listed in its site's sitemap.
     *
     * @param array<int,array<string,mixed>> $entryTypeRowsByTypeId Entry type rows keyed by entry type ID.
     */
    public function isEntryInSitemap(Entry $entry, array $entryTypeRowsByTypeId): bool
    {
        $typeRow = $entryTypeRowsByTypeId[(int)$entry->typeId] ?? null;
        if ($typeRow === null || empty($typeRow['settings']['enabled'])) {
            return false;
        }

        $seoValue = $this->seoValue($entry, (string)$typeRow['seoHandle']);
        if (!$this->isEnabledForEntry($seoValue, $typeRow)) {
            return false;
        }

        return $this->sitemapLoc($entry, rtrim((string)$entry->getSite()->getBaseUrl(), '/')) !== null;
    }

    /**
     * The robots.txt body served for a site on the given host.
     */
    public function robotsTxt(int $siteId, ?string $host): string
    {
        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($siteId);

        if (Craft::$app->getConfig()->getGeneral()->disallowRobots) {
            $rules = "User-agent: *\nDisallow: /";
        } else {
            $rules = $settings['robotsTxt'] !== '' ? $settings['robotsTxt'] : "User-agent: *\nDisallow:";
        }
        $lines = preg_split('/\r?\n/', $rules) ?: [];
        foreach (['Disallow: /pragmatic-toolkit/', 'Disallow: /*/pragmatic-toolkit/'] as $directive) {
            if (!in_array($directive, $lines, true)) {
                $lines[] = $directive;
            }
        }
        $rules = implode("\n", $lines);

        $lines = [$rules];
        $sitemapUrls = $this->robotsSitemapUrls($host);
        if ($sitemapUrls !== []) {
            $lines[] = '';
            foreach ($sitemapUrls as $url) {
                $lines[] = 'Sitemap: ' . $url;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Sitemap index URLs for every site served from the given host, so a single
     * robots.txt covers language sites that live in subpaths of the same domain.
     *
     * @return string[]
     */
    public function robotsSitemapUrls(?string $host): array
    {
        $host = strtolower((string)$host);
        $urls = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->hasUrls ? (string)$site->getBaseUrl() : '';
            if ($baseUrl === '' || strtolower((string)parse_url($baseUrl, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $urls[] = rtrim($baseUrl, '/') . '/sitemap.xml';
        }

        return array_values(array_unique($urls));
    }

    public function saveEntryTypeSettings(int $entryTypeId, array $settings): void
    {
        if ($entryTypeId <= 0) {
            return;
        }

        $this->ensureEntryTypeTable();
        Craft::$app->getDb()->createCommand()->upsert(
            self::ENTRYTYPE_TABLE,
            [
                'entryTypeId' => $entryTypeId,
                'enabled' => !empty($settings['enabled']) ? 1 : 0,
                'includeImages' => !empty($settings['includeImages']) ? 1 : 0,
            ],
            [
                'enabled' => !empty($settings['enabled']) ? 1 : 0,
                'includeImages' => !empty($settings['includeImages']) ? 1 : 0,
            ]
        )->execute();
    }

    /**
     * Loads entries one by one so a single broken entry doesn't break the whole list.
     *
     * @return Entry[]
     */
    public function safeEntriesFromQuery(EntryQuery $entryQuery, int $siteId): array
    {
        $entryIds = array_values(array_unique(array_filter(
            array_map('intval', $entryQuery->ids()),
            static fn(int $id): bool => $id > 0
        )));

        $entries = [];
        $elements = Craft::$app->getElements();

        foreach ($entryIds as $entryId) {
            try {
                $entry = $elements->getElementById($entryId, Entry::class, $siteId);
            } catch (\Throwable $e) {
                Craft::warning(sprintf(
                    'Skipping entry #%d in SEO sitemap because it could not be loaded: %s',
                    $entryId,
                    $e->getMessage()
                ), __METHOD__);
                continue;
            }

            if ($entry instanceof Entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function sectionHasUrlsForSite(mixed $section, int $siteId): bool
    {
        if (!is_object($section) || !method_exists($section, 'getSiteSettings')) {
            return false;
        }

        $siteSetting = $section->getSiteSettings()[$siteId] ?? null;

        return $siteSetting !== null && (bool)$siteSetting->hasUrls;
    }

    /**
     * The entry's SEO field value, normalized when the field returns raw data.
     */
    public function seoValue(Entry $entry, string $seoHandle): mixed
    {
        try {
            $value = $entry->getFieldValue($seoHandle);
            if (!$value instanceof SeoFieldValue) {
                $field = $entry->getFieldLayout()?->getFieldByHandle($seoHandle);
                if ($field instanceof SeoField) {
                    $value = $field->normalizeValue($value, $entry);
                }
            }
        } catch (\Throwable $e) {
            Craft::warning(sprintf(
                'Could not read SEO field "%s" of entry #%d: %s',
                $seoHandle,
                (int)$entry->id,
                $e->getMessage()
            ), __METHOD__);
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $typeRow
     */
    private function isEnabledForEntry(mixed $seoValue, array $typeRow): bool
    {
        return $seoValue instanceof SeoFieldValue && $seoValue->sitemapEnabled !== null
            ? (bool)$seoValue->sitemapEnabled
            : (bool)$typeRow['settings']['enabled'];
    }

    /**
     * Absolute sitemap URL of the entry, or null when it has no URL or its canonical lives on another site.
     */
    private function sitemapLoc(Entry $entry, string $baseUrl): ?string
    {
        $entryUrl = $entry->getUrl();
        if (!$entryUrl) {
            return null;
        }
        // Sitemaps list canonical URLs only; republished entries belong to their original site's sitemap.
        if (PragmaticWebToolkit::$plugin->seoMetaSettings->crossSiteCanonicalUrl($entry) !== null) {
            return null;
        }

        return str_starts_with($entryUrl, 'http') ? $entryUrl : $baseUrl . '/' . ltrim($entryUrl, '/');
    }

    private function ensureEntryTypeTable(): void
    {
        $db = Craft::$app->getDb();
        if ($db->tableExists(self::ENTRYTYPE_TABLE)) {
            return;
        }

        $db->createCommand()->createTable(self::ENTRYTYPE_TABLE, [
            'entryTypeId' => 'integer NOT NULL PRIMARY KEY',
            'enabled' => 'boolean NOT NULL DEFAULT true',
            'includeImages' => 'boolean NOT NULL DEFAULT false',
        ])->execute();

        $db->createCommand()->addForeignKey(
            null,
            self::ENTRYTYPE_TABLE,
            ['entryTypeId'],
            '{{%entrytypes}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        )->execute();
    }
}
