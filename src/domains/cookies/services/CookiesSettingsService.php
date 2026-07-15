<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\cookies\models\CookieSettingsModel;
use yii\db\Query;

class CookiesSettingsService
{
    public function get(): CookieSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new CookieSettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('cookies', (array)($pluginSettings->cookies ?? []));
        $stored = $this->mergeLegacyGeneralSettings($stored);
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

    private function mergeLegacyGeneralSettings(array $stored): array
    {
        $generalFields = [
            'popupTitle',
            'popupDescription',
            'acceptAllLabel',
            'rejectAllLabel',
            'savePreferencesLabel',
            'cookiePolicyUrl',
        ];

        $missingFields = array_filter($generalFields, static function (string $field) use ($stored): bool {
            return !array_key_exists($field, $stored);
        });

        if ($missingFields === []) {
            return $stored;
        }

        $legacyRow = (new Query())
            ->from('{{%pragmatic_toolkit_cookies_site_settings}}')
            ->orderBy(['siteId' => SORT_ASC, 'id' => SORT_ASC])
            ->one();

        if (!$legacyRow) {
            return $stored;
        }

        foreach ($missingFields as $field) {
            if (array_key_exists($field, $legacyRow)) {
                $stored[$field] = $legacyRow[$field];
            }
        }

        return $stored;
    }
}
