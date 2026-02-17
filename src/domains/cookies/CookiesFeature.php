<?php

namespace pragmatic\webtoolkit\domains\cookies;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class CookiesFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'cookies'; }
    public static function navLabel(): string { return 'Cookies'; }
    public static function cpSubpath(): string { return 'cookies'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/cookies' => 'pragmatic-web-toolkit/cookies/index',
            'pragmatic-toolkit/cookies/general' => 'pragmatic-web-toolkit/cookies/general',
            'pragmatic-toolkit/cookies/options' => 'pragmatic-web-toolkit/cookies/options',
            'pragmatic-toolkit/cookies/categories' => 'pragmatic-web-toolkit/cookies/categories',
            'pragmatic-toolkit/cookies/categories/new' => 'pragmatic-web-toolkit/cookies/edit-category',
            'pragmatic-toolkit/cookies/categories/<categoryId:\\d+>' => 'pragmatic-web-toolkit/cookies/edit-category',
            'pragmatic-toolkit/cookies/cookies' => 'pragmatic-web-toolkit/cookies/cookies',
        ];
    }
    public function siteRoutes(): array
    {
        return [
            'pragmatic-toolkit/cookies/consent/save' => 'pragmatic-web-toolkit/cookies/save-consent',
        ];
    }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:cookies' => ['label' => 'Manage Cookies']];
    }
    public function injectFrontendHtml(string $html): string
    {
        return PragmaticWebToolkit::$plugin->cookiesConsent->injectPopup($html);
    }
}
