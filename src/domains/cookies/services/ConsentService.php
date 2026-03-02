<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\cookies\assets\ConsentAsset;
use pragmatic\webtoolkit\domains\cookies\records\ConsentLogRecord;

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
        $settings = (new CookiesSettingsService())->get();
        if ($settings->autoShowPopup !== 'true') {
            return '';
        }

        if ($this->hasExistingConsent()) {
            $assetHtml = $this->assetTags();
            return $assetHtml;
        }

        $popupHtml = $this->renderPopup();
        $assetHtml = $this->assetTags();

        return $popupHtml . $assetHtml;
    }

    public function renderPopup(): string
    {
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $siteSettings = (new SiteSettingsService())->getSiteSettings($siteId);
        $baseSettings = (new CookiesSettingsService())->get();
        $isPro = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO);

        $settings = [
            'popupTitle' => $siteSettings->popupTitle,
            'popupDescription' => $siteSettings->popupDescription,
            'acceptAllLabel' => $siteSettings->acceptAllLabel,
            'rejectAllLabel' => $siteSettings->rejectAllLabel,
            'savePreferencesLabel' => $siteSettings->savePreferencesLabel,
            'cookiePolicyUrl' => $siteSettings->cookiePolicyUrl,
            'popupLayout' => $isPro ? $baseSettings->popupLayout : 'bar',
            'popupPosition' => $isPro ? $baseSettings->popupPosition : 'bottom',
            'primaryColor' => $isPro ? $baseSettings->primaryColor : '#2563eb',
            'backgroundColor' => $isPro ? $baseSettings->backgroundColor : '#ffffff',
            'textColor' => $isPro ? $baseSettings->textColor : '#1f2937',
            'overlayEnabled' => $baseSettings->overlayEnabled,
        ];

        $categories = (new CategoriesService())->getAllCategories();
        $consent = $this->getCurrentConsent();

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('pragmatic-web-toolkit/cookies/frontend/_popup', [
            'settings' => $settings,
            'categories' => $categories,
            'consent' => $consent,
        ]);

        $view->setTemplateMode($oldMode);

        return $html;
    }

    public function renderCookieTable(): string
    {
        $grouped = (new CookiesService())->getCookiesGroupedByCategory();

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('pragmatic-web-toolkit/cookies/frontend/_cookie-table', [
            'grouped' => $grouped,
        ]);

        $view->setTemplateMode($oldMode);

        return $html;
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
        $settings = (new CookiesSettingsService())->get();
        $categories = (new CategoriesService())->getAllCategories();

        $configJson = json_encode([
            'cookieName' => self::COOKIE_NAME,
            'consentExpiry' => (int)$settings->consentExpiry,
            'logConsent' => $settings->logConsent === 'true',
            'saveUrl' => UrlHelper::siteUrl('pragmatic-toolkit/cookies/consent/save'),
            'categories' => array_map(static fn($c) => [
                'handle' => $c->handle,
                'isRequired' => $c->isRequired,
            ], $categories),
        ]);

        $bundle = new ConsentAsset();
        $basePath = $bundle->sourcePath;

        $cssContent = (string)file_get_contents($basePath . '/consent.css');
        $jsContent = (string)file_get_contents($basePath . '/consent.js');

        return "\n<style>{$cssContent}</style>\n"
            . "<script>window.PragmaticCookiesConfig = {$configJson};</script>\n"
            . "<script>{$jsContent}</script>\n";
    }
}
