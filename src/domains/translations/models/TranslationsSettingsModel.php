<?php

namespace pragmatic\webtoolkit\domains\translations\models;

use craft\base\Model;

class TranslationsSettingsModel extends Model
{
    public array $languageMap = [];
    public string $translationSourcePreference = 'db';
    public string $staticPrompt = '';
    public string $entriesPrompt = '';
    public string $assetsPrompt = '';

    public function rules(): array
    {
        return [
            ['languageMap', 'safe'],
            [['staticPrompt', 'entriesPrompt', 'assetsPrompt'], 'string'],
            ['translationSourcePreference', 'in', 'range' => ['db', 'files']],
        ];
    }

    public static function defaultPrompt(): string
    {
        return <<<'PROMPT'
You are an expert website localization assistant.
Translate the values from {{sourceLanguage}} into every other language already present in each values object.
PROMPT;
    }
}
