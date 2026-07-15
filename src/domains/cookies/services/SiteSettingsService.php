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
        $this->ensureColumns();

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
            $model->popupLayout = $localized['popupLayout'];
            $model->popupPosition = $localized['popupPosition'];
            $model->primaryColor = $localized['primaryColor'];
            $model->backgroundColor = $localized['backgroundColor'];
            $model->textColor = $localized['textColor'];
            $model->overlayEnabled = $localized['overlayEnabled'];
            $model->autoShowPopup = $localized['autoShowPopup'];
            $model->consentExpiry = $localized['consentExpiry'];
            $model->logConsent = $localized['logConsent'];
            $model->showPreferencesButton = $localized['showPreferencesButton'];
            $model->preferencesButtonLabel = $localized['preferencesButtonLabel'];

            $now = Db::prepareDateForDb(new \DateTime());
            Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
                'siteId' => $siteId,
                'popupTitle' => $model->popupTitle,
                'popupDescription' => $model->popupDescription,
                'acceptAllLabel' => $model->acceptAllLabel,
                'rejectAllLabel' => $model->rejectAllLabel,
                'savePreferencesLabel' => $model->savePreferencesLabel,
                'cookiePolicyUrl' => $model->cookiePolicyUrl,
                'popupLayout' => $model->popupLayout,
                'popupPosition' => $model->popupPosition,
                'primaryColor' => $model->primaryColor,
                'backgroundColor' => $model->backgroundColor,
                'textColor' => $model->textColor,
                'overlayEnabled' => $model->overlayEnabled,
                'autoShowPopup' => $model->autoShowPopup,
                'consentExpiry' => $model->consentExpiry,
                'logConsent' => $model->logConsent,
                'showPreferencesButton' => $model->showPreferencesButton,
                'preferencesButtonLabel' => $model->preferencesButtonLabel,
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
                'popupLayout' => $model->popupLayout,
                'popupPosition' => $model->popupPosition,
                'primaryColor' => $model->primaryColor,
                'backgroundColor' => $model->backgroundColor,
                'textColor' => $model->textColor,
                'overlayEnabled' => $model->overlayEnabled,
                'autoShowPopup' => $model->autoShowPopup,
                'consentExpiry' => $model->consentExpiry,
                'logConsent' => $model->logConsent,
                'showPreferencesButton' => $model->showPreferencesButton,
                'preferencesButtonLabel' => $model->preferencesButtonLabel,
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
        $model->popupLayout = trim((string)($row['popupLayout'] ?? $defaults->popupLayout));
        $model->popupPosition = trim((string)($row['popupPosition'] ?? $defaults->popupPosition));
        $model->primaryColor = trim((string)($row['primaryColor'] ?? $defaults->primaryColor));
        $model->backgroundColor = trim((string)($row['backgroundColor'] ?? $defaults->backgroundColor));
        $model->textColor = trim((string)($row['textColor'] ?? $defaults->textColor));
        $model->overlayEnabled = trim((string)($row['overlayEnabled'] ?? $defaults->overlayEnabled));
        $model->autoShowPopup = trim((string)($row['autoShowPopup'] ?? $defaults->autoShowPopup));
        $model->consentExpiry = trim((string)($row['consentExpiry'] ?? $defaults->consentExpiry));
        $model->logConsent = trim((string)($row['logConsent'] ?? $defaults->logConsent));
        $model->showPreferencesButton = trim((string)($row['showPreferencesButton'] ?? $defaults->showPreferencesButton));
        $model->preferencesButtonLabel = trim((string)($row['preferencesButtonLabel'] ?? $defaults->preferencesButtonLabel));

        return $model;
    }

    public function saveSiteSettings(int $siteId, array $input): bool
    {
        $this->ensureColumns();
        $current = $this->getSiteSettings($siteId);

        $model = new SiteSettingsModel();
        $model->popupTitle = trim((string)($input['popupTitle'] ?? $current->popupTitle));
        $model->popupDescription = trim((string)($input['popupDescription'] ?? $current->popupDescription));
        $model->acceptAllLabel = trim((string)($input['acceptAllLabel'] ?? $current->acceptAllLabel));
        $model->rejectAllLabel = trim((string)($input['rejectAllLabel'] ?? $current->rejectAllLabel));
        $model->savePreferencesLabel = trim((string)($input['savePreferencesLabel'] ?? $current->savePreferencesLabel));
        $model->cookiePolicyUrl = trim((string)($input['cookiePolicyUrl'] ?? $current->cookiePolicyUrl));
        $model->popupLayout = trim((string)($input['popupLayout'] ?? $current->popupLayout));
        $model->popupPosition = trim((string)($input['popupPosition'] ?? $current->popupPosition));
        $model->primaryColor = trim((string)($input['primaryColor'] ?? $current->primaryColor));
        $model->backgroundColor = trim((string)($input['backgroundColor'] ?? $current->backgroundColor));
        $model->textColor = trim((string)($input['textColor'] ?? $current->textColor));
        $model->overlayEnabled = trim((string)($input['overlayEnabled'] ?? $current->overlayEnabled));
        $model->autoShowPopup = trim((string)($input['autoShowPopup'] ?? $current->autoShowPopup));
        $model->consentExpiry = trim((string)($input['consentExpiry'] ?? $current->consentExpiry));
        $model->logConsent = trim((string)($input['logConsent'] ?? $current->logConsent));
        $model->showPreferencesButton = trim((string)($input['showPreferencesButton'] ?? $current->showPreferencesButton));
        $model->preferencesButtonLabel = trim((string)($input['preferencesButtonLabel'] ?? $current->preferencesButtonLabel));

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
            'popupLayout' => $model->popupLayout,
            'popupPosition' => $model->popupPosition,
            'primaryColor' => $model->primaryColor,
            'backgroundColor' => $model->backgroundColor,
            'textColor' => $model->textColor,
            'overlayEnabled' => $model->overlayEnabled,
            'autoShowPopup' => $model->autoShowPopup,
            'consentExpiry' => $model->consentExpiry,
            'logConsent' => $model->logConsent,
            'showPreferencesButton' => $model->showPreferencesButton,
            'preferencesButtonLabel' => $model->preferencesButtonLabel,
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
            'popupLayout' => $defaults->popupLayout,
            'popupPosition' => $defaults->popupPosition,
            'primaryColor' => $defaults->primaryColor,
            'backgroundColor' => $defaults->backgroundColor,
            'textColor' => $defaults->textColor,
            'overlayEnabled' => $defaults->overlayEnabled,
            'autoShowPopup' => $defaults->autoShowPopup,
            'consentExpiry' => $defaults->consentExpiry,
            'logConsent' => $defaults->logConsent,
            'showPreferencesButton' => $defaults->showPreferencesButton,
            'preferencesButtonLabel' => $defaults->preferencesButtonLabel,
        ];
    }

    private function ensureColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $db = Craft::$app->getDb();
        $schema = $db->getTableSchema(self::TABLE, true);
        if (!$schema) {
            $done = true;
            return;
        }

        $columns = [
            'popupLayout' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('bar'),
            'popupPosition' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('bottom'),
            'primaryColor' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('#2563eb'),
            'backgroundColor' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('#ffffff'),
            'textColor' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('#1f2937'),
            'overlayEnabled' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 8)->notNull()->defaultValue('true'),
            'autoShowPopup' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 8)->notNull()->defaultValue('true'),
            'consentExpiry' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 16)->notNull()->defaultValue('365'),
            'logConsent' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 8)->notNull()->defaultValue('true'),
            'showPreferencesButton' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 8)->notNull()->defaultValue('true'),
            'preferencesButtonLabel' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING)->notNull()->defaultValue('Cookie Settings'),
        ];

        foreach ($columns as $name => $definition) {
            if (!isset($schema->columns[$name])) {
                $db->createCommand()->addColumn(self::TABLE, $name, $definition)->execute();
            }
        }

        $done = true;
    }
}
