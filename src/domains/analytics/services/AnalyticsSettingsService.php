<?php

namespace pragmatic\webtoolkit\domains\analytics\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\analytics\models\AnalyticsSettingsModel;

class AnalyticsSettingsService
{
    public function get(): AnalyticsSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new AnalyticsSettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('analytics', (array)($pluginSettings->analytics ?? []));
        $model->setAttributes($stored, false);

        return $model;
    }

    public function saveFromArray(array $input): bool
    {
        $model = $this->get();
        $model->setAttributes($input, false);

        if (!$model->validate()) {
            return false;
        }

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('analytics', $model->toArray());
    }
}
