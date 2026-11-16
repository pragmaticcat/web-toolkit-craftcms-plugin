<?php

namespace pragmatic\webtoolkit\domains\favicon\services;

use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\domains\favicon\models\FaviconSettingsModel;
use pragmatic\webtoolkit\db\Query;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;
use Illuminate\Support\Facades\DB;

class FaviconSettingsService
{
    private const TABLE = 'pragmatic_toolkit_favicon_site_settings';
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

        $now = DbHelper::prepareDateForDb(new \DateTime());
        Command::make()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid()->toString(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    /**
     * Saves the same favicon configuration for every site in the selected site's group.
     */
    public function saveSiteGroupSettings(int $siteId, array $input): bool
    {
        $sitesService = app(\CraftCms\Cms\Site\Sites::class);
        $selectedSite = $sitesService->getSiteById($siteId);
        if ($selectedSite === null) {
            return false;
        }

        $groupSiteIds = [];
        foreach ($sitesService->getAllSites(true) as $site) {
            if ((int)$site->groupId === (int)$selectedSite->groupId) {
                $groupSiteIds[] = (int)$site->id;
            }
        }

        if ($groupSiteIds === []) {
            return false;
        }

        DB::beginTransaction();
        try {
            foreach ($groupSiteIds as $groupSiteId) {
                if (!$this->saveSiteSettings($groupSiteId, $input)) {
                    DB::rollBack();
                    return false;
                }
            }

            DB::commit();
            return true;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                DB::rollBack();
            }

            Log::error($exception->getMessage());
            return false;
        }
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
        // La taula la crea la migració Install.
    }
}
