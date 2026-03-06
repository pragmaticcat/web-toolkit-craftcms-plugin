<?php

namespace pragmatic\webtoolkit\domains\translations\models;

use craft\base\Model;

class TranslationsSettingsModel extends Model
{
    public array $languageMap = [];
    public string $translationSourcePreference = 'db';

    public function rules(): array
    {
        return [
            ['languageMap', 'safe'],
            ['translationSourcePreference', 'in', 'range' => ['db', 'files']],
        ];
    }
}
