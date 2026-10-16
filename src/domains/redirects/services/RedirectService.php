<?php

namespace pragmatic\webtoolkit\domains\redirects\services;

use Craft;
use craft\db\Query;
use craft\elements\Element;
use craft\events\ElementEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use yii\caching\TagDependency;

/**
 * Stores redirect rules, matches 404s, and maintains the 404 log.
 */
class RedirectService
{
    private const RULES = '{{%pragmatic_toolkit_redirects}}';
    private const LOGS = '{{%pragmatic_toolkit_redirect_404s}}';
    private const CACHE_KEY = 'pwt.redirects.compiled';
    private const CACHE_TAG = 'pwt.redirects';

    /** @var RedirectMatcher */
    private RedirectMatcher $matcher;

    /**
     * Create service and matcher.
     */
    public function __construct()
    {
        $this->matcher = new RedirectMatcher();
    }

    /**
     * Return redirect rules for CP listing.
     *
     * @param array<string, mixed> $filters Search and filter values.
     * @return array<int, array<string, mixed>> Rules.
     */
    public function getRules(array $filters = []): array
    {
        $query = (new Query())->from(self::RULES)->orderBy(['hitCount' => SORT_DESC, 'id' => SORT_DESC]);
        if (!empty($filters['siteId'])) {
            $query->andWhere(['siteId' => (int)$filters['siteId']]);
        }
        if (isset($filters['enabled']) && $filters['enabled'] !== '') {
            $query->andWhere(['enabled' => (bool)$filters['enabled']]);
        }
        if (!empty($filters['statusCode'])) {
            $query->andWhere(['statusCode' => (int)$filters['statusCode']]);
        }
        if (!empty($filters['matchType'])) {
            $query->andWhere(['matchType' => (string)$filters['matchType']]);
        }
        if (!empty($filters['q'])) {
            $query->andWhere(['or', ['like', 'sourceUri', (string)$filters['q']], ['like', 'destination', (string)$filters['q']], ['like', 'notes', (string)$filters['q']]]);
        }
        return $query->all();
    }

    /**
     * Find one rule by identifier.
     *
     * @param int $id Rule ID.
     * @return array<string, mixed>|null Rule row.
     */
    public function getRule(int $id): ?array
    {
        $row = (new Query())->from(self::RULES)->where(['id' => $id])->one();
        return $row ?: null;
    }

    /**
     * Validate and save a rule. Returns a validation error string when rejected.
     *
     * @param array<string, mixed> $values Rule fields.
     * @param int|null $id Existing rule ID.
     * @return int|string Saved ID or error.
     */
    public function saveRule(array $values, ?int $id = null): int|string
    {
        $source = trim((string)($values['sourceUri'] ?? ''), '/ ');
        $destination = trim((string)($values['destination'] ?? ''));
        $matchType = (string)($values['matchType'] ?? 'exact');
        $source = $matchType === 'regex' ? trim($source, '/') : $this->matcher->normalize($source);
        $statusCode = (int)($values['statusCode'] ?? 301);
        $destinationElementId = !empty($values['destinationElementId']) ? (int)$values['destinationElementId'] : null;
        if ($source === '' || !in_array($matchType, ['exact', 'pattern', 'regex'], true)) {
            return 'A source URI and valid match type are required.';
        }
        if ($matchType === 'regex' && !$this->matcher->isValidRegex($source)) {
            return 'The regular expression is invalid.';
        }
        if (!in_array($statusCode, [301, 302, 307, 410], true)) {
            return 'The status code must be 301, 302, 307, or 410.';
        }
        if ($statusCode !== 410 && $destination === '' && $destinationElementId === null) {
            return 'A destination is required unless the status is 410.';
        }
        if ($this->matcher->isSelfRedirect($source, $destination)) {
            return 'The rule redirects to itself.';
        }
        $siteId = !empty($values['siteId']) ? (int)$values['siteId'] : null;
        if ($destinationElementId !== null) {
            $destinationElement = Craft::$app->getElements()->getElementById($destinationElementId, null, $siteId);
            if ($destinationElement instanceof Element && $this->matcher->isSelfRedirect($source, (string)$destinationElement->getUrl())) {
                return 'The rule redirects to itself.';
            }
        }
        if ($this->hasSimpleLoop($source, $destination, $siteId, $id)) {
            return 'This rule creates a redirect loop.';
        }
        $now = Db::prepareDateForDb(new \DateTime());
        $row = [
            'siteId' => $siteId,
            'sourceUri' => $source,
            'matchType' => $matchType,
            'destination' => $destination,
            'destinationElementId' => $destinationElementId,
            'statusCode' => $statusCode,
            'enabled' => (bool)($values['enabled'] ?? true),
            'preserveQueryString' => (bool)($values['preserveQueryString'] ?? true),
            'notes' => (string)($values['notes'] ?? ''),
            'dateUpdated' => $now,
        ];
        if ($id !== null) {
            Craft::$app->getDb()->createCommand()->update(self::RULES, $row, ['id' => $id])->execute();
            $savedId = $id;
        } else {
            $row += ['hitCount' => 0, 'lastHitAt' => null, 'dateCreated' => $now, 'uid' => StringHelper::UUID()];
            Craft::$app->getDb()->createCommand()->insert(self::RULES, $row)->execute();
            $savedId = (int)Craft::$app->getDb()->getLastInsertID();
        }
        $this->invalidateRules();
        return $savedId;
    }

