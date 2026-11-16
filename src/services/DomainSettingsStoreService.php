<?php

namespace pragmatic\webtoolkit\services;

use pragmatic\webtoolkit\db\Query;
use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;

class DomainSettingsStoreService
{
    private const TABLE = 'pragmatic_toolkit_domain_settings';

    public function get(string $domainKey, array $fallback = []): array
    {
        if (!$this->tableExists()) {
            return $fallback;
        }

        $row = (new Query())
            ->select(['settingsJson'])
            ->from(self::TABLE)
            ->where(['domainKey' => $domainKey])
            ->one();

        if (!$row || !isset($row['settingsJson'])) {
            return $fallback;
        }

        try {
            $decoded = Json::decodeIfJson((string)$row['settingsJson']);
            return is_array($decoded) ? $decoded : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function save(string $domainKey, array $settings): bool
    {
        if (!$this->tableExists() && !$this->ensureTableExists()) {
            return false;
        }
        $now = DbHelper::prepareDateForDb(new \DateTime());

        try {
            Command::make()->upsert(
                self::TABLE,
                [
                    'domainKey' => $domainKey,
                    'settingsJson' => Json::encode($settings),
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => Str::uuid()->toString(),
                ],
                [
                    'settingsJson' => Json::encode($settings),
                    'dateUpdated' => $now,
                ]
            )->execute();

            return true;
        } catch (\Throwable $e) {
            Log::error('Could not save domain settings store: ' . $e->getMessage());
            return false;
        }
    }

    private function tableExists(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    private function ensureTableExists(): bool
    {
        return $this->tableExists();
    }
}
