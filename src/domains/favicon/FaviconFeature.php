<?php

namespace pragmatic\webtoolkit\domains\favicon;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class FaviconFeature implements FeatureProviderInterface
{
    public static function domainKey(): string
    {
        return 'favicon';
    }

    public static function navLabel(): string
    {
        return 'Favicon';
    }

    public static function cpSubpath(): string
    {
        return 'favicon';
    }

    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/favicon' => 'pragmatic-web-toolkit/favicon/index',
            'pragmatic-toolkit/favicon/general' => 'pragmatic-web-toolkit/favicon/general',
            'pragmatic-toolkit/favicon/options' => 'pragmatic-web-toolkit/favicon/options',
            'pragmatic-toolkit/favicon/save-general' => 'pragmatic-web-toolkit/favicon/save-general',
        ];
    }

    public function siteRoutes(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return ['pragmatic-toolkit:favicon' => ['label' => 'Manage Favicon']];
    }

    public function injectFrontendHtml(string $html): string
    {
        return PragmaticWebToolkit::$plugin->faviconTags->injectIntoHtml($html);
    }
}
