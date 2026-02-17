<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\cookies\models\CookieSettingsModel;

class CookiesSettingsService
{
    public function get(): CookieSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new CookieSettingsModel();
        $model->setAttributes((array)($pluginSettings->cookies ?? []), false);

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
        $pluginSettings->cookies = $model->toArray();

        return \Craft::$app->getPlugins()->savePluginSettings(PragmaticWebToolkit::$plugin, $pluginSettings->toArray());
    }
}
