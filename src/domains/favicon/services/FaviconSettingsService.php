<?php

namespace pragmatic\webtoolkit\domains\favicon\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\domains\favicon\models\FaviconSettingsModel;
use yii\db\Query;
use yii\db\Schema;

class FaviconSettingsService
{
    private const TABLE = '{{%pragmatic_toolkit_favicon_site_settings}}';
    private static bool $tableReady = false;

    public function getSiteSettings(int $siteId): FaviconSettingsModel
    {
        $this->ensureTable();

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->one();

        $model = new FaviconSettingsModel();
        if (!$row) {
            return $model;
        }

        $model->setAttributes([
            'enabled' => (bool)($row['enabled'] ?? true),
            'autoGenerateManifest' => (bool)($row['autoGenerateManifest'] ?? true),
            'faviconIcoAssetId' => $this->normalizeId($row['faviconIcoAssetId'] ?? null),
            'faviconSvgAssetId' => $this->normalizeId($row['faviconSvgAssetId'] ?? null),
            'appleTouchIconAssetId' => $this->normalizeId($row['appleTouchIconAssetId'] ?? null),
            'maskIconAssetId' => $this->normalizeId($row['maskIconAssetId'] ?? null),
            'maskIconColor' => $this->normalizeColor($row['maskIconColor'] ?? '#000000', '#000000'),
            'manifestAssetId' => $this->normalizeId($row['manifestAssetId'] ?? null),
            'themeColor' => $this->normalizeColor($row['themeColor'] ?? '#ffffff', '#ffffff'),
            'msTileColor' => $this->normalizeColor($row['msTileColor'] ?? '#ffffff', '#ffffff'),
        ], false);

        return $model;
    }

    public function saveSiteSettings(int $siteId, array $input): bool
    {
        $this->ensureTable();

        $model = $this->getSiteSettings($siteId);
        $model->setAttributes([
            'enabled' => !empty($input['enabled']),
            'autoGenerateManifest' => !empty($input['autoGenerateManifest']),
            'faviconIcoAssetId' => $this->normalizeId($input['faviconIcoAssetId'] ?? null),
            'faviconSvgAssetId' => $this->normalizeId($input['faviconSvgAssetId'] ?? null),
            'appleTouchIconAssetId' => $this->normalizeId($input['appleTouchIconAssetId'] ?? null),
            'maskIconAssetId' => $this->normalizeId($input['maskIconAssetId'] ?? null),
            'maskIconColor' => $this->normalizeColor($input['maskIconColor'] ?? '#000000', '#000000'),
            'manifestAssetId' => $this->normalizeId($input['manifestAssetId'] ?? null),
            'themeColor' => $this->normalizeColor($input['themeColor'] ?? '#ffffff', '#ffffff'),
            'msTileColor' => $this->normalizeColor($input['msTileColor'] ?? '#ffffff', '#ffffff'),
        ], false);

        if (!$model->validate()) {
            return false;
        }

        $data = [
            'siteId' => $siteId,
            'enabled' => $model->enabled ? 1 : 0,
            'autoGenerateManifest' => $model->autoGenerateManifest ? 1 : 0,
            'faviconIcoAssetId' => $model->faviconIcoAssetId,
            'faviconSvgAssetId' => $model->faviconSvgAssetId,
            'appleTouchIconAssetId' => $model->appleTouchIconAssetId,
            'maskIconAssetId' => $model->maskIconAssetId,
            'maskIconColor' => $model->maskIconColor,
            'manifestAssetId' => $model->manifestAssetId,
            'themeColor' => $model->themeColor,
            'msTileColor' => $model->msTileColor,
        ];

        $now = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    private function normalizeId(mixed $value): ?int
    {
        $id = $this->extractPositiveInt($value);
        return $id > 0 ? $id : null;
    }

    private function extractPositiveInt(mixed $value): int
    {
        if (is_array($value)) {
            foreach ($value as $candidate) {
                $id = $this->extractPositiveInt($candidate);
                if ($id > 0) {
                    return $id;
                }
            }
            return 0;
        }

        if ($value === null || $value === '' || $value === false) {
            return 0;
        }

        return max(0, (int)$value);
    }

    private function normalizeColor(mixed $value, string $default): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return $default;
        }

        return preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $raw) ? strtolower($raw) : $default;
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
                'siteId' => Schema::TYPE_INTEGER . ' NOT NULL',
                'enabled' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'autoGenerateManifest' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'faviconIcoAssetId' => Schema::TYPE_INTEGER,
                'faviconSvgAssetId' => Schema::TYPE_INTEGER,
                'appleTouchIconAssetId' => Schema::TYPE_INTEGER,
                'maskIconAssetId' => Schema::TYPE_INTEGER,
                'maskIconColor' => Schema::TYPE_STRING . "(32) NOT NULL DEFAULT '#000000'",
                'manifestAssetId' => Schema::TYPE_INTEGER,
                'themeColor' => Schema::TYPE_STRING . "(32) NOT NULL DEFAULT '#ffffff'",
                'msTileColor' => Schema::TYPE_STRING . "(32) NOT NULL DEFAULT '#ffffff'",
                'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
        }

        try {
            $db->createCommand()->createIndex('pwt_favicon_site_unique', self::TABLE, ['siteId'], true)->execute();
        } catch (\Throwable) {
            // Ignore if index already exists.
        }

        try {
            $db->createCommand()->addForeignKey(
                'pwt_favicon_site_settings_site_fk',
                self::TABLE,
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            )->execute();
        } catch (\Throwable) {
            // Ignore if foreign key already exists.
        }

        $schema = $db->getTableSchema(self::TABLE, true);
        if ($schema !== null && !isset($schema->columns['autoGenerateManifest'])) {
            try {
                $db->createCommand()->addColumn(
                    self::TABLE,
                    'autoGenerateManifest',
                    Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1'
                )->execute();
            } catch (\Throwable) {
                // Ignore if added concurrently.
            }
        }
    }
}
