<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\db\Query;
use yii\db\Schema;

class MetaSettingsService
{
    private const TABLE = '{{%pragmatic_toolkit_seo_meta_site_settings}}';
    private static bool $tableReady = false;

    public function getSiteSettings(int $siteId): array
    {
        $this->ensureTable();
        $defaults = $this->defaults();

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->one();

        if (!$row) {
            return $defaults;
        }

        return [
            'ogType' => $this->sanitizeOgType($row['ogType'] ?? null),
            'robots' => $this->sanitizeRobots($row['robots'] ?? null),
            'googleSiteVerification' => trim((string)($row['googleSiteVerification'] ?? '')),
            'twitterSite' => trim((string)($row['twitterSite'] ?? '')),
            'twitterCreator' => trim((string)($row['twitterCreator'] ?? '')),
            'siteNameOverride' => trim((string)($row['siteNameOverride'] ?? '')),
            'defaultSiteDescription' => trim((string)($row['defaultSiteDescription'] ?? '')),
            'defaultSiteImageId' => !empty($row['defaultSiteImageId']) ? (int)$row['defaultSiteImageId'] : null,
            'defaultSiteImageDescription' => trim((string)($row['defaultSiteImageDescription'] ?? '')),
            'titleSiteName' => trim((string)($row['titleSiteName'] ?? '')),
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePosition($row['titleSiteNamePosition'] ?? null),
            'titleSeparator' => $this->sanitizeTitleSeparator($row['titleSeparator'] ?? null),
            'enableHreflang' => (bool)($row['enableHreflang'] ?? true),
            'xDefaultSiteId' => !empty($row['xDefaultSiteId']) ? (int)$row['xDefaultSiteId'] : null,
            'schemaMode' => $this->sanitizeSchemaMode($row['schemaMode'] ?? null),
            'enableArticleMeta' => (bool)($row['enableArticleMeta'] ?? true),
            'includeImageMeta' => (bool)($row['includeImageMeta'] ?? true),
        ];
    }

    public function saveSiteSettings(int $siteId, array $input): void
    {
        $this->ensureTable();
        $data = [
            'siteId' => $siteId,
            'ogType' => $this->sanitizeOgType($input['ogType'] ?? null),
            'robots' => $this->sanitizeRobots($input['robots'] ?? null),
            'googleSiteVerification' => trim((string)($input['googleSiteVerification'] ?? '')),
            'twitterSite' => trim((string)($input['twitterSite'] ?? '')),
            'twitterCreator' => trim((string)($input['twitterCreator'] ?? '')),
            'siteNameOverride' => trim((string)($input['siteNameOverride'] ?? '')),
            'defaultSiteDescription' => trim((string)($input['defaultSiteDescription'] ?? '')),
            'defaultSiteImageId' => $this->normalizeElementId($input['defaultSiteImageId'] ?? null),
            'defaultSiteImageDescription' => trim((string)($input['defaultSiteImageDescription'] ?? '')),
            'titleSiteName' => trim((string)($input['titleSiteName'] ?? '')),
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePosition($input['titleSiteNamePosition'] ?? null),
            'titleSeparator' => $this->sanitizeTitleSeparator($input['titleSeparator'] ?? null),
            'enableHreflang' => !empty($input['enableHreflang']) ? 1 : 0,
            'xDefaultSiteId' => !empty($input['xDefaultSiteId']) ? (int)$input['xDefaultSiteId'] : null,
            'schemaMode' => $this->sanitizeSchemaMode($input['schemaMode'] ?? null),
            'enableArticleMeta' => !empty($input['enableArticleMeta']) ? 1 : 0,
            'includeImageMeta' => !empty($input['includeImageMeta']) ? 1 : 0,
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
    }

    private function defaults(): array
    {
        return [
            'ogType' => 'auto',
            'robots' => '',
            'googleSiteVerification' => '',
            'twitterSite' => '',
            'twitterCreator' => '',
            'siteNameOverride' => '',
            'defaultSiteDescription' => '',
            'defaultSiteImageId' => null,
            'defaultSiteImageDescription' => '',
            'titleSiteName' => '',
            'titleSiteNamePosition' => 'after',
            'titleSeparator' => '|',
            'enableHreflang' => true,
            'xDefaultSiteId' => null,
            'schemaMode' => 'auto',
            'enableArticleMeta' => true,
            'includeImageMeta' => true,
        ];
    }

    private function sanitizeOgType(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['auto', 'article', 'website'], true) ? $value : 'auto';
    }

