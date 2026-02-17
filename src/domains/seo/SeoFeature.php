<?php

namespace pragmatic\webtoolkit\domains\seo;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class SeoFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'seo'; }
    public static function navLabel(): string { return 'SEO'; }
    public static function cpSubpath(): string { return 'seo'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/seo' => 'pragmatic-web-toolkit/domain/view?domain=seo',
        ];
    }
    public function siteRoutes(): array
    {
        return [
            'sitemap.xml' => 'pragmatic-web-toolkit/domain/seo-sitemap-xml',
        ];
    }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:seo' => ['label' => 'Manage SEO']];
    }
    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
