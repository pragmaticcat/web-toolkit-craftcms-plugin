<?php

namespace pragmatic\webtoolkit\domains\languageRedirect;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class LanguageRedirectFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'languageRedirect'; }
    public static function navLabel(): string { return 'Language Redirect'; }
    public static function cpSubpath(): string { return 'language-redirect'; }

    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/language-redirect' => 'pragmatic-web-toolkit/language-redirect/index',
            'pragmatic-toolkit/language-redirect/general' => 'pragmatic-web-toolkit/language-redirect/general',
            'pragmatic-toolkit/language-redirect/options' => 'pragmatic-web-toolkit/language-redirect/options',
        ];
    }

    public function siteRoutes(): array
    {
        return [
            'pragmatic-toolkit/language-redirect/preference' => 'pragmatic-web-toolkit/language-redirect/preference',
        ];
    }

    public function permissions(): array
    {
        return ['pragmatic-toolkit:language-redirect' => ['label' => 'Manage language redirect']];
    }

    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
