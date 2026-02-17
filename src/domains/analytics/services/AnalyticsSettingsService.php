<?php

namespace pragmatic\webtoolkit\domains\analytics\services;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\analytics\models\AnalyticsSettingsModel;

class AnalyticsSettingsService
{
    public function get(): AnalyticsSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new AnalyticsSettingsModel();
        $model->setAttributes((array)($pluginSettings->analytics ?? []), false);

        return $model;
    }

    public function saveFromArray(array $input): bool
    {
        $model = $this->get();
        $model->setAttributes($input, false);

        if (!$model->validate()) {
            return false;
        }

        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $pluginSettings->analytics = $model->toArray();

        return Craft::$app->getPlugins()->savePluginSettings(PragmaticWebToolkit::$plugin, $pluginSettings->toArray());
    }
}
