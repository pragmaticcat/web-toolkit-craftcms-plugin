<?php

namespace pragmatic\webtoolkit\domains\plus18\services;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\plus18\models\Plus18SettingsModel;

class Plus18SettingsService
{
    public function get(): Plus18SettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new Plus18SettingsModel();
        $model->setAttributes((array)($pluginSettings->plus18 ?? []), false);

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
        $pluginSettings->plus18 = $model->toArray();

        return Craft::$app->getPlugins()->savePluginSettings(PragmaticWebToolkit::$plugin, $pluginSettings->toArray());
    }
}
