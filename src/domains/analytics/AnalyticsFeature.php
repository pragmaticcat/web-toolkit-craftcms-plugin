<?php

namespace pragmatic\webtoolkit\domains\analytics;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class AnalyticsFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'analytics'; }
    public static function navLabel(): string { return 'Analytics'; }
    public static function cpSubpath(): string { return 'analytics'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/analytics' => 'pragmatic-web-toolkit/analytics/index',
            'pragmatic-toolkit/analytics/general' => 'pragmatic-web-toolkit/analytics/general',
            'pragmatic-toolkit/analytics/options' => 'pragmatic-web-toolkit/analytics/options',
        ];
    }
    public function siteRoutes(): array
    {
        return [
            'pragmatic-toolkit/analytics/track' => 'pragmatic-web-toolkit/analytics/track',
        ];
    }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:analytics' => ['label' => 'Manage Analytics']];
    }
    public function injectFrontendHtml(string $html): string
    {
        return PragmaticWebToolkit::$plugin->analytics->injectFrontendScripts($html);
    }
}
