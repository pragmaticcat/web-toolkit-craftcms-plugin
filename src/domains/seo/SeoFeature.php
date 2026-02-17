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
            'pragmatic-toolkit/seo' => 'pragmatic-web-toolkit/seo/index',
            'pragmatic-toolkit/seo/general' => 'pragmatic-web-toolkit/seo/general',
            'pragmatic-toolkit/seo/options' => 'pragmatic-web-toolkit/seo/options',
            'pragmatic-toolkit/seo/content' => 'pragmatic-web-toolkit/seo/content',
            'pragmatic-toolkit/seo/sitemap' => 'pragmatic-web-toolkit/seo/sitemap',
        ];
    }
    public function siteRoutes(): array
    {
        return [
            'sitemap.xml' => 'pragmatic-web-toolkit/seo/sitemap-xml',
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
