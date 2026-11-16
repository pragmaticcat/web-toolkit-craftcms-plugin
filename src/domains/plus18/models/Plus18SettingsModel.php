<?php

namespace pragmatic\webtoolkit\domains\plus18\models;

use CraftCms\Cms\Component\Component;

class Plus18SettingsModel extends Component
{
    public bool $enabled = true;
    public string $cookieName = 'silverbranch_age_check';
    public int $cookieDays = 1;
    public int $minimumAge = 18;
    public ?int $logoAssetId = null;
    public ?string $logoUrl = null;
    public ?string $primaryButtonColor = null;
    public ?string $fontFamily = null;
    public bool $showNoButton = false;

    /** @var array<int|string, string> */
    public array $underageUrls = [];

    /** @var array<string, array{previousText?: string, yesText?: string, noText?: string, afterText?: string}> */
    public array $translations = [];

    public function getRules(): array
    {
        return [
            'enabled' => ['boolean'],
            'showNoButton' => ['boolean'],
            'cookieName' => ['required', 'string'],
            'logoUrl' => ['nullable', 'string'],
            'fontFamily' => ['nullable', 'string'],
            'primaryButtonColor' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'logoAssetId' => ['nullable', 'integer', 'min:1'],
            'cookieDays' => ['integer', 'min:1'],
            'minimumAge' => ['integer', 'min:1'],
        ];
    }
}
