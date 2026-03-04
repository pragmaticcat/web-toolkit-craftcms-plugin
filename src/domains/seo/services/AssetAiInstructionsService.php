<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\db\Query;
use yii\db\Schema;

class AssetAiInstructionsService
{
    private const TABLE = '{{%pragmatic_toolkit_seo_asset_ai_instructions}}';
    private static bool $tableReady = false;

    public function getInstructions(int $assetId, int $siteId): string
    {
        $this->ensureTable();

        $value = (new Query())
            ->select(['instructions'])
            ->from(self::TABLE)
            ->where([
                'assetId' => $assetId,
                'siteId' => $siteId,
            ])
            ->scalar();

        return trim((string)($value ?? ''));
    }

    /**
     * @param int[] $assetIds
     * @return array<int, string>
     */
    public function getInstructionsForAssets(array $assetIds, int $siteId): array
    {
        $this->ensureTable();
        $assetIds = array_values(array_filter(array_map('intval', $assetIds), static fn(int $id): bool => $id > 0));
        if (empty($assetIds)) {
            return [];
        }

        $rows = (new Query())
            ->select(['assetId', 'instructions'])
            ->from(self::TABLE)
            ->where([
                'siteId' => $siteId,
                'assetId' => $assetIds,
            ])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['assetId']] = trim((string)($row['instructions'] ?? ''));
        }

        return $result;
    }

    public function saveInstructions(int $assetId, int $siteId, string $instructions): void
    {
        $this->ensureTable();
        $instructions = trim($instructions);
        $db = Craft::$app->getDb();

        if ($instructions === '') {
            $db->createCommand()
                ->delete(self::TABLE, [
                    'assetId' => $assetId,
                    'siteId' => $siteId,
                ])
                ->execute();
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $db->createCommand()->upsert(self::TABLE, [
            'assetId' => $assetId,
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
                'assetId' => Schema::TYPE_INTEGER . ' NOT NULL',
                'siteId' => Schema::TYPE_INTEGER . ' NOT NULL',
                'instructions' => Schema::TYPE_TEXT,
                'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
        }

        try {
            $db->createCommand()->createIndex(
                'pwt_seo_asset_ai_instructions_unique',
                self::TABLE,
                ['assetId', 'siteId'],
                true
            )->execute();
        } catch (\Throwable) {
            // Ignore if the index already exists.
        }
    }
}
