<?php

namespace pragmatic\webtoolkit\domains\analytics\services;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\analytics\models\AnalyticsSettingsModel;

class AnalyticsSettingsService
{
    public function get(?int $siteId = null): AnalyticsSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new AnalyticsSettingsModel();
        $store = PragmaticWebToolkit::$plugin->domainSettingsStore;
        $legacySettings = $store->get('analytics', (array)($pluginSettings->analytics ?? []));
        $groupId = $this->resolveGroupId($siteId);
        $stored = $groupId !== null
            ? $store->get('analytics:group:' . $groupId, $legacySettings)
            : $legacySettings;
        $model->setAttributes($stored, false);

        return $model;
    }

    public function saveFromArray(array $input, ?int $siteId = null): bool
    {
        $groupId = $this->resolveGroupId($siteId);
        if ($groupId === null) {
            return false;
        }

        $model = $this->get($siteId);
        $model->setAttributes($input, false);

        if (!$model->validate()) {
            return false;
        }

        if ($model->gaConsentCategory !== '') {
            if (!PragmaticWebToolkit::$plugin->domains->isEnabled('cookies')) {
                return false;
            }
            $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($siteId);
            if (!in_array($model->gaConsentCategory, array_map(static fn($category): string => $category->handle, $categories), true)) {
                return false;
            }
        }

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('analytics:group:' . $groupId, $model->toArray());
    }

    private function resolveGroupId(?int $siteId): ?int
    {
        $sites = Craft::$app->getSites();
        $site = $siteId === null ? $sites->getCurrentSite() : $sites->getSiteById($siteId);
        $groupId = (int)($site?->groupId ?? 0);

        return $groupId > 0 ? $groupId : null;
    }
}
