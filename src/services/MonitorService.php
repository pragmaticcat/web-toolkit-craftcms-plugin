<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\models\Site;
use pragmatic\webtoolkit\jobs\SendMonitorEventJob;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class MonitorService
{
    public const INVENTORY_PAGE_SIZE = 500;
    public const SIGNATURE_MAX_AGE = 300;
    private const VERSIONS_STORE_KEY = 'monitor.versions';

    /** @var array<int,bool> Entries already reported in this request. */
    private array $reportedEntryIds = [];

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->siteId() !== '' && $this->secret() !== '';
    }

    public function queue(string $type, array $payload = [], array $fingerprints = []): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        Craft::$app->getQueue()->push(new SendMonitorEventJob([
            'type' => $type,
            'payload' => $payload,
            'fingerprints' => $fingerprints,
        ]));
    }

    public function send(string $eventId, string $type, string $occurredAt, array $payload, array $fingerprints): void
    {
        $body = json_encode([
            'event_id' => $eventId,
            'type' => $type,
            'occurred_at' => $occurredAt,
            'payload' => $payload,
            'fingerprints' => $fingerprints,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp."\nPOST\n".$body, $this->secret());

        Craft::createGuzzleClient(['timeout' => 15])->request('POST', $this->url(), [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PWT-Site' => $this->siteId(),
                'X-PWT-Timestamp' => $timestamp,
                'X-PWT-Signature' => $signature,
            ],
            'body' => $body,
        ]);
    }

    /**
     * Whether a request carries PWM's signature for this site.
     */
    public function isValidRequest(string $site, string $timestamp, string $signature, string $method, string $body = ''): bool
    {
        return self::verifySignature($this->siteId(), $this->secret(), $site, $timestamp, $signature, $method, $body, time());
    }

    /**
     * Checks the X-PWT-* headers: the site must match, the timestamp must be recent and
     * the signature must be the HMAC of "<timestamp>\n<METHOD>\n<body>".
     */
    public static function verifySignature(
        string $expectedSite,
        string $secret,
        string $site,
        string $timestamp,
        string $signature,
        string $method,
        string $body,
        int $now
    ): bool {
        if ($secret === '' || $expectedSite === '' || !hash_equals($expectedSite, $site)) {
            return false;
        }
        if (!ctype_digit($timestamp) || abs($now - (int)$timestamp) > self::SIGNATURE_MAX_AGE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp."\n".strtoupper($method)."\n".$body, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Fingerprint of the project config, so PWM can tell when it changes between deploys.
     */
    public function projectConfigFingerprint(): ?string
    {
        $path = Craft::$app->getPath()->getConfigPath().'/project/project.yaml';

        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    /**
     * Queues `entry.saved` for saves that can change what the public site shows.
     */
    public function reportEntrySave(Entry $entry, bool $isNew): void
    {
        $report = self::shouldReportEntrySave(
            ElementHelper::isDraftOrRevision($entry),
            (bool)$entry->propagating,
            (bool)$entry->resaving,
            $entry->uri,
            $isNew,
            $entry->getStatus() === Entry::STATUS_LIVE
        );
        if (!$report || isset($this->reportedEntryIds[(int)$entry->id])) {
            return;
        }
        $this->reportedEntryIds[(int)$entry->id] = true;

        $this->queue('entry.saved', [
            'entry_id' => $entry->id,
            'site_id' => $entry->siteId,
            'uri' => $entry->uri,
            'url' => $entry->getUrl(),
            'status' => $entry->getStatus(),
            'is_new' => $isNew,
        ]);
    }

    /**
     * Drafts, revisions, propagation to other sites, bulk resaves and entries without
     * a URL never reach the public site. A new entry only matters once it is live.
     */
    public static function shouldReportEntrySave(
        bool $isDraftOrRevision,
        bool $propagating,
        bool $resaving,
        ?string $uri,
        bool $isNew,
        bool $isLive
    ): bool {
        if ($isDraftOrRevision || $propagating || $resaving || $uri === null || $uri === '') {
            return false;
        }

        return !$isNew || $isLive;
    }

    /**
     * Queues `deployment` when Craft or plugin versions changed since the last check.
     * The first check only stores the versions, so installing PWT doesn't look like a deploy.
     */
    public function checkForVersionChanges(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $store = PragmaticWebToolkit::$plugin->domainSettingsStore;
        $stored = $store->get(self::VERSIONS_STORE_KEY);
        $current = $this->versions();
        $previous = is_array($stored['versions'] ?? null) ? $stored['versions'] : null;

        if ($previous === $current) {
            return;
        }

        $store->save(self::VERSIONS_STORE_KEY, ['versions' => $current]);
        if ($previous === null) {
            return;
        }

        $this->queue('deployment', [
            'changes' => self::versionChanges($previous, $current),
        ], [
            'project_config' => $this->projectConfigFingerprint(),
        ]);
    }

    /**
     * @param array<string,string> $previous
     * @param array<string,string> $current
     * @return array<string,array{from:?string,to:?string}>
     */
    public static function versionChanges(array $previous, array $current): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($previous), ...array_keys($current)]) as $name) {
            $from = $previous[$name] ?? null;
            $to = $current[$name] ?? null;
            if ($from !== $to) {
                $changes[(string)$name] = ['from' => $from, 'to' => $to];
            }
        }
        ksort($changes);

        return $changes;
    }

    /**
     * Craft and installed plugin versions.
     *
     * @return array<string,string>
     */
    public function versions(): array
    {
        $versions = ['craft' => (string)Craft::$app->getVersion()];
        foreach (Craft::$app->getPlugins()->getAllPlugins() as $handle => $plugin) {
            $versions[(string)$handle] = (string)$plugin->getVersion();
        }
        ksort($versions);

        return $versions;
    }

    /**
     * Settings that decide whether search engines may index the site, plus one summary per site.
     *
     * @return array<string,mixed>
     */
    public function inventorySummary(): array
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $seoEnabled = PragmaticWebToolkit::$plugin->domains->isEnabled('seo');
        $sites = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!$site->hasUrls) {
                continue;
            }

            $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings((int)$site->id);
            $robots = $this->expectedRobots($settings);
            $canonicalSite = !empty($settings['canonicalSiteId']) && (int)$settings['canonicalSiteId'] !== (int)$site->id
                ? Craft::$app->getSites()->getSiteById((int)$settings['canonicalSiteId'])
                : null;
            $baseUrl = rtrim((string)$site->getBaseUrl(), '/');
            $host = parse_url($baseUrl, PHP_URL_HOST);

            $sites[] = [
                'handle' => (string)$site->handle,
                'name' => $site->getName(),
                'language' => $site->language,
                'base_url' => $baseUrl,
                'primary' => $site->primary,
                'enabled' => (bool)$site->getEnabled(),
                'robots' => $robots,
                'noindex' => self::robotsBlocksIndexing($robots),
                'canonical_site' => $canonicalSite?->handle,
                'robots_txt' => $seoEnabled
                    ? PragmaticWebToolkit::$plugin->seoSitemap->robotsTxt((int)$site->id, is_string($host) ? $host : null)
                    : null,
                'sitemap_url' => $seoEnabled ? $baseUrl.'/sitemap.xml' : null,
                'url_count' => (int)$this->siteEntryQuery($site)->count(),
            ];
        }

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'environment' => Craft::$app->env,
            'dev_mode' => (bool)$general->devMode,
            'disallow_robots' => (bool)$general->disallowRobots,
            'seo_enabled' => $seoEnabled,
            'page_size' => self::INVENTORY_PAGE_SIZE,
            'sites' => $sites,
        ];
    }

    /**
     * One page of a site's live URLs, with what PWT renders for each so PWM can check it from outside.
     *
     * @return array<string,mixed>
     */
    public function inventoryUrls(Site $site, int $page): array
    {
        $page = max(1, $page);
        $siteId = (int)$site->id;
        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($siteId);
        $robots = $this->expectedRobots($settings);
        $siteNoindex = self::robotsBlocksIndexing($robots);
        $sitemap = PragmaticWebToolkit::$plugin->seoSitemap;
        $typeRows = [];
        foreach ($sitemap->entryTypeRows($siteId, false) as $row) {
            $typeRows[$row['entryTypeId']] = $row;
        }

        $total = (int)$this->siteEntryQuery($site)->count();
        $entries = $sitemap->safeEntriesFromQuery(
            $this->siteEntryQuery($site)
                ->offset(($page - 1) * self::INVENTORY_PAGE_SIZE)
                ->limit(self::INVENTORY_PAGE_SIZE),
            $siteId
        );

        $urls = [];
        foreach ($entries as $entry) {
            $url = $entry->getUrl();
            if (!$url) {
                continue;
            }

            $canonical = PragmaticWebToolkit::$plugin->seoMetaSettings->crossSiteCanonicalUrl($entry) ?? $url;
            $urls[] = [
                'url' => $url,
                'entry_id' => (int)$entry->id,
                'section' => $entry->getSection()?->handle,
                'has_seo_field' => isset($typeRows[(int)$entry->typeId]),
                'robots' => $robots,
                'canonical' => $canonical,
                'in_sitemap' => $sitemap->isEntryInSitemap($entry, $typeRows),
                'indexable' => !$siteNoindex && $canonical === $url,
            ];
        }

        return [
            'site' => (string)$site->handle,
            'page' => $page,
            'page_size' => self::INVENTORY_PAGE_SIZE,
            'pages' => max(1, (int)ceil($total / self::INVENTORY_PAGE_SIZE)),
            'total' => $total,
            'urls' => $urls,
        ];
    }

    /**
     * Whether a robots meta value tells search engines not to index the page.
     */
    public static function robotsBlocksIndexing(string $robots): bool
    {
        $directives = array_map('trim', explode(',', strtolower($robots)));

        return in_array('noindex', $directives, true) || in_array('none', $directives, true);
    }

    /**
     * The robots meta PWT renders for a live entry: the site setting, or "index,follow".
     */
    private function expectedRobots(array $siteSettings): string
    {
        $robots = trim((string)($siteSettings['robots'] ?? ''));

        return $robots !== '' ? $robots : 'index,follow';
    }

    private function siteEntryQuery(Site $site): EntryQuery
    {
        return Entry::find()
            ->siteId((int)$site->id)
            ->status('live')
            ->uri(':notempty:')
            ->orderBy(['elements.id' => SORT_ASC]);
    }

    private function url(): string
    {
        return (string)App::parseEnv('$PWT_MONITOR_EVENTS_URL');
    }

    private function siteId(): string
    {
        return (string)App::parseEnv('$PWT_MONITOR_SITE_ID');
    }

    private function secret(): string
    {
        return (string)App::parseEnv('$PWT_MONITOR_SECRET');
    }
}
