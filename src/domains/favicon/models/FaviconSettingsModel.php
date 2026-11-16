<?php

namespace pragmatic\webtoolkit\domains\favicon\models;

use CraftCms\Cms\Component\Component;

class FaviconSettingsModel extends Component
{
    public bool $enabled = true;
    public bool $autoGenerateManifest = true;

    public ?int $faviconIcoAssetId = null;
    public ?int $faviconSvgAssetId = null;
    public ?int $appleTouchIconAssetId = null;
    public ?int $maskIconAssetId = null;
    public string $maskIconColor = '#000000';
    public ?int $manifestAssetId = null;
    public string $themeColor = '#ffffff';
    public string $msTileColor = '#ffffff';

    public function getRules(): array
    {
        $assetRule = ['nullable', 'integer', 'min:1'];

        return [
            'enabled' => ['boolean'],
            'autoGenerateManifest' => ['boolean'],
            'faviconIcoAssetId' => $assetRule,
            'faviconSvgAssetId' => $assetRule,
            'appleTouchIconAssetId' => $assetRule,
            'maskIconAssetId' => $assetRule,
            'manifestAssetId' => $assetRule,
            'maskIconColor' => ['string', 'max:32'],
            'themeColor' => ['string', 'max:32'],
            'msTileColor' => ['string', 'max:32'],
        ];
    }
}
