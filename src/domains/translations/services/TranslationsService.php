<?php

namespace pragmatic\webtoolkit\domains\translations\services;

use pragmatic\webtoolkit\db\Query;
use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\domains\translations\records\TranslationGroupRecord;
use pragmatic\webtoolkit\domains\translations\records\TranslationRecord;
use pragmatic\webtoolkit\domains\translations\records\TranslationValueRecord;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Path;
use pragmatic\webtoolkit\db\Command;

use function CraftCms\Cms\t;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TranslationsService
{
    private array $requestCache = [];
    private static bool $tablesReady = false;

    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true, bool $createIfMissing = true, ?string $group = null): string
    {
        $siteId = $siteId ?? Sites::getCurrentSite()->id;
        $normalizedGroup = $this->normalizeGroup($group);
        $value = $this->getValue($key, $siteId, $normalizedGroup);

        if ($value === null && $fallbackToPrimary) {
            foreach ($this->fallbackSiteIds($siteId) as $fallbackSiteId) {
                $value = $this->getValue($key, $fallbackSiteId, $normalizedGroup);
                if ($value !== null) {
                    break;
                }
            }
        }

        if ($value === null && $createIfMissing) {
            $this->ensureKeyExists($key, $normalizedGroup);
        }

        if ($value === null) {
            $value = $key;
        }

        if ($params) {
            foreach ($params as $paramKey => $paramValue) {
                $value = str_replace('{' . $paramKey . '}', (string)$paramValue, $value);
            }
        }

        return $value;
    }

    public function getAllTranslations(?string $search = null, ?string $group = null, ?int $limit = null, ?int $offset = null, ?array $allowedGroups = null): array
    {
        $this->ensureTables();

        $baseQuery = (new Query())
            ->select([
                't.id',
                't.key',
                't.group',
            ])
            ->from(['t' => TranslationRecord::tableName()]);

        if ($group !== null && $group !== '') {
            $baseQuery->andWhere(['t.group' => $group]);
        }
        if ($allowedGroups !== null) {
            if (empty($allowedGroups)) {
                return [];
            }
            $baseQuery->andWhere(['t.group' => $allowedGroups]);
        }

        if ($search !== null && $search !== '') {
            $baseQuery->andWhere([
                'or',
                ['like', 't.key', $search],
            ]);
        }

        $baseQuery->orderBy(['t.key' => SORT_ASC, 't.id' => SORT_ASC]);

        if ($limit !== null) {
            $baseQuery->limit($limit);
        }
        if ($offset !== null) {
            $baseQuery->offset($offset);
        }

        $keyRows = $baseQuery->all();
        if (empty($keyRows)) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $keyRows);
        $valueRows = (new Query())
            ->select([
                'v.translationId',
                'v.siteId',
                'v.value',
            ])
            ->from(['v' => TranslationValueRecord::tableName()])
            ->where(['v.translationId' => $ids])
            ->all();

        $valuesByTranslationId = [];
        foreach ($valueRows as $row) {
            $translationId = (int)$row['translationId'];
            $siteId = (int)$row['siteId'];
            $valuesByTranslationId[$translationId][$siteId] = $row['value'];
        }

        $translations = [];
        foreach ($keyRows as $row) {
            $id = (int)$row['id'];
            $translations[] = [
                'id' => $id,
                'key' => $row['key'],
                'group' => $row['group'],
                'values' => $valuesByTranslationId[$id] ?? [],
            ];
        }

        return $translations;
    }

    public function countTranslations(?string $search = null, ?string $group = null, ?array $allowedGroups = null): int
    {
        $this->ensureTables();

        $query = (new Query())
            ->from(['t' => TranslationRecord::tableName()]);

        if ($group !== null && $group !== '') {
            $query->andWhere(['t.group' => $group]);
        }
        if ($allowedGroups !== null) {
            if (empty($allowedGroups)) {
                return 0;
            }
            $query->andWhere(['t.group' => $allowedGroups]);
        }

        if ($search !== null && $search !== '') {
            $query->andWhere([
                'or',
                ['like', 't.key', $search],
            ]);
        }

        return (int)$query->count();
    }

    public function saveTranslations(array $items): void
    {
        $this->ensureTables();
        DB::beginTransaction();

        try {
            foreach ($items as $item) {
                if (!empty($item['delete']) && !empty($item['id'])) {
                    $this->deleteTranslationById((int)$item['id']);
                    continue;
                }

                $key = trim((string)($item['key'] ?? ''));
                if ($key === '') {
                    continue;
                }

                $record = null;
                if (!empty($item['id'])) {
                    $record = TranslationRecord::findOne((int)$item['id']);
                }
                $incomingGroup = $this->normalizeGroup($item['group'] ?? null);

                if (!$record) {
                    $record = TranslationRecord::find()
                        ->where(['key' => $key, 'group' => $incomingGroup])
                        ->one();
                }

                if (!$record) {
                    $record = new TranslationRecord();
                }

                $record->key = $key;

                $preserveMeta = !empty($item['preserveMeta']);
                $hasGroup = array_key_exists('group', $item);

                if (!$preserveMeta || !$record->id || $hasGroup) {
                    $record->group = $incomingGroup;
                }

                $this->ensureGroupExists($record->group);

                $record->description = null;

                if (!$record->save()) {
                    throw new \RuntimeException('Failed to save translation key: ' . $key);
                }

                $translationId = (int)$record->id;
                $values = (array)($item['values'] ?? []);
                foreach ($values as $siteId => $value) {
                    $siteId = (int)$siteId;
                    $value = (string)$value;

                    $valueRecord = TranslationValueRecord::findOne([
                        'translationId' => $translationId,
                        'siteId' => $siteId,
                    ]);

                    if ($value === '') {
                        if ($valueRecord) {
                            $valueRecord->delete();
                        }
                        continue;
                    }

                    if (!$valueRecord) {
                        $valueRecord = new TranslationValueRecord();
                        $valueRecord->translationId = $translationId;
                        $valueRecord->siteId = $siteId;
                    }

                    $valueRecord->value = $value;
                    if (!$valueRecord->save()) {
                        throw new \RuntimeException('Failed to save translation value for key: ' . $key);
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->requestCache = [];
    }

    public function getTranslationsBySiteId(int $siteId, ?string $group = null): array
    {
        $this->ensureTables();
        $group = $this->normalizeGroup($group);

        $rows = (new Query())
            ->select(['t.key', 'v.value'])
            ->from(['t' => TranslationRecord::tableName()])
            ->leftJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]] AND [[v.siteId]] = :siteId', [':siteId' => $siteId])
            ->where(['t.group' => $group])
            ->orderBy(['t.key' => SORT_ASC])
            ->all();

        $translations = [];
        foreach ($rows as $row) {
            $translations[$row['key']] = $row['value'] ?? '';
        }

        return $translations;
    }

    public function getValueWithFallback(string $key, ?int $siteId = null, bool $fallbackToPrimary = true, bool $createIfMissing = true, ?string $group = null): ?string
    {
        $siteId = $siteId ?? Sites::getCurrentSite()->id;
        $normalizedGroup = $this->normalizeGroup($group);
        $value = $this->getValue($key, $siteId, $normalizedGroup);

        if ($value === null && $fallbackToPrimary) {
            foreach ($this->fallbackSiteIds($siteId) as $fallbackSiteId) {
                $value = $this->getValue($key, $fallbackSiteId, $normalizedGroup);
                if ($value !== null) {
                    break;
                }
            }
        }

        if ($value === null && $createIfMissing) {
            $this->ensureKeyExists($key, $normalizedGroup);
        }

        return $value;
    }

    private function fallbackSiteIds(int $siteId): array
    {
        $sitesService = app(\CraftCms\Cms\Site\Sites::class);
        $fallbackSiteIds = [];
        $site = $sitesService->getSiteById($siteId);

        if ($site !== null) {
            foreach ($sitesService->getAllSites() as $candidateSite) {
                if ($candidateSite->id === $siteId || $candidateSite->language !== $site->language) {
                    continue;
                }

                $fallbackSiteIds[] = (int)$candidateSite->id;
            }
        }

        $primarySiteId = (int)$sitesService->getPrimarySite()->id;
        if ($primarySiteId !== $siteId && !in_array($primarySiteId, $fallbackSiteIds, true)) {
            $fallbackSiteIds[] = $primarySiteId;
        }

        return $fallbackSiteIds;
    }

    public function ensureKeyExists(string $key, ?string $group = null): void
    {
        $this->ensureTables();

        $key = trim($key);
        if ($key === '') {
            return;
        }
        $normalizedGroup = $this->normalizeGroup($group);
        $this->ensureGroupExists($normalizedGroup);

        $record = TranslationRecord::find()
            ->where(['key' => $key, 'group' => $normalizedGroup])
            ->one();
        if ($record) {
            return;
        }

        $record = new TranslationRecord();
        $record->key = $key;
        $record->group = $normalizedGroup;
        $record->description = null;

        try {
            $record->save(false);
        } catch (\Throwable $e) {
            // Ignore race conditions for duplicate keys
        }
    }

    public function getGroups(): array
    {
        $this->ensureTables();

        $groups = (new Query())
            ->select(['g.name'])
            ->from(['g' => TranslationGroupRecord::tableName()])
            ->orderBy([
                'g.sortOrder' => SORT_ASC,
                'g.name' => SORT_ASC,
            ])
            ->column();

        // Keep `site` always first, then the persisted order for the rest.
        $groups = array_values(array_filter($groups, static fn(string $name): bool => $name !== 'site'));
        array_unshift($groups, 'site');
        return array_values(array_unique($groups));
    }

    public function getGroupsWithState(): array
    {
        $this->ensureTables();

        $rows = (new Query())
            ->select(['g.name', 'g.isActive', 'g.sortOrder'])
            ->from(['g' => TranslationGroupRecord::tableName()])
            ->orderBy([
                'g.sortOrder' => SORT_ASC,
                'g.name' => SORT_ASC,
            ])
            ->all();

        $groups = [];
        foreach ($rows as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $groups[] = [
                'name' => $name,
                'isActive' => (bool)($row['isActive'] ?? true),
                'sortOrder' => (int)($row['sortOrder'] ?? 0),
            ];
        }

        usort($groups, static fn(array $a, array $b): int => ($a['name'] === 'site' ? -1 : (($b['name'] === 'site' ? 1 : 0)))
            ?: ($a['sortOrder'] <=> $b['sortOrder'])
            ?: strcmp($a['name'], $b['name']));

        return $groups;
    }

    public function getActiveGroups(): array
    {
        $groups = [];
        foreach ($this->getGroupsWithState() as $row) {
            if (!empty($row['isActive']) || $row['name'] === 'site') {
                $groups[] = (string)$row['name'];
            }
        }

        $groups = array_values(array_unique($groups));
        if (!in_array('site', $groups, true)) {
            array_unshift($groups, 'site');
        }

        return $groups;
    }

    public function addGroup(string $name): void
    {
        $this->ensureGroupExists($name);
    }

    public function deleteGroup(string $name): void
    {
        $name = $this->normalizeGroup($name);
        if ($name === 'site') {
            return;
        }

        TranslationGroupRecord::deleteAll(['name' => $name]);
        TranslationRecord::updateAll(['group' => 'site'], ['group' => $name]);
    }

    public function ensureGroupExists(?string $name): string
    {
        $this->ensureTables();

        $name = $this->normalizeGroup($name);
        if ($name === 'site') {
            return $name;
        }

        if (!TranslationGroupRecord::find()->where(['name' => $name])->exists()) {
            $record = new TranslationGroupRecord();
            $record->name = $name;
            $record->sortOrder = $this->nextGroupSortOrder();
            $record->isActive = true;
            $record->save(false);
        }

        return $name;
    }

    public function saveGroups(array $items): void
    {
        $orderedGroups = [];
        foreach ($items as $item) {
            $rawOriginal = trim((string)($item['original'] ?? ''));
            $original = $rawOriginal === '' ? '' : $this->normalizeGroup($rawOriginal);
            $name = $this->normalizeGroup($item['name'] ?? '');
            $delete = !empty($item['delete']);
            $sortOrder = max(0, (int)($item['sortOrder'] ?? 0));
            $isActive = !array_key_exists('isActive', $item) || !empty($item['isActive']);

            if ($original === 'site') {
                continue;
            }

            if ($delete && $original !== '') {
                $this->deleteGroup($original);
                continue;
            }

            if ($original === '' && $name !== '') {
                $this->ensureGroupExists($name);
                TranslationGroupRecord::updateAll(['isActive' => $isActive ? 1 : 0], ['name' => $name]);
                $orderedGroups[] = ['name' => $name, 'sortOrder' => $sortOrder];
                continue;
            }

            if ($original !== '' && $name !== '' && $original !== $name) {
                $this->ensureGroupExists($name);
                TranslationRecord::updateAll(['group' => $name], ['group' => $original]);
                TranslationGroupRecord::deleteAll(['name' => $original]);
                TranslationGroupRecord::updateAll(['isActive' => $isActive ? 1 : 0], ['name' => $name]);
                $orderedGroups[] = ['name' => $name, 'sortOrder' => $sortOrder];
                continue;
            }

            if ($original !== '' && $name !== '') {
                TranslationGroupRecord::updateAll(['isActive' => $isActive ? 1 : 0], ['name' => $name]);
                $orderedGroups[] = ['name' => $name, 'sortOrder' => $sortOrder];
            }
        }

        // Keep site pinned, and persist the current non-site order.
        TranslationGroupRecord::updateAll(['sortOrder' => 0, 'isActive' => 1], ['name' => 'site']);
        usort($orderedGroups, static fn(array $a, array $b): int => ($a['sortOrder'] <=> $b['sortOrder']) ?: strcmp($a['name'], $b['name']));
        $sortOrder = 1;
        $seen = [];
        foreach ($orderedGroups as $groupItem) {
            $groupName = (string)$groupItem['name'];
            if (isset($seen[$groupName])) {
                continue;
            }
            $seen[$groupName] = true;
            if ($groupName === 'site') {
                continue;
            }
            TranslationGroupRecord::updateAll(['sortOrder' => $sortOrder], ['name' => $groupName]);
            $sortOrder++;
        }
    }

    public function deleteTranslationById(int $id): void
    {
        $this->ensureTables();

        $record = TranslationRecord::findOne($id);
        if ($record) {
            $record->delete();
        }
    }

    public function scanProjectTemplatesForTranslatableKeys(?string $group = 'site', ?array $allowedGroups = null): array
    {
        $group = $group !== null ? $this->normalizeGroup($group) : '';
        $this->ensureTables();

        if ($group !== '') {
            $this->ensureGroupExists($group);
            $scopeGroups = [$group];
            $fallbackGroup = $group;
        } else {
            $scopeGroups = $allowedGroups !== null
                ? array_values(array_unique(array_map(fn($item): string => $this->normalizeGroup($item), $allowedGroups)))
                : $this->getActiveGroups();
            $scopeGroups = array_values(array_filter($scopeGroups, static fn(string $item): bool => $item !== ''));
            $fallbackGroup = 'site';
        }

        $scan = $this->scanTemplateTranslatableKeys($fallbackGroup);
        $templateDirs = $scan['directories'];
        $fileCount = (int)$scan['filesScanned'];
        $matchCount = (int)$scan['matchesFound'];
        $keysByGroup = $scan['keysByGroup'];

        $pairs = [];
        foreach ($keysByGroup as $targetGroup => $groupKeys) {
            if (!empty($scopeGroups) && !in_array($targetGroup, $scopeGroups, true)) {
                continue;
            }
            $keys = array_keys($groupKeys);
            sort($keys);
            foreach ($keys as $key) {
                $pairs[] = ['group' => $targetGroup, 'key' => $key];
            }
        }

        $existingQuery = (new Query())
            ->select(['key', 'group'])
            ->from(TranslationRecord::tableName());

        if ($group !== '') {
            $existingQuery->where(['group' => $group]);
        } elseif (!empty($scopeGroups)) {
            $existingQuery->where(['group' => $scopeGroups]);
        }

        $existingRows = $existingQuery->all();
        $existingMap = [];
        foreach ($existingRows as $row) {
            $existingMap[(string)$row['group'] . "\n" . (string)$row['key']] = true;
        }

        $items = [];
        foreach ($pairs as $pair) {
            $compoundKey = $pair['group'] . "\n" . $pair['key'];
            if (isset($existingMap[$compoundKey])) {
                continue;
            }
            $items[] = [
                'key' => $pair['key'],
                'group' => $pair['group'],
                'values' => [],
            ];
        }

        if (!empty($items)) {
            $this->saveTranslations($items);
        }

        $scannedMap = [];
        foreach ($pairs as $pair) {
            $scannedMap[$pair['group'] . "\n" . $pair['key']] = true;
        }

        $keysRemoved = 0;
        if (!empty($existingRows)) {
            $keysToDelete = [];
            foreach ($existingRows as $row) {
                $compoundKey = (string)$row['group'] . "\n" . (string)$row['key'];
                if (!isset($scannedMap[$compoundKey])) {
                    $keysToDelete[] = ['group' => (string)$row['group'], 'key' => (string)$row['key']];
                }
            }

            if (!empty($keysToDelete)) {
                TranslationRecord::deleteAll([
                    'or',
                    ...array_map(
                        static fn(array $pair): array => ['group' => $pair['group'], 'key' => $pair['key']],
                        $keysToDelete
                    ),
                ]);
                $this->requestCache = [];
                $keysRemoved = count($keysToDelete);
            }
        }

        return [
            'directories' => $templateDirs,
            'filesScanned' => $fileCount,
            'matchesFound' => $matchCount,
            'keysFound' => count($pairs),
            'keysAdded' => count($items),
            'keysRemoved' => $keysRemoved,
        ];
    }

    public function previewUnusedStaticTranslations(?string $group = null, ?array $allowedGroups = null): array
    {
        $this->ensureTables();

        $group = $group !== null ? $this->normalizeGroup($group) : '';
        $allowedGroups = $allowedGroups !== null
            ? array_values(array_unique(array_map(fn($item): string => $this->normalizeGroup($item), $allowedGroups)))
            : null;

        $scan = $this->scanTemplateTranslatableKeys();
        $keysByGroup = $scan['keysByGroup'];

        $query = (new Query())
            ->select(['t.id', 't.key', 't.group'])
            ->from(['t' => TranslationRecord::tableName()]);

        if ($group !== '') {
            $query->where(['t.group' => $group]);
        } elseif ($allowedGroups !== null) {
            if (empty($allowedGroups)) {
                return [
                    'directories' => $scan['directories'],
                    'filesScanned' => (int)$scan['filesScanned'],
                    'matchesFound' => (int)$scan['matchesFound'],
                    'scopeGroup' => '',
                    'scopeGroups' => [],
                    'totalEntriesInScope' => 0,
                    'unusedCount' => 0,
                    'unusedSample' => [],
                    'unusedIds' => [],
                ];
            }
            $query->where(['t.group' => $allowedGroups]);
        }

        $rows = $query->orderBy(['t.group' => SORT_ASC, 't.key' => SORT_ASC])->all();

        $unused = [];
        foreach ($rows as $row) {
            $rowGroup = (string)$row['group'];
            $rowKey = (string)$row['key'];
            if (!isset($keysByGroup[$rowGroup][$rowKey])) {
                $unused[] = [
                    'id' => (int)$row['id'],
                    'group' => $rowGroup,
                    'key' => $rowKey,
                ];
            }
        }

        return [
            'directories' => $scan['directories'],
            'filesScanned' => (int)$scan['filesScanned'],
            'matchesFound' => (int)$scan['matchesFound'],
            'scopeGroup' => $group,
            'scopeGroups' => $group !== '' ? [$group] : ($allowedGroups ?? []),
            'totalEntriesInScope' => count($rows),
            'unusedCount' => count($unused),
            'unusedSample' => array_slice($unused, 0, 20),
            'unusedIds' => array_map(static fn(array $item): int => (int)$item['id'], $unused),
        ];
    }

    public function deleteUnusedStaticTranslations(?string $group = null, ?array $allowedGroups = null): array
    {
        $preview = $this->previewUnusedStaticTranslations($group, $allowedGroups);
        $ids = (array)($preview['unusedIds'] ?? []);
        if (!empty($ids)) {
            TranslationRecord::deleteAll(['id' => $ids]);
            $this->requestCache = [];
        }

        $preview['deletedCount'] = count($ids);
        return $preview;
    }

    private function scanTemplateTranslatableKeys(string $fallbackGroup = 'site'): array
    {
        $fallbackGroup = $this->normalizeGroup($fallbackGroup);
        $allowedGroups = array_flip($this->getGroups());
        $allowedGroups[$fallbackGroup] = true;
        $templateDirs = $this->discoverProjectTemplateDirs();
        $keysByGroup = [];
        $fileCount = 0;
        $matchCount = 0;

        foreach ($templateDirs as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower((string)$file->getExtension()) !== 'twig') {
                    continue;
                }

                $fileCount++;
                $contents = @file_get_contents($file->getPathname());
                if ($contents === false || $contents === '') {
                    continue;
                }

                preg_match_all(
                    '/([\'\"])((?:\\\\.|(?!\\1).)*)\\1\\s*\\|\\s*t(?:\\s*\\(\\s*([\'\"])((?:\\\\.|(?!\\3).)*)\\3)?/m',
                    $contents,
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as $match) {
                    $matchCount++;
                    $domain = isset($match[4]) ? $this->unescapeTwigString((string)$match[4]) : '';
                    if ($domain !== '') {
                        $targetGroup = $this->normalizeGroup($domain);
                        if (!isset($allowedGroups[$targetGroup])) {
                            continue;
                        }
                    } else {
                        $targetGroup = $fallbackGroup;
                    }

                    $key = $this->unescapeTwigString((string)$match[2]);
                    $key = trim($key);
                    if ($key === '') {
                        continue;
                    }
                    $keysByGroup[$targetGroup][$key] = true;
                }
            }
        }

        return [
            'directories' => $templateDirs,
            'filesScanned' => $fileCount,
            'matchesFound' => $matchCount,
            'keysByGroup' => $keysByGroup,
        ];
    }

    private function getValue(string $key, int $siteId, ?string $group = null): ?string
    {
        $this->ensureTables();
        $group = $this->normalizeGroup($group);

        $cacheKey = $siteId . ':' . $group . ':' . $key;
        if (array_key_exists($cacheKey, $this->requestCache)) {
            return $this->requestCache[$cacheKey];
        }

        $value = (new Query())
            ->select(['v.value'])
            ->from(['t' => TranslationRecord::tableName()])
            ->innerJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]]')
            ->where(['t.key' => $key, 't.group' => $group, 'v.siteId' => $siteId])
            ->scalar();

        $value = $value !== false ? (string)$value : null;
        $this->requestCache[$cacheKey] = $value;

        return $value;
    }

    private function discoverProjectTemplateDirs(): array
    {
        $dirs = [];

        $templatesPath = Path::siteTemplates();
        if (is_string($templatesPath) && $templatesPath !== '' && is_dir($templatesPath)) {
            $resolvedTemplatesPath = realpath($templatesPath);
            if ($resolvedTemplatesPath !== false) {
                $dirs[] = $resolvedTemplatesPath;
            }
        }

        $dirs = array_values(array_unique($dirs));
        sort($dirs);

        return $dirs;
    }

    private function unescapeTwigString(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/s', static fn(array $m): string => $m[1], $value) ?? $value;
    }

    private function normalizeGroup($group): string
    {
        $group = trim((string)$group);
        if ($group === '') {
            return 'site';
        }

        return $group;
    }

    private function ensureTables(): void
    {
        // L’esquema el crea la migració Install.
    }

    private function nextGroupSortOrder(): int
    {
        $max = (new Query())
            ->from(TranslationGroupRecord::tableName())
            ->where(['!=', 'name', 'site'])
            ->max('sortOrder');

        return ((int)$max) + 1;
    }
}
