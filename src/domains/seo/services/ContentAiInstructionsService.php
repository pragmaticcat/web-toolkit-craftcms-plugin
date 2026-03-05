<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\db\Query;
use yii\db\Schema;

class ContentAiInstructionsService
{
    private const TABLE = '{{%pragmatic_toolkit_seo_content_ai_instructions}}';
    private static bool $tableReady = false;

    public function getInstructions(int $entryId, string $fieldHandle, int $siteId): string
    {
        $this->ensureTable();

        $value = (new Query())
            ->select(['instructions'])
            ->from(self::TABLE)
            ->where([
                'entryId' => $entryId,
                'fieldHandle' => $fieldHandle,
                'siteId' => $siteId,
            ])
            ->scalar();

        return trim((string)($value ?? ''));
    }

    /**
     * @param array<int,array{entryId:int,fieldHandle:string}> $rows
     * @return array<string,string>
     */
    public function getInstructionsForRows(array $rows, int $siteId): array
    {
        $this->ensureTable();
        if (empty($rows)) {
            return [];
        }

        $or = ['or'];
        foreach ($rows as $row) {
            $entryId = (int)($row['entryId'] ?? 0);
            $fieldHandle = trim((string)($row['fieldHandle'] ?? ''));
            if ($entryId <= 0 || $fieldHandle === '') {
                continue;
            }
            $or[] = ['and', ['entryId' => $entryId], ['fieldHandle' => $fieldHandle]];
        }

        if (count($or) === 1) {
            return [];
        }

        $rows = (new Query())
            ->select(['entryId', 'fieldHandle', 'instructions'])
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->andWhere($or)
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $key = $this->buildKey((int)$row['entryId'], (string)$row['fieldHandle']);
            $result[$key] = trim((string)($row['instructions'] ?? ''));
        }

        return $result;
    }

    public function saveInstructions(int $entryId, string $fieldHandle, int $siteId, string $instructions): void
    {
        $this->ensureTable();
        $fieldHandle = trim($fieldHandle);
        $instructions = trim($instructions);
        if ($entryId <= 0 || $fieldHandle === '') {
            return;
        }

        $db = Craft::$app->getDb();
        if ($instructions === '') {
            $db->createCommand()
                ->delete(self::TABLE, [
                    'entryId' => $entryId,
                    'fieldHandle' => $fieldHandle,
                    'siteId' => $siteId,
                ])
                ->execute();
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $db->createCommand()->upsert(self::TABLE, [
            'entryId' => $entryId,
            'fieldHandle' => $fieldHandle,
            'siteId' => $siteId,
            'instructions' => $instructions,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            'instructions' => $instructions,
            'dateUpdated' => $now,
        ])->execute();
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        self::$tableReady = true;

        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::TABLE)) {
            $db->createCommand()->createTable(self::TABLE, [
                'id' => Schema::TYPE_PK,
                'entryId' => Schema::TYPE_INTEGER . ' NOT NULL',
                'fieldHandle' => Schema::TYPE_STRING . '(255) NOT NULL',
                'siteId' => Schema::TYPE_INTEGER . ' NOT NULL',
                'instructions' => Schema::TYPE_TEXT,
                'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
        }

        try {
            $db->createCommand()->createIndex(
                'pwt_seo_content_ai_instructions_unique',
                self::TABLE,
                ['entryId', 'fieldHandle', 'siteId'],
                true
            )->execute();
        } catch (\Throwable) {
            // Ignore if exists.
        }
    }

    private function buildKey(int $entryId, string $fieldHandle): string
    {
        return $entryId . ':' . trim($fieldHandle);
    }
}
