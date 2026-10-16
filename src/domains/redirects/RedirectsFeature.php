<?php

namespace pragmatic\webtoolkit\domains\redirects;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

/**
 * Registers the redirects domain with the toolkit navigation and routing.
 */
class RedirectsFeature implements FeatureProviderInterface
{
    /** @return string Domain configuration key. */
    public static function domainKey(): string { return 'redirects'; }
    /** @return string Control panel navigation label. */
    public static function navLabel(): string { return 'Redirects'; }
    /** @return string Control panel subpath. */
    public static function cpSubpath(): string { return 'redirects'; }

    /** @return array<string, string> Control panel routes. */
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/redirects' => 'pragmatic-web-toolkit/redirects/index',
            'pragmatic-toolkit/redirects/new' => 'pragmatic-web-toolkit/redirects/edit',
            'pragmatic-toolkit/redirects/<id:\\d+>' => 'pragmatic-web-toolkit/redirects/edit',
            'pragmatic-toolkit/redirects/404s' => 'pragmatic-web-toolkit/redirects/404s',
            'pragmatic-toolkit/redirects/import' => 'pragmatic-web-toolkit/redirects/import',
            'pragmatic-toolkit/redirects/options' => 'pragmatic-web-toolkit/redirects/options',
        ];
    }

    /** @return array<string, string> No site routes are required. */
    public function siteRoutes(): array { return []; }

    /** @return array<string, array{label:string}> Permissions owned by Redirects. */
    public function permissions(): array
    {
        return [
            'pragmatic-toolkit:view-redirects' => ['label' => 'View redirects'],
            'pragmatic-toolkit:manage-redirects' => ['label' => 'Manage redirects'],
            'pragmatic-toolkit:view-404-log' => ['label' => 'View 404 log'],
            'pragmatic-toolkit:redirects-import-export' => ['label' => 'Import and export redirects'],
        ];
    }

    /** @param string $html Rendered HTML. @return string Unchanged HTML. */
    public function injectFrontendHtml(string $html): string { return $html; }
}
