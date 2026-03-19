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
            'strategyAudience' => trim((string)($row['strategyAudience'] ?? '')),
            'strategyBusinessGoals' => trim((string)($row['strategyBusinessGoals'] ?? '')),
            'strategyTone' => trim((string)($row['strategyTone'] ?? '')),
            'strategyBrandTerms' => trim((string)($row['strategyBrandTerms'] ?? '')),
            'strategyForbiddenTerms' => trim((string)($row['strategyForbiddenTerms'] ?? '')),
            'strategyPrimaryKeywords' => trim((string)($row['strategyPrimaryKeywords'] ?? '')),
            'strategySecondaryKeywords' => trim((string)($row['strategySecondaryKeywords'] ?? '')),
            'strategyCtaStyle' => trim((string)($row['strategyCtaStyle'] ?? '')),
            'strategyNotes' => trim((string)($row['strategyNotes'] ?? '')),
            'maxImageCandidates' => max(1, (int)($row['maxImageCandidates'] ?? 12)),
            'maxSourceTextChars' => max(500, (int)($row['maxSourceTextChars'] ?? 6000)),
        ];
    }

    public function saveSiteSettings(int $siteId, array $input): void
    {
        $this->ensureTable();
        $current = $this->getSiteSettings($siteId);
        $data = [
            'siteId' => $siteId,
            'ogType' => $this->sanitizeOgType($this->pick($input, 'ogType', $current['ogType'])),
            'robots' => $this->sanitizeRobots($this->pick($input, 'robots', $current['robots'])),
            'googleSiteVerification' => trim((string)$this->pick($input, 'googleSiteVerification', $current['googleSiteVerification'])),
            'twitterSite' => trim((string)$this->pick($input, 'twitterSite', $current['twitterSite'])),
            'twitterCreator' => trim((string)$this->pick($input, 'twitterCreator', $current['twitterCreator'])),
            'siteNameOverride' => trim((string)$this->pick($input, 'siteNameOverride', $current['siteNameOverride'])),
            'defaultSiteDescription' => trim((string)$this->pick($input, 'defaultSiteDescription', $current['defaultSiteDescription'])),
            'defaultSiteImageId' => $this->normalizeElementId($this->pick($input, 'defaultSiteImageId', $current['defaultSiteImageId'])),
            'defaultSiteImageDescription' => trim((string)$this->pick($input, 'defaultSiteImageDescription', $current['defaultSiteImageDescription'])),
            'titleSiteName' => trim((string)$this->pick($input, 'titleSiteName', $current['titleSiteName'])),
            'titleSiteNamePosition' => $this->sanitizeTitleSiteNamePosition($this->pick($input, 'titleSiteNamePosition', $current['titleSiteNamePosition'])),
            'titleSeparator' => $this->sanitizeTitleSeparator($this->pick($input, 'titleSeparator', $current['titleSeparator'])),
            'enableHreflang' => $this->pickBool($input, 'enableHreflang', (bool)$current['enableHreflang']) ? 1 : 0,
            'xDefaultSiteId' => !empty($this->pick($input, 'xDefaultSiteId', $current['xDefaultSiteId'])) ? (int)$this->pick($input, 'xDefaultSiteId', $current['xDefaultSiteId']) : null,
            'schemaMode' => $this->sanitizeSchemaMode($this->pick($input, 'schemaMode', $current['schemaMode'])),
            'enableArticleMeta' => $this->pickBool($input, 'enableArticleMeta', (bool)$current['enableArticleMeta']) ? 1 : 0,
            'includeImageMeta' => $this->pickBool($input, 'includeImageMeta', (bool)$current['includeImageMeta']) ? 1 : 0,
            'strategyAudience' => trim((string)$this->pick($input, 'strategyAudience', $current['strategyAudience'])),
            'strategyBusinessGoals' => trim((string)$this->pick($input, 'strategyBusinessGoals', $current['strategyBusinessGoals'])),
            'strategyTone' => trim((string)$this->pick($input, 'strategyTone', $current['strategyTone'])),
            'strategyBrandTerms' => trim((string)$this->pick($input, 'strategyBrandTerms', $current['strategyBrandTerms'])),
            'strategyForbiddenTerms' => trim((string)$this->pick($input, 'strategyForbiddenTerms', $current['strategyForbiddenTerms'])),
            'strategyPrimaryKeywords' => trim((string)$this->pick($input, 'strategyPrimaryKeywords', $current['strategyPrimaryKeywords'])),
            'strategySecondaryKeywords' => trim((string)$this->pick($input, 'strategySecondaryKeywords', $current['strategySecondaryKeywords'])),
            'strategyCtaStyle' => trim((string)$this->pick($input, 'strategyCtaStyle', $current['strategyCtaStyle'])),
            'strategyNotes' => trim((string)$this->pick($input, 'strategyNotes', $current['strategyNotes'])),
            'maxImageCandidates' => max(1, (int)$this->pick($input, 'maxImageCandidates', $current['maxImageCandidates'])),
            'maxSourceTextChars' => max(500, (int)$this->pick($input, 'maxSourceTextChars', $current['maxSourceTextChars'])),
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
            'strategyAudience' => '',
            'strategyBusinessGoals' => '',
            'strategyTone' => '',
            'strategyBrandTerms' => '',
            'strategyForbiddenTerms' => '',
            'strategyPrimaryKeywords' => '',
            'strategySecondaryKeywords' => '',
            'strategyCtaStyle' => '',
            'strategyNotes' => '',
            'maxImageCandidates' => 12,
            'maxSourceTextChars' => 6000,
        ];
    }

    private function pick(array $input, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $input) ? $input[$key] : $default;
    }

    private function pickBool(array $input, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        return !empty($input[$key]);
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
                'strategyAudience' => Schema::TYPE_TEXT,
                'strategyBusinessGoals' => Schema::TYPE_TEXT,
                'strategyTone' => Schema::TYPE_TEXT,
                'strategyBrandTerms' => Schema::TYPE_TEXT,
                'strategyForbiddenTerms' => Schema::TYPE_TEXT,
                'strategyPrimaryKeywords' => Schema::TYPE_TEXT,
                'strategySecondaryKeywords' => Schema::TYPE_TEXT,
                'strategyCtaStyle' => Schema::TYPE_TEXT,
                'strategyNotes' => Schema::TYPE_TEXT,
                'maxImageCandidates' => Schema::TYPE_INTEGER . ' NOT NULL DEFAULT 12',
                'maxSourceTextChars' => Schema::TYPE_INTEGER . ' NOT NULL DEFAULT 6000',
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
        $extraColumns = [
            'strategyAudience' => Schema::TYPE_TEXT,
            'strategyBusinessGoals' => Schema::TYPE_TEXT,
            'strategyTone' => Schema::TYPE_TEXT,
            'strategyBrandTerms' => Schema::TYPE_TEXT,
            'strategyForbiddenTerms' => Schema::TYPE_TEXT,
            'strategyPrimaryKeywords' => Schema::TYPE_TEXT,
            'strategySecondaryKeywords' => Schema::TYPE_TEXT,
            'strategyCtaStyle' => Schema::TYPE_TEXT,
            'strategyNotes' => Schema::TYPE_TEXT,
            'maxImageCandidates' => Schema::TYPE_INTEGER . ' NOT NULL DEFAULT 12',
            'maxSourceTextChars' => Schema::TYPE_INTEGER . ' NOT NULL DEFAULT 6000',
        ];

        $columns = Craft::$app->getDb()->getTableSchema(self::TABLE, true)?->columns ?? [];
        foreach ($extraColumns as $columnName => $definition) {
            if (isset($columns[$columnName])) {
                continue;
            }

            try {
                $db->createCommand()->addColumn(self::TABLE, $columnName, $definition)->execute();
            } catch (\Throwable) {
                // Ignore if column already exists or cannot be added in this environment.
            }
        }
    }
}
