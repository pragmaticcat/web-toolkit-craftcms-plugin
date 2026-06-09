<?php

namespace pragmatic\webtoolkit\domains\plus18\services;

use Craft;
use craft\elements\Asset;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\plus18\models\Plus18SettingsModel;

class Plus18SettingsService
{
    /** @var array<string, array<int, string>> */
    private array $lastErrors = [];

    public function get(): Plus18SettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new Plus18SettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('plus18', (array)($pluginSettings->plus18 ?? []));
        $model->setAttributes($stored, false);

        return $model;
    }

    public function saveFromArray(array $input): bool
    {
        $model = $this->get();
        $input = $this->normalizeInput($input);
        $model->setAttributes($input, false);
        $this->lastErrors = [];

        if (!$model->validate()) {
            $this->lastErrors = $model->getErrors();
            return false;
        }

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('plus18', $model->toArray());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getLastErrors(): array
    {
        return $this->lastErrors;
    }

    public function resolveLogoAsset(?int $siteId = null): ?Asset
    {
        $settings = $this->get();
        $assetId = (int)($settings->logoAssetId ?? 0);
        if ($assetId <= 0) {
            return null;
        }

        $targetSiteId = $siteId ?: (int)Craft::$app->getSites()->getCurrentSite()->id;
        $asset = Craft::$app->getElements()->getElementById($assetId, Asset::class, $targetSiteId);
        if (!$asset instanceof Asset) {
            $asset = Craft::$app->getElements()->getElementById($assetId, Asset::class);
        }

        return $asset instanceof Asset ? $asset : null;
    }

    public function resolveLogoUrl(?int $siteId = null): ?string
    {
        $asset = $this->resolveLogoAsset($siteId);
        if ($asset instanceof Asset) {
            try {
                $url = $asset->getUrl();
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (\Throwable) {
            }
        }

        $legacyUrl = trim((string)$this->get()->logoUrl);
        return $legacyUrl !== '' ? $legacyUrl : null;
    }

    private function normalizeInput(array $input): array
    {
        $logoAssetId = $input['logoAssetId'] ?? null;
        if (is_array($logoAssetId)) {
            $logoAssetId = $logoAssetId[0] ?? null;
        }

        $input['logoAssetId'] = ($logoAssetId !== null && $logoAssetId !== '' && (int)$logoAssetId > 0)
            ? (int)$logoAssetId
            : null;

        foreach (['logoUrl', 'primaryButtonColor', 'fontFamily'] as $key) {
            if (array_key_exists($key, $input)) {
                $value = trim((string)$input[$key]);
                $input[$key] = $value !== '' ? $value : null;
            }
        }

        return $input;
    }
}
