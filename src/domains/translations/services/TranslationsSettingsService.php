<?php

namespace pragmatic\webtoolkit\domains\translations\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\translations\models\TranslationsSettingsModel;

class TranslationsSettingsService
{
    public function get(): TranslationsSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new TranslationsSettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('translations', (array)($pluginSettings->translations ?? []));
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

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('translations', $model->toArray());
    }
}
