<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\db\Query;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;

class AssetAiInstructionsService
{
    private const TABLE = 'pragmatic_toolkit_seo_asset_ai_instructions';
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

        if ($instructions === '') {
            Command::make()
                ->delete(self::TABLE, [
                    'assetId' => $assetId,
                    'siteId' => $siteId,
                ])
                ->execute();
            return;
        }

        $now = DbHelper::prepareDateForDb(new \DateTime());
        Command::make()->upsert(self::TABLE, [
            'assetId' => $assetId,
            'siteId' => $siteId,
            'instructions' => $instructions,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid()->toString(),
        ], [
            'instructions' => $instructions,
            'dateUpdated' => $now,
        ])->execute();
    }

    private function ensureTable(): void
    {
        // L’esquema el crea la migració Install.
    }
}
