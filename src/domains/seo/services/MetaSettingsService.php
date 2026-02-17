<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\db\Query;

class MetaSettingsService
{
    private const TABLE = '{{%pragmatic_toolkit_seo_meta_site_settings}}';

    public function getSiteSettings(int $siteId): array
    {
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
            'enableHreflang' => (bool)($row['enableHreflang'] ?? true),
            'xDefaultSiteId' => !empty($row['xDefaultSiteId']) ? (int)$row['xDefaultSiteId'] : null,
            'schemaMode' => $this->sanitizeSchemaMode($row['schemaMode'] ?? null),
            'enableArticleMeta' => (bool)($row['enableArticleMeta'] ?? true),
            'includeImageMeta' => (bool)($row['includeImageMeta'] ?? true),
        ];
    }

    public function saveSiteSettings(int $siteId, array $input): void
    {
        $data = [
            'siteId' => $siteId,
            'ogType' => $this->sanitizeOgType($input['ogType'] ?? null),
            'robots' => $this->sanitizeRobots($input['robots'] ?? null),
            'googleSiteVerification' => trim((string)($input['googleSiteVerification'] ?? '')),
            'twitterSite' => trim((string)($input['twitterSite'] ?? '')),
            'twitterCreator' => trim((string)($input['twitterCreator'] ?? '')),
            'siteNameOverride' => trim((string)($input['siteNameOverride'] ?? '')),
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
}
