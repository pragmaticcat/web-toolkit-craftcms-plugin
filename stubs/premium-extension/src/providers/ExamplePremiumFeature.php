<?php

namespace pragmatic\premiumexample\providers;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class ExamplePremiumFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'seo'; }
    public static function navLabel(): string { return 'SEO Premium'; }
    public static function cpSubpath(): string { return 'seo-premium'; }

    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/seo/premium' => 'premium-example/default/index',
        ];
    }

    public function siteRoutes(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return ['pragmatic-toolkit:seo-premium' => ['label' => 'Manage SEO Premium']];
    }

    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
