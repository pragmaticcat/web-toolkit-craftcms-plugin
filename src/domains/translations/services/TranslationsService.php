<?php

namespace pragmatic\webtoolkit\domains\translations\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\domains\translations\records\TranslationGroupRecord;
use pragmatic\webtoolkit\domains\translations\records\TranslationRecord;
use pragmatic\webtoolkit\domains\translations\records\TranslationValueRecord;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TranslationsService extends Component
{
    private array $requestCache = [];
    private static bool $tablesReady = false;

    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true, bool $createIfMissing = true, ?string $group = null): string
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $value = $this->getValue($key, $siteId);

        if ($value === null && $fallbackToPrimary) {
            $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
            if ($primarySiteId !== $siteId) {
                $value = $this->getValue($key, $primarySiteId);
            }
        }

        if ($value === null && $createIfMissing) {
            $this->ensureKeyExists($key, $group);
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

    public function getAllTranslations(?string $search = null, ?string $group = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->ensureTables();

        $query = (new Query())
            ->select([
                't.id',
                't.key',
                't.group',
                'v.siteId',
                'v.value',
            ])
            ->from(['t' => TranslationRecord::tableName()])
            ->leftJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]]');

        if ($group !== null && $group !== '') {
            $query->andWhere(['t.group' => $group]);
        }

        if ($search !== null && $search !== '') {
            $query->andWhere([
                'or',
                ['like', 't.key', $search],
            ]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }
        if ($offset !== null) {
            $query->offset($offset);
        }

        $rows = $query
            ->orderBy(['t.key' => SORT_ASC])
            ->all();

        $translations = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!isset($translations[$id])) {
                $translations[$id] = [
                    'id' => $id,
                    'key' => $row['key'],
                    'group' => $row['group'],
                    'values' => [],
                ];
            }
            if ($row['siteId'] !== null) {
                $translations[$id]['values'][(int)$row['siteId']] = $row['value'];
            }
        }

        return array_values($translations);
    }

    public function countTranslations(?string $search = null, ?string $group = null): int
    {
        $this->ensureTables();

        $query = (new Query())
            ->from(['t' => TranslationRecord::tableName()]);

        if ($group !== null && $group !== '') {
            $query->andWhere(['t.group' => $group]);
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

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

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

                if (!$record) {
                    $record = TranslationRecord::find()->where(['key' => $key])->one();
                }

                if (!$record) {
                    $record = new TranslationRecord();
                }

                $record->key = $key;

                $preserveMeta = !empty($item['preserveMeta']);
                $hasGroup = array_key_exists('group', $item);

                if (!$preserveMeta || !$record->id || $hasGroup) {
                    $record->group = $this->normalizeGroup($item['group'] ?? null);
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

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->requestCache = [];
    }

    public function getTranslationsBySiteId(int $siteId): array
    {
        $this->ensureTables();

        $rows = (new Query())
            ->select(['t.key', 'v.value'])
            ->from(['t' => TranslationRecord::tableName()])
            ->leftJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]] AND [[v.siteId]] = :siteId', [':siteId' => $siteId])
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
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $value = $this->getValue($key, $siteId);

        if ($value === null && $fallbackToPrimary) {
            $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
            if ($primarySiteId !== $siteId) {
                $value = $this->getValue($key, $primarySiteId);
            }
        }

        if ($value === null && $createIfMissing) {
            $this->ensureKeyExists($key, $group);
        }

        return $value;
    }

    public function ensureKeyExists(string $key, ?string $group = null): void
    {
        $this->ensureTables();

        $key = trim($key);
        if ($key === '') {
            return;
        }

        $record = TranslationRecord::find()->where(['key' => $key])->one();
        if ($record) {
            if ($record->group === null || $record->group === '') {
                $record->group = $this->normalizeGroup($group);
                $record->save(false);
            }
            return;
        }

        $record = new TranslationRecord();
        $record->key = $key;
        $record->group = $this->normalizeGroup($group);
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
            ->orderBy(['g.name' => SORT_ASC])
            ->column();

        if (!in_array('site', $groups, true)) {
            $groups[] = 'site';
        }

        sort($groups);
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
            $record->save(false);
        }

        return $name;
    }

    public function saveGroups(array $items): void
    {
        foreach ($items as $item) {
            $original = $this->normalizeGroup($item['original'] ?? '');
            $name = $this->normalizeGroup($item['name'] ?? '');
            $delete = !empty($item['delete']);

            if ($original === 'site') {
                continue;
            }

            if ($delete && $original !== '') {
                $this->deleteGroup($original);
                continue;
            }

            if ($original === '' && $name !== '') {
                $this->ensureGroupExists($name);
                continue;
            }

            if ($original !== '' && $name !== '' && $original !== $name) {
                $this->ensureGroupExists($name);
                TranslationRecord::updateAll(['group' => $name], ['group' => $original]);
                TranslationGroupRecord::deleteAll(['name' => $original]);
            }
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

    public function scanProjectTemplatesForTranslatableKeys(string $group = 'site'): array
    {
        $group = $this->normalizeGroup($group);
        $templateDirs = $this->discoverProjectTemplateDirs();
        $keys = [];
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
                    if ($domain !== '' && $domain !== 'site') {
                        continue;
                    }

                    $key = $this->unescapeTwigString((string)$match[2]);
                    $key = trim($key);
                    if ($key === '') {
                        continue;
                    }
                    $keys[$key] = true;
                }
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        if (empty($keys)) {
            return [
                'directories' => $templateDirs,
                'filesScanned' => $fileCount,
                'matchesFound' => $matchCount,
                'keysFound' => 0,
                'keysAdded' => 0,
            ];
        }

        $existingKeys = (new Query())
            ->select(['key'])
            ->from(TranslationRecord::tableName())
            ->where(['key' => $keys])
            ->column();
        $existingMap = array_fill_keys(array_map('strval', $existingKeys), true);

        $items = [];
        foreach ($keys as $key) {
            if (isset($existingMap[$key])) {
                continue;
            }
            $items[] = [
                'key' => $key,
                'group' => $group,
                'values' => [],
            ];
        }

        if (!empty($items)) {
            $this->saveTranslations($items);
        }

        return [
            'directories' => $templateDirs,
            'filesScanned' => $fileCount,
            'matchesFound' => $matchCount,
            'keysFound' => count($keys),
            'keysAdded' => count($items),
        ];
    }

    private function getValue(string $key, int $siteId): ?string
    {
        $this->ensureTables();

        $cacheKey = $siteId . ':' . $key;
        if (array_key_exists($cacheKey, $this->requestCache)) {
            return $this->requestCache[$cacheKey];
        }

        $value = (new Query())
            ->select(['v.value'])
            ->from(['t' => TranslationRecord::tableName()])
            ->innerJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]]')
            ->where(['t.key' => $key, 'v.siteId' => $siteId])
            ->scalar();

        $value = $value !== false ? (string)$value : null;
        $this->requestCache[$cacheKey] = $value;

        return $value;
    }

    private function discoverProjectTemplateDirs(): array
    {
        $dirs = [];

        $templatesPath = Craft::getAlias('@templates', false);
        if (is_string($templatesPath) && is_dir($templatesPath)) {
            $dirs[] = $templatesPath;
        }

        $rootPath = Craft::getAlias('@root', false);
        if (is_string($rootPath)) {
            $modulesPath = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'modules';
            if (is_dir($modulesPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($modulesPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                );
                /** @var SplFileInfo $entry */
                foreach ($iterator as $entry) {
                    if (!$entry->isDir()) {
                        continue;
                    }
                    if ($entry->getFilename() !== 'templates') {
                        continue;
                    }
                    $dirs[] = $entry->getPathname();
                }
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
        if (self::$tablesReady) {
            return;
        }
        self::$tablesReady = true;

        $db = Craft::$app->getDb();
        $keysTable = TranslationRecord::tableName();
        $valuesTable = TranslationValueRecord::tableName();
        $groupsTable = TranslationGroupRecord::tableName();

        if (!$db->tableExists($keysTable)) {
            $db->createCommand()->createTable($keysTable, [
                'id' => 'pk',
                'key' => 'string NOT NULL',
                'group' => "string NOT NULL DEFAULT 'site'",
                'description' => 'text',
                'dateCreated' => 'datetime NOT NULL',
                'dateUpdated' => 'datetime NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
            $db->createCommand()->createIndex('pwt_translations_keys_key_unique', $keysTable, ['key'], true)->execute();
            $db->createCommand()->createIndex('pwt_translations_keys_group_idx', $keysTable, ['group'], false)->execute();
        }

        if (!$db->tableExists($groupsTable)) {
            $db->createCommand()->createTable($groupsTable, [
                'id' => 'pk',
                'name' => 'string NOT NULL',
                'dateCreated' => 'datetime NOT NULL',
                'dateUpdated' => 'datetime NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
            $db->createCommand()->createIndex('pwt_translations_groups_name_unique', $groupsTable, ['name'], true)->execute();
        }

        if (!$db->tableExists($valuesTable)) {
            $db->createCommand()->createTable($valuesTable, [
                'id' => 'pk',
                'translationId' => 'integer NOT NULL',
                'siteId' => 'integer NOT NULL',
                'value' => 'text',
                'dateCreated' => 'datetime NOT NULL',
                'dateUpdated' => 'datetime NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
            $db->createCommand()->createIndex('pwt_translations_values_unique', $valuesTable, ['translationId', 'siteId'], true)->execute();
            $db->createCommand()->addForeignKey(
                'pwt_translations_values_translation_fk',
                $valuesTable,
                ['translationId'],
                $keysTable,
                ['id'],
                'CASCADE',
                'CASCADE'
            )->execute();
            $db->createCommand()->addForeignKey(
                'pwt_translations_values_site_fk',
                $valuesTable,
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            )->execute();
        }

        if (!TranslationGroupRecord::find()->where(['name' => 'site'])->exists()) {
            $now = Db::prepareDateForDb(new \DateTime());
            $db->createCommand()->insert($groupsTable, [
                'name' => 'site',
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }
}
