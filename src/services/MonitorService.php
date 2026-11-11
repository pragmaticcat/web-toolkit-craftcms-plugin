<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\models\Site;
use craft\helpers\StringHelper;
use craft\queue\BaseJob;
use craft\queue\Queue as CraftQueue;
use craft\web\Request as WebRequest;
use pragmatic\webtoolkit\jobs\SendMonitorEventJob;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\db\Query;
use yii\queue\ExecEvent;
use yii\base\InvalidRouteException;
use yii\console\UnknownCommandException;
use yii\web\HttpException;
use pragmatic\webtoolkit\errors\MonitorTestException;

class MonitorService
{
    public const INVENTORY_PAGE_SIZE = 500;
    public const SIGNATURE_MAX_AGE = 300;
    public const QUEUE_STUCK_SECONDS = 1800;
    public const ERROR_REPEAT_SECONDS = 600;

    /**
     * What this version of PWT offers PWM, reported by the health endpoint.
     */
    public const CAPABILITIES = ['inventory', 'inventory.date_updated', 'inventory.fingerprint', 'resolve', 'run_queue', 'finding_exceptions'];

    private static bool $reporting = false;
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

    /**
     * Reports an unhandled exception. Client errors such as 404 are ignored, and the same
     * error is reported at most once every ten minutes.
     */
    public function reportException(\Throwable $exception, bool $sendNow = false): void
    {
        if (self::isIgnoredException($exception)) {
            return;
        }

        $request = Craft::$app->getRequest();
        $this->reportError('exception', [
            'class' => $exception::class,
            'message' => mb_substr($exception->getMessage(), 0, 500),
            'file' => $this->relativePath($exception->getFile()),
            'line' => $exception->getLine(),
            'url' => $request instanceof WebRequest ? $request->getAbsoluteUrl() : null,
            'method' => $request instanceof WebRequest ? $request->getMethod() : 'CLI',
        ], $exception::class.'|'.$exception->getFile().'|'.$exception->getLine()
            // Every test error is new, so repeating the test always sends it.
            .($exception instanceof MonitorTestException ? '|'.$exception->getMessage() : ''), $sendNow);
    }

    /**
     * Client errors such as 404, and console commands that don't exist, are not Craft errors.
     */
    public static function isIgnoredException(\Throwable $exception): bool
    {
        return ($exception instanceof HttpException && $exception->statusCode < 500)
            || $exception instanceof UnknownCommandException
            || $exception instanceof InvalidRouteException;
    }

    /**
     * Reports a queue job that failed for good (it will not be retried).
     */
    public function reportFailedJob(ExecEvent $event): void
    {
        if ($event->retry || $event->job instanceof SendMonitorEventJob) {
            return;
        }

        $error = $event->error;
        $description = $event->job instanceof BaseJob ? $event->job->getDescription() : null;
        $this->reportError('queue.failed', [
            'job' => is_object($event->job) ? $event->job::class : null,
            'description' => $description,
            'error_class' => $error ? $error::class : null,
            'message' => $error ? mb_substr($error->getMessage(), 0, 500) : null,
            'attempt' => $event->attempt,
        ], (is_object($event->job) ? $event->job::class : '').'|'.($error ? $error::class.'|'.$error->getMessage() : ''));
    }

    /**
     * Queues an error event, or sends it right away when the queue itself is down (for
     * example when the database is). Reporting never throws, so it can't hide the error.
     */
    private function reportError(string $type, array $payload, string $signature, bool $sendNow = false): void
    {
        if (self::$reporting || !$this->isConfigured()) {
            return;
        }

        if ($sendNow) {
            $payload['fingerprint'] = sha1($signature);
            $this->send(StringHelper::UUID(), $type, gmdate(DATE_ATOM), $payload, [], 10);

            return;
        }
        self::$reporting = true;

        try {
            $fingerprint = sha1($signature);
            try {
                if (!Craft::$app->getCache()->add('pwt-monitor-error:'.$fingerprint, true, self::ERROR_REPEAT_SECONDS)) {
                    return;
                }
            } catch (\Throwable) {
                // Without a cache every occurrence is reported.
            }

            $payload['fingerprint'] = $fingerprint;
            try {
                $this->queue($type, $payload);
            } catch (\Throwable) {
                $this->send(StringHelper::UUID(), $type, gmdate(DATE_ATOM), $payload, [], 5);
            }
        } catch (\Throwable $e) {
            Craft::warning('Could not report an error to Pragmatic Web Monitor: '.$e->getMessage(), __METHOD__);
        } finally {
            self::$reporting = false;
        }
    }

