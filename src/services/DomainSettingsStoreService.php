<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

class DomainSettingsStoreService extends Component
{
    private const TABLE = '{{%pragmatic_toolkit_domain_settings}}';

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

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        try {
            $db->createCommand()->upsert(
                self::TABLE,
                [
                    'domainKey' => $domainKey,
                    'settingsJson' => Json::encode($settings),
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ],
                [
                    'settingsJson' => Json::encode($settings),
                    'dateUpdated' => $now,
                ]
            )->execute();

            return true;
        } catch (\Throwable $e) {
            Craft::error('Could not save domain settings store: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    private function tableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }

    private function ensureTableExists(): bool
    {
        if ($this->tableExists()) {
            return true;
        }

        $db = Craft::$app->getDb();
        try {
            $db->createCommand()->createTable(self::TABLE, [
                'id' => 'pk',
                'domainKey' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING)->notNull(),
                'settingsJson' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TEXT)->notNull(),
                'dateCreated' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_DATETIME)->notNull(),
                'dateUpdated' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_DATETIME)->notNull(),
                'uid' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_CHAR, 36),
            ])->execute();

            $db->createCommand()->createIndex(
                'pwt_domain_settings_domain_key_unique',
                self::TABLE,
                ['domainKey'],
                true
            )->execute();

            return true;
        } catch (\Throwable $e) {
            Craft::error('Could not auto-create domain settings table: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
