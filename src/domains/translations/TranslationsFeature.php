<?php

namespace pragmatic\webtoolkit\domains\translations;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class TranslationsFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'translations'; }
    public static function navLabel(): string { return 'Translations'; }
    public static function cpSubpath(): string { return 'translations'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/translations' => 'pragmatic-web-toolkit/translations/index',
            'pragmatic-toolkit/translations/static' => 'pragmatic-web-toolkit/translations/static-index',
            'pragmatic-toolkit/translations/entries' => 'pragmatic-web-toolkit/translations/entries',
            'pragmatic-toolkit/translations/options' => 'pragmatic-web-toolkit/translations/options',
            'pragmatic-toolkit/translations/export' => 'pragmatic-web-toolkit/translations/export',
        ];
    }
    public function siteRoutes(): array
    {
        return [];
    }
    public function permissions(): array
    {
        return [
            'pragmatic-toolkit:translations-manage' => ['label' => 'Manage Translations'],
            'pragmatic-toolkit:translations-export' => ['label' => 'Export Translations'],
        ];
    }
    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
