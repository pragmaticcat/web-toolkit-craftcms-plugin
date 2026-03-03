<?php

namespace pragmatic\webtoolkit\domains\sync;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class SyncFeature implements FeatureProviderInterface
{
    public static function domainKey(): string
    {
        return 'sync';
    }

    public static function navLabel(): string
    {
        return 'Sync';
    }

    public static function cpSubpath(): string
    {
        return 'sync';
    }

    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/sync' => 'pragmatic-web-toolkit/sync/index',
            'pragmatic-toolkit/sync/packages' => 'pragmatic-web-toolkit/sync/packages',
            'pragmatic-toolkit/sync/export' => 'pragmatic-web-toolkit/sync/export',
            'pragmatic-toolkit/sync/upload-import-package' => 'pragmatic-web-toolkit/sync/upload-import-package',
            'pragmatic-toolkit/sync/confirm-import' => 'pragmatic-web-toolkit/sync/confirm-import',
            'pragmatic-toolkit/sync/options' => 'pragmatic-web-toolkit/sync/options',
            'pragmatic-toolkit/sync/save-options' => 'pragmatic-web-toolkit/sync/save-options',
        ];
    }

    public function siteRoutes(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [
            'pragmatic-toolkit:sync-manage' => ['label' => 'Manage Sync'],
            'pragmatic-toolkit:sync-export' => ['label' => 'Export Sync Packages'],
            'pragmatic-toolkit:sync-import' => ['label' => 'Import Sync Packages'],
        ];
    }

    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
