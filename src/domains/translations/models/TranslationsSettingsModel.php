<?php

namespace pragmatic\webtoolkit\domains\translations\models;

use craft\base\Model;

class TranslationsSettingsModel extends Model
{
    public string $googleProjectId = '';
    public string $googleLocation = 'global';
    public string $googleApiKeyEnv = 'GOOGLE_TRANSLATE_API_KEY';
    public array $languageMap = [];
    public bool $enableAutotranslate = true;

    public function rules(): array
    {
        return [
            [['googleProjectId', 'googleLocation', 'googleApiKeyEnv'], 'string'],
            ['languageMap', 'safe'],
            ['enableAutotranslate', 'boolean'],
        ];
    }
}
