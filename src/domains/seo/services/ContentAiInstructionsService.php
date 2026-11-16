<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\db\Query;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;

class ContentAiInstructionsService
{
    private const TABLE = 'pragmatic_toolkit_seo_content_ai_instructions';
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
        if ($instructions === '') {
            Command::make()
                ->delete(self::TABLE, [
                    'entryId' => $entryId,
                    'fieldHandle' => $fieldHandle,
                    'siteId' => $siteId,
                ])
                ->execute();
            return;
        }

        $now = DbHelper::prepareDateForDb(new \DateTime());
        Command::make()->upsert(self::TABLE, [
            'entryId' => $entryId,
            'fieldHandle' => $fieldHandle,
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

    private function buildKey(int $entryId, string $fieldHandle): string
    {
        return $entryId . ':' . trim($fieldHandle);
    }
}
