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
            $model = new SiteSettingsModel();
            $model->popupTitle = $defaults->popupTitle;
            $model->popupDescription = $defaults->popupDescription;
            $model->acceptAllLabel = $defaults->acceptAllLabel;
            $model->rejectAllLabel = $defaults->rejectAllLabel;
            $model->savePreferencesLabel = $defaults->savePreferencesLabel;
            $model->cookiePolicyUrl = $defaults->cookiePolicyUrl;
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
}