    /**
     * Remove a rule.
     *
     * @param int $id Rule ID.
     * @return void
     */
    public function deleteRule(int $id): void
    {
        Craft::$app->getDb()->createCommand()->delete(self::RULES, ['id' => $id])->execute();
        $this->invalidateRules();
    }

    /**
     * Toggle a rule's enabled state.
     *
     * @param int $id Rule ID.
     * @param bool $enabled New state.
     * @return void
     */
    public function setEnabled(int $id, bool $enabled): void
    {
        Craft::$app->getDb()->createCommand()->update(self::RULES, ['enabled' => $enabled, 'dateUpdated' => Db::prepareDateForDb(new \DateTime())], ['id' => $id])->execute();
        $this->invalidateRules();
    }

    /**
     * Match one URL against rules and return the resolved redirect.
     *
     * @param string $path Request path.
     * @param int|null $siteId Current site ID.
     * @param string|null $queryString Request query string.
     * @return array<string, mixed>|null Matched rule and destination.
     */
    public function match(string $path, ?int $siteId = null, ?string $queryString = null, bool $recordHit = true): ?array
    {
        $normalized = $this->matcher->normalize($path);
        $exact = (new Query())->from(self::RULES)->where(['enabled' => true, 'matchType' => 'exact', 'sourceUri' => $normalized])->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]])->orderBy(['siteId' => SORT_DESC])->one();
        $row = $exact ?: null;
        if ($row === null) {
            foreach ($this->compiledRules() as $candidate) {
                if ($candidate['siteId'] !== null && (int)$candidate['siteId'] !== $siteId) {
                    continue;
                }
                $captures = $this->matcher->matchCompiled($normalized, $candidate['matchType'], $candidate['_compiled']);
                if ($captures !== null) {
                    $row = $candidate;
                    $row['_captures'] = $captures;
                    break;
                }
            }
        }
        if ($row === null) {
            return null;
        }
        $destination = (string)$row['destination'];
        if (!empty($row['destinationElementId'])) {
            $element = Craft::$app->getElements()->getElementById((int)$row['destinationElementId'], null, $siteId);
            if (!$element instanceof Element || !$element->getUrl()) {
                return null;
            }
            $destination = $element->getUrl();
        } else {
            $destination = $this->matcher->expandDestination($destination, (array)($row['_captures'] ?? []));
            if ($destination !== '' && !str_starts_with($destination, '/') && !preg_match('#^https?://#i', $destination)) {
                $destination = '/' . $destination;
            }
        }
        if (!empty($row['preserveQueryString']) && $queryString) {
            $separator = str_contains($destination, '?') ? '&' : '?';
            $destination .= $separator . ltrim($queryString, '?');
        }
        if ($recordHit) {
            $this->queueHit((int)$row['id']);
        }
        return ['rule' => $row, 'destination' => $destination, 'statusCode' => (int)$row['statusCode']];
    }

    /**
     * Preview a URL without incrementing usage counters.
     *
     * @param string $path URL path.
     * @param int|null $siteId Site ID.
     * @return array<string, mixed>|null Preview result.
     */
    public function test(string $path, ?int $siteId = null): ?array
    {
        $queryString = parse_url($path, PHP_URL_QUERY);
        $result = $this->match($path, $siteId, is_string($queryString) ? $queryString : null, false);
        if ($result === null) {
            return null;
        }
        $destination = (string)$result['destination'];
        $chain = [$result['rule']];
        for ($depth = 0; $depth < 10 && !preg_match('#^https?://#i', $destination) && (int)$result['statusCode'] !== 410; $depth++) {
            $next = $this->match($destination, $siteId, null, false);
            if ($next === null) {
                break;
            }
            $result = $next;
            $chain[] = $next['rule'];
            $destination = (string)$next['destination'];
        }
        $result['finalDestination'] = $destination;
        $result['chain'] = $chain;
        return $result;
    }

    /**
     * Save or increment a deduplicated 404 record unless ignored.
     *
     * @param string $path Request path.
     * @param int|null $siteId Site ID.
     * @param string|null $referrer Referring URL.
     * @param string|null $userAgent User agent.
     * @return void
     */
    public function record404(string $path, ?int $siteId, ?string $referrer, ?string $userAgent): void
    {
        $settings = PragmaticWebToolkit::$plugin->redirectSettings->get();
        if (!$settings->log404s) {
            return;
        }
        $path = $this->matcher->normalize($path);
        if ($path === '' || $this->isIgnored($path, $settings->ignorePatterns)) {
            return;
        }
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        $pathHash = hash('sha256', $path);
        $siteKey = $siteId ?? 0;
        $existing = (new Query())->from(self::LOGS)->where(['siteKey' => $siteKey, 'pathHash' => $pathHash])->one();
        if ($existing) {
            $db->createCommand()->update(self::LOGS, ['hitCount' => new \yii\db\Expression('[[hitCount]] + 1'), 'lastSeenAt' => $now, 'referrer' => $referrer, 'userAgent' => $userAgent], ['id' => $existing['id']])->execute();
            return;
        }
        $db->createCommand()->insert(self::LOGS, ['siteId' => $siteId, 'siteKey' => $siteKey, 'path' => $path, 'pathHash' => $pathHash, 'referrer' => $referrer, 'userAgent' => $userAgent, 'hitCount' => 1, 'firstSeenAt' => $now, 'lastSeenAt' => $now, 'handled' => false, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()])->execute();
    }

    /**
     * Return 404 log rows for the control panel.
     *
     * @param array<string, mixed> $filters Search filters.
     * @return array<int, array<string, mixed>> Log rows.
     */
    public function get404s(array $filters = []): array
    {
        $query = (new Query())->from(self::LOGS)->orderBy(['hitCount' => SORT_DESC, 'lastSeenAt' => SORT_DESC]);
        if (!empty($filters['siteId'])) {
            $query->andWhere(['siteId' => (int)$filters['siteId']]);
        }
        if (isset($filters['handled']) && $filters['handled'] !== '') {
            $query->andWhere(['handled' => (bool)$filters['handled']]);
        }
        if (!empty($filters['q'])) {
            $query->andWhere(['or', ['like', 'path', (string)$filters['q']], ['like', 'referrer', (string)$filters['q']]]);
        }
        return $query->all();
    }

    /**
     * Mark a 404 record as handled.
     *
     * @param int $id Log row ID.
     * @return void
     */
    public function markHandled(int $id): void
    {
        Craft::$app->getDb()->createCommand()->update(self::LOGS, ['handled' => true, 'dateUpdated' => Db::prepareDateForDb(new \DateTime())], ['id' => $id])->execute();
    }

    /**
     * Check whether a saved redirect participates in a redirect chain.
     *
     * @param int $id Rule ID.
     * @return bool Whether its destination is another exact source.
     */
    public function hasChain(int $id): bool
    {
        $rule = $this->getRule($id);
        if (!$rule || (int)$rule['statusCode'] === 410) {
            return false;
        }
        $destination = (string)$rule['destination'];
        if (!empty($rule['destinationElementId'])) {
            $element = Craft::$app->getElements()->getElementById((int)$rule['destinationElementId'], null, $rule['siteId'] ? (int)$rule['siteId'] : null);
            $destination = $element instanceof Element ? (string)$element->getUrl() : '';
        }
        if ($destination === '' || preg_match('#^https?://#i', $destination)) {
            return false;
        }
        $next = $this->match($destination, $rule['siteId'] ? (int)$rule['siteId'] : null, null, false);
        return $next !== null && (int)$next['rule']['id'] !== $id;
    }

    /**
     * Delete old 404 rows using configured retention.
     *
     * @return int Deleted row count.
     */
    public function prune404s(): int
    {
        $days = max(1, PragmaticWebToolkit::$plugin->redirectSettings->get()->retentionDays);
        return Craft::$app->getDb()->createCommand()->delete(self::LOGS, ['<', 'lastSeenAt', Db::prepareDateForDb(new \DateTime('-' . $days . ' days'))])->execute();
    }

    /**
     * Create automatic entry redirect on URI change.
     *
     * @param ElementEvent $event Element save event.
     * @return void
     */
    public function handleEntrySave(ElementEvent $event): void
    {
        $entry = $event->element;
        if (!$entry instanceof \craft\elements\Entry || $event->isNew || !$entry->getIsCanonical()) {
            return;
        }
        if (ElementHelper::isDraftOrRevision($entry) || $entry->getIsDraft() || (method_exists($entry, 'getIsProvisionalDraft') && $entry->getIsProvisionalDraft()) || (isset($entry->propagating) && $entry->propagating)) {
            return;
        }
        if (!PragmaticWebToolkit::$plugin->redirectSettings->get()->autoRedirects) {
            return;
        }
        $old = (new Query())->select(['uri'])->from('{{%elements_sites}}')->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])->scalar();
        if (!is_string($old) || $old === '' || $old === $entry->uri) {
            return;
        }
        $site = Craft::$app->getSites()->getSiteById((int)$entry->siteId);
        $sitePrefix = trim((string)(parse_url((string)$site?->baseUrl, PHP_URL_PATH) ?: ''), '/');
        $sourceUri = $sitePrefix !== '' && !str_starts_with(trim($old, '/'), $sitePrefix . '/')
            ? $sitePrefix . '/' . trim($old, '/')
            : $old;
        $this->saveRule(['siteId' => $entry->siteId, 'sourceUri' => $sourceUri, 'matchType' => 'exact', 'destinationElementId' => $entry->id, 'statusCode' => 301, 'enabled' => true, 'preserveQueryString' => true, 'notes' => 'Automatically created after entry URI changed.']);
    }

    /**
     * Import CSV rows and report each create, update, or error.
     *
     * @param array<int, array<string, string>> $rows Parsed CSV rows.
     * @param bool $dryRun Preview without writes.
     * @return array<int, array{status:string,message:string,row:array<string,string>}>
     */
    public function importRows(array $rows, bool $dryRun = false): array
    {
        $results = [];
        foreach ($rows as $row) {
            $siteId = null;
            if (!empty($row['siteHandle'])) {
                $site = Craft::$app->getSites()->getSiteByHandle($row['siteHandle']);
                if ($site === null) {
                    $results[] = ['status' => 'error', 'message' => 'Unknown site handle.', 'row' => $row];
                    continue;
                }
                $siteId = (int)$site->id;
            }
            $matchType = (string)($row['matchType'] ?? 'exact');
            $source = (string)($row['source'] ?? '');
            $importSource = $matchType === 'regex' ? trim($source, '/') : $this->matcher->normalize($source);
            $existing = (new Query())->from(self::RULES)->where(['sourceUri' => $importSource, 'siteId' => $siteId, 'matchType' => $matchType])->one();
            $values = ['sourceUri' => $source, 'destination' => $row['destination'] ?? '', 'statusCode' => $row['statusCode'] ?? 301, 'siteId' => $siteId, 'matchType' => $matchType, 'enabled' => true, 'preserveQueryString' => true];
            if ($dryRun) {
                $error = $this->validateImportValues($values, $existing ? (int)$existing['id'] : null);
                $results[] = $error === null ? ['status' => $existing ? 'update' : 'create', 'message' => 'Valid row.', 'row' => $row] : ['status' => 'error', 'message' => $error, 'row' => $row];
                continue;
            }
            $saved = $this->saveRule($values, $existing ? (int)$existing['id'] : null);
            $results[] = is_int($saved) ? ['status' => $existing ? 'update' : 'create', 'message' => 'Saved.', 'row' => $row] : ['status' => 'error', 'message' => (string)$saved, 'row' => $row];
        }
        return $results;
    }

    /**
     * Export rules or 404 log as CSV bytes.
     *
     * @param bool $logs Whether to export the 404 log.
     * @return string CSV content.
     */
    public function exportCsv(bool $logs = false): string
    {
        $rows = $logs ? $this->get404s() : $this->getRules();
        $stream = fopen('php://temp', 'r+');
        $headers = $logs ? ['path', 'siteId', 'referrer', 'userAgent', 'hitCount', 'firstSeenAt', 'lastSeenAt', 'handled'] : ['source', 'destination', 'statusCode', 'siteHandle', 'matchType'];
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            if (!$logs) {
                $site = !empty($row['siteId']) ? Craft::$app->getSites()->getSiteById((int)$row['siteId']) : null;
                $row['source'] = $row['sourceUri'];
                $row['siteHandle'] = $site?->handle ?? '';
            }
            fputcsv($stream, array_map(static fn(string $key): mixed => $row[$key] ?? '', $headers));
        }
        rewind($stream);
        return (string)stream_get_contents($stream);
    }

    /**
     * Return the most specific pattern and regex rule set from tagged cache.
     *
     * @return array<int, array<string, mixed>> Compiled candidate rows.
     */
    private function compiledRules(): array
    {
        return Craft::$app->getCache()->getOrSet(self::CACHE_KEY, function (): array {
            $rows = (new Query())->from(self::RULES)->where(['enabled' => true])->andWhere(['in', 'matchType', ['pattern', 'regex']])->orderBy(['siteId' => SORT_DESC, 'id' => SORT_ASC])->all();
            foreach ($rows as &$row) {
                $row['_compiled'] = $row['matchType'] === 'pattern'
                    ? $this->matcher->compilePattern((string)$row['sourceUri'])
                    : $this->matcher->compileRegex((string)$row['sourceUri']);
            }
            unset($row);
            return $rows;
        }, null, new TagDependency(['tags' => self::CACHE_TAG]));
    }

    /**
     * Invalidate compiled matcher cache.
     *
     * @return void
     */
    private function invalidateRules(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    /**
     * Queue hit count updates for after-response processing.
     *
     * @param int $id Rule ID.
     * @return void
     */
    private function queueHit(int $id): void
    {
        $key = 'pwt.redirect.hit.' . $id;
        $cache = Craft::$app->getCache();
        $cache->add($key, 0, 86400);
        $count = $cache->increment($key);
        if ($count === false || $count < 25) {
            return;
        }
        $cache->decrement($key, 25);
        Craft::$app->on(\yii\base\Application::EVENT_AFTER_REQUEST, static function () use ($id): void {
            Craft::$app->getDb()->createCommand()->update(self::RULES, ['hitCount' => new \yii\db\Expression('[[hitCount]] + 25'), 'lastHitAt' => Db::prepareDateForDb(new \DateTime())], ['id' => $id])->execute();
        });
    }

    /**
     * Check for direct two-rule loops.
     *
     * @param string $source Source.
     * @param string $destination Destination.
     * @param int|null $siteId Site.
     * @param int|null $excludeId Rule ID to ignore.
     * @return bool Whether a reverse rule exists.
     */
    private function hasSimpleLoop(string $source, string $destination, ?int $siteId, ?int $excludeId): bool
    {
        if ($destination === '' || preg_match('#^https?://#i', $destination)) {
            return false;
        }
        $query = (new Query())->from(self::RULES)->where(['enabled' => true, 'matchType' => 'exact', 'sourceUri' => $this->matcher->normalize($destination)])->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]]);
        if ($excludeId !== null) {
            $query->andWhere(['not', ['id' => $excludeId]]);
        }
        $existing = $query->one();
        return $existing !== false && $this->matcher->normalize((string)$existing['destination']) === $this->matcher->normalize($source);
    }

    /**
     * Test path against configured glob-like ignored patterns.
     *
     * @param string $path Normalized path.
     * @param string[] $patterns Ignore patterns.
     * @return bool Whether ignored.
     */
    private function isIgnored(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim((string)$pattern, '/');
            if ($pattern === '') {
                continue;
            }
            $quoted = preg_quote(mb_strtolower($pattern), '#');
            $glob = '#^' . str_replace('\\*', '.*', $quoted) . '$#u';
            if (preg_match($glob, $path) || str_contains($path, mb_strtolower($pattern))) {
                return true;
            }
            foreach (explode('/', $path) as $segment) {
                if (preg_match($glob, $segment)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Validate import essentials without writing a rule.
     *
     * @param array<string, mixed> $values Candidate rule fields.
     * @return string|null Validation message.
     */
    private function validateImportValues(array $values, ?int $excludeId = null): ?string
    {
        $source = (string)($values['sourceUri'] ?? '');
        $destination = (string)($values['destination'] ?? '');
        if ($source === '' || !in_array((string)($values['matchType'] ?? ''), ['exact', 'pattern', 'regex'], true)) {
            return 'Source and supported matchType are required.';
        }
        if (($values['matchType'] ?? '') === 'regex' && !$this->matcher->isValidRegex($source)) {
            return 'The regular expression is invalid.';
        }
        if (!in_array((int)($values['statusCode'] ?? 0), [301, 302, 307, 410], true)) {
            return 'Unsupported status code.';
        }
        if ((int)$values['statusCode'] !== 410 && $destination === '') {
            return 'Destination is required unless statusCode is 410.';
        }
        if ($this->matcher->isSelfRedirect($source, $destination)) {
            return 'The rule redirects to itself.';
        }
        $siteId = !empty($values['siteId']) ? (int)$values['siteId'] : null;
        return $this->hasSimpleLoop($source, $destination, $siteId, $excludeId) ? 'This rule creates a redirect loop.' : null;
    }

}
