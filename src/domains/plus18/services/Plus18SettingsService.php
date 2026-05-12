<?php

namespace pragmatic\webtoolkit\domains\plus18\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\plus18\models\Plus18SettingsModel;

class Plus18SettingsService
{
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
        $model->setAttributes($input, false);

        if (!$model->validate()) {
            return false;
        }

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('plus18', $model->toArray());
    }
}
