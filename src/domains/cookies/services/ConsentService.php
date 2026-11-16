<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use function CraftCms\Cms\template;

use CraftCms\Cms\Support\Url;
use CraftCms\Cms\View\TemplateMode;
use pragmatic\webtoolkit\domains\cookies\assets\ConsentAsset;
use pragmatic\webtoolkit\domains\cookies\records\ConsentLogRecord;
use CraftCms\Cms\Support\Facades\Sites;

class ConsentService
{
    private const COOKIE_NAME = 'pragmatic_toolkit_cookies_consent';

    public function injectPopup(string $output): string
    {
        $frontend = $this->renderFrontend();
        if ($frontend === '') {
            return $output;
        }

        return str_replace('</body>', $frontend . '</body>', $output);
    }

    public function renderFrontend(): string
    {
        $siteId = (int)Sites::getCurrentSite()->id;
        $settings = (new SiteSettingsService())->getSiteSettings($siteId);
        $autoShow = $settings->autoShowPopup === 'true';
        $showButton = $settings->showPreferencesButton === 'true';

        if (!$autoShow && !$showButton) {
            return '';
        }

        $hasConsent = $this->hasExistingConsent();
        $shouldRenderPopup = !$hasConsent || $showButton;

        $popupHtml = $shouldRenderPopup ? $this->renderPopup() : '';
        $assetHtml = $this->assetTags();

        return $popupHtml . $assetHtml;
    }

    public function renderPopup(): string
    {
        $siteId = (int)Sites::getCurrentSite()->id;
        $siteSettings = (new SiteSettingsService())->getSiteSettings($siteId);
        $primaryColor = $this->normalizeColor($siteSettings->primaryColor, '#2563eb');
        $backgroundColor = $this->normalizeColor($siteSettings->backgroundColor, '#ffffff');
        $textColor = $this->normalizeColor($siteSettings->textColor, '#1f2937');

        $settings = [
            'popupTitle' => $siteSettings->popupTitle,
            'popupDescription' => $siteSettings->popupDescription,
            'acceptAllLabel' => $siteSettings->acceptAllLabel,
            'rejectAllLabel' => $siteSettings->rejectAllLabel,
            'savePreferencesLabel' => $siteSettings->savePreferencesLabel,
            'cookiePolicyUrl' => $siteSettings->cookiePolicyUrl,
            'popupLayout' => $siteSettings->popupLayout,
            'popupPosition' => $siteSettings->popupPosition,
            'primaryColor' => $primaryColor,
            'backgroundColor' => $backgroundColor,
            'textColor' => $textColor,
            'overlayEnabled' => $siteSettings->overlayEnabled,
            'showPreferencesButton' => $siteSettings->showPreferencesButton,
            'preferencesButtonLabel' => $siteSettings->preferencesButtonLabel,
        ];

        $categories = (new CategoriesService())->getAllCategories();
        $consent = $this->getCurrentConsent();

        return template('pragmatic-web-toolkit/cookies/frontend/_popup', [
            'settings' => $settings,
            'categories' => $categories,
            'consent' => $consent,
        ], TemplateMode::Cp);
    }

    private function normalizeColor(?string $value, string $fallback): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return $fallback;
        }
        if ($value[0] !== '#') {
            return '#' . $value;
        }
        return $value;
    }

    public function renderCookieTable(): string
    {
        $siteId = (int)Sites::getCurrentSite()->id;
        $grouped = (new CookiesService())->getCookiesGroupedByCategory($siteId);

        return template('pragmatic-web-toolkit/cookies/frontend/_cookie-table', [
            'grouped' => $grouped,
        ], TemplateMode::Cp);
    }

    public function hasConsent(string $categoryHandle): bool
    {
        $consent = $this->getCurrentConsent();
        return !empty($consent[$categoryHandle]);
    }

    public function getCurrentConsent(): array
    {
        $cookieValue = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$cookieValue) {
            return [];
        }

        $decoded = json_decode(urldecode($cookieValue), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function hasExistingConsent(): bool
    {
        return !empty($_COOKIE[self::COOKIE_NAME]);
    }

    public function logConsent(string $visitorId, array $consent, ?string $ipAddress, ?string $userAgent): void
    {
        $record = new ConsentLogRecord();
        $record->visitorId = $visitorId;
        $record->consent = json_encode($consent);
        $record->ipAddress = $ipAddress;
        $record->userAgent = $userAgent;
        $record->save();
    }

    private function assetTags(): string
    {
        $siteId = (int)Sites::getCurrentSite()->id;
        $settings = (new SiteSettingsService())->getSiteSettings($siteId);
        $categories = (new CategoriesService())->getAllCategories();

        $configJson = json_encode([
            'cookieName' => self::COOKIE_NAME,
            'consentExpiry' => (int)$settings->consentExpiry,
            'logConsent' => $settings->logConsent === 'true',
            'autoShowPopup' => $settings->autoShowPopup === 'true',
            'saveUrl' => Url::siteUrl('pragmatic-toolkit/cookies/consent/save'),
            'categories' => array_map(static fn($c) => [
                'handle' => $c->handle,
                'isRequired' => $c->isRequired,
            ], $categories),
        ]);

        $basePath = ConsentAsset::SOURCE_PATH;

        $cssContent = (string)file_get_contents($basePath . '/consent.css');
        $jsContent = (string)file_get_contents($basePath . '/consent.js');

        return "\n<style>{$cssContent}</style>\n"
            . "<script>window.PragmaticCookiesConfig = {$configJson};</script>\n"
            . "<script>{$jsContent}</script>\n";
    }
}