    private function sanitizeSchemaMode(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['auto', 'none', 'webpage', 'article'], true) ? $value : 'auto';
    }

    private function sanitizeRobots(mixed $value): string
    {
        return trim((string)($value ?? ''));
    }

    private function sanitizeTitleSiteNamePosition(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['never', 'before', 'after'], true) ? $value : 'after';
    }

    private function sanitizeTitleSeparator(mixed $value): string
    {
        $separator = trim((string)($value ?? ''));
        if ($separator === '') {
            return '|';
        }

        return mb_substr($separator, 0, 12);
    }

    private function normalizeElementId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (int)$value;
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
                'ogType' => Schema::TYPE_STRING . "(16) NOT NULL DEFAULT 'auto'",
                'robots' => Schema::TYPE_STRING . '(128)',
                'googleSiteVerification' => Schema::TYPE_STRING . '(255)',
                'twitterSite' => Schema::TYPE_STRING . '(64)',
                'twitterCreator' => Schema::TYPE_STRING . '(64)',
                'siteNameOverride' => Schema::TYPE_STRING . '(255)',
                'defaultSiteDescription' => Schema::TYPE_TEXT,
                'defaultSiteImageId' => Schema::TYPE_INTEGER,
                'defaultSiteImageDescription' => Schema::TYPE_TEXT,
                'titleSiteName' => Schema::TYPE_STRING . '(255)',
                'titleSiteNamePosition' => Schema::TYPE_STRING . "(16) NOT NULL DEFAULT 'after'",
                'titleSeparator' => Schema::TYPE_STRING . "(16) NOT NULL DEFAULT '|'",
                'enableHreflang' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'xDefaultSiteId' => Schema::TYPE_INTEGER,
                'schemaMode' => Schema::TYPE_STRING . "(16) NOT NULL DEFAULT 'auto'",
                'enableArticleMeta' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'includeImageMeta' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
        }

        try {
            $db->createCommand()->createIndex(
                'pwt_seo_meta_site_unique',
                self::TABLE,
                ['siteId'],
                true
            )->execute();
        } catch (\Throwable) {
            // Ignore if index already exists.
        }

        $columns = Craft::$app->getDb()->getTableSchema(self::TABLE, true)?->columns ?? [];
        if (!isset($columns['titleSiteName'])) {
            try {
                $db->createCommand()->addColumn(self::TABLE, 'titleSiteName', Schema::TYPE_STRING . '(255)')->execute();
            } catch (\Throwable) {
                // Ignore if column already exists or cannot be added in this environment.
            }
        }
        if (!isset($columns['defaultSiteDescription'])) {
            try {
                $db->createCommand()->addColumn(self::TABLE, 'defaultSiteDescription', Schema::TYPE_TEXT)->execute();
            } catch (\Throwable) {
                // Ignore if column already exists or cannot be added in this environment.
            }
        }
        if (!isset($columns['defaultSiteImageId'])) {
            try {
                $db->createCommand()->addColumn(self::TABLE, 'defaultSiteImageId', Schema::TYPE_INTEGER)->execute();
            } catch (\Throwable) {
                // Ignore if column already exists or cannot be added in this environment.
            }
        }
        if (!isset($columns['defaultSiteImageDescription'])) {
            try {
                $db->createCommand()->addColumn(self::TABLE, 'defaultSiteImageDescription', Schema::TYPE_TEXT)->execute();
            } catch (\Throwable) {
                // Ignore if column already exists or cannot be added in this environment.
            }
        }
        if (!isset($columns['titleSiteNamePosition'])) {
            try {
                $db->createCommand()->addColumn(self::TABLE, 'titleSiteNamePosition', Schema::TYPE_STRING . "(16) NOT NULL DEFAULT 'after'")->execute();
            } catch (\Throwable) {
                // Ignore if column already exists or cannot be added in this environment.
            }
        }
        if (!isset($columns['titleSeparator'])) {
            try {
                $db->createCommand()->addColumn(self::TABLE, 'titleSeparator', Schema::TYPE_STRING . "(16) NOT NULL DEFAULT '|'")->execute();
            } catch (\Throwable) {
                // Ignore if column already exists or cannot be added in this environment.
            }
        }
    }
}
