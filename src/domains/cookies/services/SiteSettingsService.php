<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\domains\cookies\models\SiteSettingsModel;
use yii\db\Query;

class SiteSettingsService
{
    private const TABLE = '{{%pragmatic_toolkit_cookies_site_settings}}';

    public function getSiteSettings(int $siteId): SiteSettingsModel
    {
        $defaults = (new CookiesSettingsService())->get();

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->one();

        if (!$row) {
            $localized = $this->buildLocalizedDefaults($siteId, $defaults);
            $model = new SiteSettingsModel();
            $model->popupTitle = $localized['popupTitle'];
            $model->popupDescription = $localized['popupDescription'];
            $model->acceptAllLabel = $localized['acceptAllLabel'];
            $model->rejectAllLabel = $localized['rejectAllLabel'];
            $model->savePreferencesLabel = $localized['savePreferencesLabel'];
            $model->cookiePolicyUrl = $localized['cookiePolicyUrl'];

            $now = Db::prepareDateForDb(new \DateTime());
            Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
                'siteId' => $siteId,
                'popupTitle' => $model->popupTitle,
                'popupDescription' => $model->popupDescription,
                'acceptAllLabel' => $model->acceptAllLabel,
                'rejectAllLabel' => $model->rejectAllLabel,
                'savePreferencesLabel' => $model->savePreferencesLabel,
                'cookiePolicyUrl' => $model->cookiePolicyUrl,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                'popupTitle' => $model->popupTitle,
                'popupDescription' => $model->popupDescription,
                'acceptAllLabel' => $model->acceptAllLabel,
                'rejectAllLabel' => $model->rejectAllLabel,
                'savePreferencesLabel' => $model->savePreferencesLabel,
                'cookiePolicyUrl' => $model->cookiePolicyUrl,
                'dateUpdated' => $now,
            ])->execute();

            return $model;
        }

        $model = new SiteSettingsModel();
        $model->popupTitle = trim((string)($row['popupTitle'] ?? $defaults->popupTitle));
        $model->popupDescription = trim((string)($row['popupDescription'] ?? $defaults->popupDescription));
        $model->acceptAllLabel = trim((string)($row['acceptAllLabel'] ?? $defaults->acceptAllLabel));
        $model->rejectAllLabel = trim((string)($row['rejectAllLabel'] ?? $defaults->rejectAllLabel));
        $model->savePreferencesLabel = trim((string)($row['savePreferencesLabel'] ?? $defaults->savePreferencesLabel));
        $model->cookiePolicyUrl = trim((string)($row['cookiePolicyUrl'] ?? $defaults->cookiePolicyUrl));

        return $model;
    }

    public function saveSiteSettings(int $siteId, array $input): bool
    {
        $current = $this->getSiteSettings($siteId);

        $model = new SiteSettingsModel();
        $model->popupTitle = trim((string)($input['popupTitle'] ?? $current->popupTitle));
        $model->popupDescription = trim((string)($input['popupDescription'] ?? $current->popupDescription));
        $model->acceptAllLabel = trim((string)($input['acceptAllLabel'] ?? $current->acceptAllLabel));
        $model->rejectAllLabel = trim((string)($input['rejectAllLabel'] ?? $current->rejectAllLabel));
        $model->savePreferencesLabel = trim((string)($input['savePreferencesLabel'] ?? $current->savePreferencesLabel));
        $model->cookiePolicyUrl = trim((string)($input['cookiePolicyUrl'] ?? $current->cookiePolicyUrl));

        if (!$model->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $data = [
            'siteId' => $siteId,
            'popupTitle' => $model->popupTitle,
            'popupDescription' => $model->popupDescription,
            'acceptAllLabel' => $model->acceptAllLabel,
            'rejectAllLabel' => $model->rejectAllLabel,
            'savePreferencesLabel' => $model->savePreferencesLabel,
            'cookiePolicyUrl' => $model->cookiePolicyUrl,
        ];

        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    private function buildLocalizedDefaults(int $siteId, $defaults): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $language = $site ? $site->language : Craft::$app->getSites()->getCurrentSite()->language;

        return [
            'popupTitle' => Craft::t('pragmatic-web-toolkit', 'defaults.cookies.popup-title', [], $language) ?: $defaults->popupTitle,
            'popupDescription' => Craft::t('pragmatic-web-toolkit', 'defaults.cookies.popup-description', [], $language) ?: $defaults->popupDescription,
            'acceptAllLabel' => Craft::t('pragmatic-web-toolkit', 'defaults.cookies.accept-all-label', [], $language) ?: $defaults->acceptAllLabel,
            'rejectAllLabel' => Craft::t('pragmatic-web-toolkit', 'defaults.cookies.reject-all-label', [], $language) ?: $defaults->rejectAllLabel,
            'savePreferencesLabel' => Craft::t('pragmatic-web-toolkit', 'defaults.cookies.save-preferences-label', [], $language) ?: $defaults->savePreferencesLabel,
            'cookiePolicyUrl' => $defaults->cookiePolicyUrl,
        ];
    }
}
