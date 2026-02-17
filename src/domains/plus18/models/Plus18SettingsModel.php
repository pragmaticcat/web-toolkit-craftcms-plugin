<?php

namespace pragmatic\webtoolkit\domains\plus18\models;

use craft\base\Model;

class Plus18SettingsModel extends Model
{
    public bool $enabled = true;
    public string $cookieName = 'silverbranch_age_check';
    public int $cookieDays = 1;
    public int $minimumAge = 18;
    public ?string $logoUrl = null;
    public bool $showNoButton = false;

    /** @var array<int|string, string> */
    public array $underageUrls = [];

    /** @var array<string, array{previousText?: string, yesText?: string, noText?: string, afterText?: string}> */
    public array $translations = [];

    public function rules(): array
    {
        return [
            [['enabled', 'showNoButton'], 'boolean'],
            [['cookieName'], 'required'],
            [['cookieName', 'logoUrl'], 'string'],
            [['cookieDays', 'minimumAge'], 'integer', 'min' => 1],
            [['translations', 'underageUrls'], 'safe'],
            [['logoUrl'], 'default', 'value' => null],
        ];
    }
}
