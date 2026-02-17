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
            'pragmatic-toolkit/translations' => 'pragmatic-web-toolkit/domain/view?domain=translations',
        ];
    }
    public function siteRoutes(): array
    {
        return [];
    }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:translations' => ['label' => 'Manage Translations']];
    }
    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
