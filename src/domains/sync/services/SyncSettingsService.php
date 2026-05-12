<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\sync\models\SyncSettingsModel;

class SyncSettingsService
{
    public function get(): SyncSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new SyncSettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('sync', (array)($pluginSettings->sync ?? []));
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

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('sync', $model->toArray());
    }
}