    private function relativePath(string $path): string
    {
        $root = Craft::getAlias('@root');

        return is_string($root) && str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/') : $path;
    }

    public function send(string $eventId, string $type, string $occurredAt, array $payload, array $fingerprints, int $timeout = 15): void
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

        Craft::createGuzzleClient(['timeout' => $timeout])->request('POST', $this->url(), [
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
     * Findings the editors marked as intended, for the health endpoint. PWM lists them as
     * expected instead of alerting. The list is complete: PWM removes the ones not in it.
     *
     * @return array<int, array{id: string, monitor: ?string, code: ?string, match: string, pattern: string, reason: string, expires_at: null}>
     */
    public function findingExceptions(): array
    {
        try {
            return self::redirectFindingExceptions(PragmaticWebToolkit::$plugin->redirects->getMonitorExpectedRules());
        } catch (\Throwable $exception) {
            Craft::warning('Could not read the redirects PWM should expect: '.$exception->getMessage(), __METHOD__);
            return [];
        }
    }

    /**
     * The PWM finding exceptions for redirect rules marked as intended. A redirect is expected
     * by the indexability check; a 410 rule also by the broken links check. Redirect sources are
     * full paths, so they become PWM path patterns ("/es/horari"); pattern tokens become "*".
     *
     * @param array<int, array<string, mixed>> $rules Redirect rule rows.
     * @return array<int, array{id: string, monitor: ?string, code: ?string, match: string, pattern: string, reason: string, expires_at: null}>
     */
    public static function redirectFindingExceptions(array $rules): array
    {
        $exceptions = [];
        foreach ($rules as $rule) {
            $source = trim((string)($rule['sourceUri'] ?? ''));
            if ($source === '' || !($rule['enabled'] ?? false) || !($rule['monitorExpected'] ?? false)) {
                continue;
            }

            [$match, $pattern] = match ((string)($rule['matchType'] ?? 'exact')) {
                'pattern' => ['glob', '/'.(preg_replace('/<[a-zA-Z][a-zA-Z0-9_]*>/', '*', trim($source, '/')) ?? '')],
                // PWM compares a regex starting with "^/" with the path, so the leading slash is added after the anchor.
                'regex' => ['regex', str_starts_with($source, '^') ? '^/'.ltrim(substr($source, 1), '/') : $source],
                default => ['exact', '/'.trim($source, '/')],
            };
            $id = 'redirect-'.(int)$rule['id'];
            $notes = trim((string)($rule['notes'] ?? ''));
            $reason = $notes !== '' ? mb_substr($notes, 0, 2000) : 'Redirect rule marked as intended in Craft';
            $gone = (int)($rule['statusCode'] ?? 301) === 410;

            $exceptions[] = ['id' => $id, 'monitor' => 'seo', 'code' => $gone ? 'http_error' : 'redirect', 'match' => $match, 'pattern' => $pattern, 'reason' => $reason, 'expires_at' => null];
            if ($gone) {
                $exceptions[] = ['id' => $id.'-links', 'monitor' => 'broken_links', 'code' => 'broken_link', 'match' => $match, 'pattern' => $pattern, 'reason' => $reason, 'expires_at' => null];
            }
        }

        return $exceptions;
    }

    /**
     * Facts about this install that can break the site, read for the health endpoint.
     *
     * @return array{project_config_pending: bool, updates_pending: bool, dev_mode: bool, environment: string, failed_jobs: ?int, oldest_waiting_job_seconds: ?int}
     */
    public function healthFacts(): array
    {
        $queue = Craft::$app->getQueue();
        $failedJobs = null;
        $oldestWaiting = null;

        if ($queue instanceof CraftQueue) {
            $failedJobs = $queue->getTotalFailed();
            $now = time();
            $oldestDue = (new Query())
                ->from($queue->tableName)
                ->where(['fail' => false, 'timeUpdated' => null])
                ->andWhere('[[timePushed]] + [[delay]] <= :now', [':now' => $now])
                ->min('[[timePushed]] + [[delay]]', $queue->db);
            $oldestWaiting = $oldestDue === null ? null : max(0, $now - (int)$oldestDue);
        }

        return [
            'project_config_pending' => Craft::$app->getProjectConfig()->areChangesPending(null, true),
            'updates_pending' => Craft::$app->getUpdates()->getIsUpdatePending(),
            'dev_mode' => (bool)Craft::$app->getConfig()->getGeneral()->devMode,
            'environment' => (string)Craft::$app->env,
            'failed_jobs' => $failedJobs,
            'oldest_waiting_job_seconds' => $oldestWaiting,
        ];
    }

    /**
     * Problems PWM should alert on. Critical ones can break the site; warnings need attention soon.
     *
     * @param array{project_config_pending: bool, updates_pending: bool, dev_mode: bool, environment: string, failed_jobs: ?int, oldest_waiting_job_seconds: ?int} $facts
     * @return array<int,array{code:string,severity:string,message:string}>
     */
    public static function healthIssues(array $facts): array
    {
        $issues = [];

        if ($facts['project_config_pending']) {
            $issues[] = ['code' => 'project_config_pending', 'severity' => 'critical', 'message' => 'Project config changes are not applied; run `craft up`'];
        }
        if ($facts['updates_pending']) {
            $issues[] = ['code' => 'updates_pending', 'severity' => 'critical', 'message' => 'Craft or plugin migrations are pending; run `craft up`'];
        }
        if ($facts['dev_mode'] && $facts['environment'] === 'production') {
            $issues[] = ['code' => 'dev_mode', 'severity' => 'warning', 'message' => 'devMode is on in production'];
        }
        if (($facts['failed_jobs'] ?? 0) > 0) {
            $issues[] = ['code' => 'failed_jobs', 'severity' => 'warning', 'message' => $facts['failed_jobs'].' failed queue '.($facts['failed_jobs'] === 1 ? 'job' : 'jobs')];
        }
        if (($facts['oldest_waiting_job_seconds'] ?? 0) > self::QUEUE_STUCK_SECONDS) {
            $issues[] = ['code' => 'queue_stuck', 'severity' => 'warning', 'message' => 'The queue has not run for '.intdiv((int)$facts['oldest_waiting_job_seconds'], 60).' minutes'];
        }

        return $issues;
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
            'fingerprint' => $this->inventoryFingerprint(),
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
                'date_updated' => $entry->dateUpdated?->format(DATE_ATOM),
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
     * Changes whenever something that affects every page's HTML changes: templates, the
     * project config, Craft or plugin versions, global sets or the SEO settings of any site. When it
     * changes, PWM checks every page again instead of only the ones updated since.
     */
    public function inventoryFingerprint(): string
    {
        $seo = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $seo[(string)$site->handle] = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings((int)$site->id);
        }

        return self::fingerprint([
            'templates' => self::templatesFingerprint(Craft::$app->getPath()->getSiteTemplatesPath()),
            'project_config' => $this->projectConfigFingerprint(),
            'versions' => $this->versions(),
            'globals' => GlobalSet::find()->siteId('*')->status(null)->max('elements.dateUpdated'),
            'seo' => $seo,
        ]);
    }

    /**
     * Hash of the template files' paths, sizes and modification times (not their contents, to stay fast).
     */
    public static function templatesFingerprint(string $path): ?string
    {
        if (!is_dir($path)) {
            return null;
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($path))] = $file->getSize().':'.$file->getMTime();
            }
        }
        ksort($files);

        return self::fingerprint($files);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fingerprint(array $data): string
    {
        return hash('sha256', (string)json_encode($data));
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
