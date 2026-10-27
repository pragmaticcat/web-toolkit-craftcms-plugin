<?php

namespace pragmatic\webtoolkit\domains\externalCode;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class ExternalCodeFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'externalCode'; }
    public static function navLabel(): string { return 'External Code'; }
    public static function cpSubpath(): string { return 'external-code'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/external-code' => 'pragmatic-web-toolkit/external-code/index',
            'pragmatic-toolkit/external-code/new' => 'pragmatic-web-toolkit/external-code/edit',
            'pragmatic-toolkit/external-code/setup' => 'pragmatic-web-toolkit/external-code/setup',
            'pragmatic-toolkit/external-code/<id:[a-fA-F0-9-]+>' => 'pragmatic-web-toolkit/external-code/edit',
        ];
    }
    public function siteRoutes(): array { return []; }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:manage-external-code' => ['label' => 'Manage external code']];
    }
    public function injectFrontendHtml(string $html): string { return $html; }
}
