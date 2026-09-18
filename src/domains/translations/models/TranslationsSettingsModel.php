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

OUTPUT CONTRACT (mandatory):
- Return exactly one valid JSON object and nothing else.
- Enclose the final JSON output inside a single markdown code block (```json ... ```).
- Do not output any conversational text, explanations, or markdown outside the code block.
- Use double quotes for every JSON key and string. Escape quotes, backslashes, and line breaks correctly.
- The JSON inside the code block must parse with JSON.parse() without preprocessing.
- Preserve exactly the root keys and structure: version, domain, site, generatedAt, items.
- Preserve all identity fields, array order, language keys, data types, and non-translatable values.
- Preserve placeholders and tokens byte-for-byte, including {name}, {count}, %s, :attribute and {{variable}}.
- Do not add or remove keys. Only replace the translatable string values.
- Never truncate the JSON syntax or strings; if the payload is too large, safely limit the number of items returned in this response rather than cutting off mid-text.
- Before responding, silently validate that the result is syntactically valid JSON.
PROMPT;
    }
}
