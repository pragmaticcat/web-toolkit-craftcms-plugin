<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\domains\cookies\models\CookieSettingsModel;
use pragmatic\webtoolkit\domains\cookies\models\SiteSettingsModel;
use pragmatic\webtoolkit\db\Query;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;

use function CraftCms\Cms\t;

class SiteSettingsService
{
    private const TABLE = 'pragmatic_toolkit_cookies_site_settings';

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
            $this->applyGlobalOptionsToModel($model, $defaults);

            $now = DbHelper::prepareDateForDb(new \DateTime());
            Command::make()->upsert(self::TABLE, [
                'siteId' => $siteId,
                'popupTitle' => $model->popupTitle,
                'popupDescription' => $model->popupDescription,
                'acceptAllLabel' => $model->acceptAllLabel,
                'rejectAllLabel' => $model->rejectAllLabel,
                'savePreferencesLabel' => $model->savePreferencesLabel,
                'cookiePolicyUrl' => $model->cookiePolicyUrl,
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
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => Str::uuid()->toString(),
            ], [
                'popupTitle' => $model->popupTitle,
                'popupDescription' => $model->popupDescription,
                'acceptAllLabel' => $model->acceptAllLabel,
                'rejectAllLabel' => $model->rejectAllLabel,
                'savePreferencesLabel' => $model->savePreferencesLabel,
                'cookiePolicyUrl' => $model->cookiePolicyUrl,
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
        $this->applyGlobalOptionsToModel($model, $defaults);

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

        $now = DbHelper::prepareDateForDb(new \DateTime());
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

        Command::make()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid()->toString(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    private function buildLocalizedDefaults(int $siteId, $defaults): array
    {
        $site = Sites::getSiteById($siteId);
        $language = $site ? $site->language : Sites::getCurrentSite()->language;

        return [
            'popupTitle' => t('defaults.cookies.popup-title', [], 'pragmatic-web-toolkit', $language) ?: $defaults->popupTitle,
            'popupDescription' => t('defaults.cookies.popup-description', [], 'pragmatic-web-toolkit', $language) ?: $defaults->popupDescription,
            'acceptAllLabel' => t('defaults.cookies.accept-all-label', [], 'pragmatic-web-toolkit', $language) ?: $defaults->acceptAllLabel,
            'rejectAllLabel' => t('defaults.cookies.reject-all-label', [], 'pragmatic-web-toolkit', $language) ?: $defaults->rejectAllLabel,
            'savePreferencesLabel' => t('defaults.cookies.save-preferences-label', [], 'pragmatic-web-toolkit', $language) ?: $defaults->savePreferencesLabel,
            'cookiePolicyUrl' => $defaults->cookiePolicyUrl,
        ];
    }

    private function applyGlobalOptionsToModel(SiteSettingsModel $model, CookieSettingsModel $defaults): void
    {
        $model->popupLayout = trim((string)$defaults->popupLayout);
        $model->popupPosition = trim((string)$defaults->popupPosition);
        $model->primaryColor = trim((string)$defaults->primaryColor);
        $model->backgroundColor = trim((string)$defaults->backgroundColor);
        $model->textColor = trim((string)$defaults->textColor);
        $model->overlayEnabled = trim((string)$defaults->overlayEnabled);
        $model->autoShowPopup = trim((string)$defaults->autoShowPopup);
        $model->consentExpiry = trim((string)$defaults->consentExpiry);
        $model->logConsent = trim((string)$defaults->logConsent);
        $model->showPreferencesButton = trim((string)$defaults->showPreferencesButton);
        $model->preferencesButtonLabel = trim((string)$defaults->preferencesButtonLabel);
    }

    private function ensureColumns(): void
    {
        // Les columnes les crea la migració Install.
    }
}
