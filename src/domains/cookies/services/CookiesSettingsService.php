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
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('cookies', (array)($pluginSettings->cookies ?? []));
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

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('cookies', $model->toArray());
    }
}
