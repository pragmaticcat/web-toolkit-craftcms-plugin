<?php

namespace pragmatic\webtoolkit\domains\translations\services;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\translations\models\TranslationsSettingsModel;

class TranslationsSettingsService
{
    public function get(): TranslationsSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new TranslationsSettingsModel();
        $model->setAttributes((array)($pluginSettings->translations ?? []), false);

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
        $pluginSettings->translations = $model->toArray();

        return Craft::$app->getPlugins()->savePluginSettings(PragmaticWebToolkit::$plugin, $pluginSettings->toArray());
    }
}
