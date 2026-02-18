<?php

namespace pragmatic\webtoolkit\domains\favicon\models;

use craft\base\Model;

class FaviconSettingsModel extends Model
{
    public bool $enabled = true;

    public ?int $faviconIcoAssetId = null;
    public ?int $faviconSvgAssetId = null;
    public ?int $appleTouchIconAssetId = null;
    public ?int $maskIconAssetId = null;
    public string $maskIconColor = '#000000';
    public ?int $manifestAssetId = null;
    public string $themeColor = '#ffffff';
    public string $msTileColor = '#ffffff';

    public function rules(): array
    {
        return [
            [['enabled'], 'boolean'],
            [['faviconIcoAssetId', 'faviconSvgAssetId', 'appleTouchIconAssetId', 'maskIconAssetId', 'manifestAssetId'], 'integer', 'min' => 1],
            [['faviconIcoAssetId', 'faviconSvgAssetId', 'appleTouchIconAssetId', 'maskIconAssetId', 'manifestAssetId'], 'default', 'value' => null],
            [['maskIconColor'], 'default', 'value' => '#000000'],
            [['themeColor', 'msTileColor'], 'default', 'value' => '#ffffff'],
            [['maskIconColor', 'themeColor', 'msTileColor'], 'string', 'max' => 32],
        ];
    }
}
