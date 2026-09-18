<?php

namespace pragmatic\webtoolkit\domains\seo\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;

class SeoPromptsService
{
    public function get(int $siteId): array
    {
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('seo-prompts');
        $siteSettings = is_array($stored[(string)$siteId] ?? null) ? $stored[(string)$siteId] : [];

        return [
            'contentPrompt' => trim((string)($siteSettings['contentPrompt'] ?? '')) ?: $this->defaultContentPrompt(),
            'assetsPrompt' => trim((string)($siteSettings['assetsPrompt'] ?? '')) ?: $this->defaultAssetsPrompt(),
        ];
    }

    public function save(int $siteId, array $input): bool
    {
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('seo-prompts');
        $stored[(string)$siteId] = [
            'contentPrompt' => trim((string)($input['contentPrompt'] ?? '')) ?: $this->defaultContentPrompt(),
            'assetsPrompt' => trim((string)($input['assetsPrompt'] ?? '')) ?: $this->defaultAssetsPrompt(),
        ];

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('seo-prompts', $stored);
    }

    public function defaultContentPrompt(): string
    {
        return <<<'PROMPT'
Generate SEO metadata for every entry in the input. Preserve the bundle structure and all identity fields. Fill only title, description and imageId; keep aiInstructions unchanged.

OUTPUT CONTRACT (mandatory):
- Return exactly one valid JSON object and nothing else.
- Enclose the final JSON output inside a single markdown code block (```json ... ```).
- Do not output any conversational text, explanations, or markdown outside the code block.
- Use double quotes and correct JSON escaping. The JSON inside the code block must parse with JSON.parse() without preprocessing.
- Preserve root keys, identity fields, array order, and data types exactly. Do not add keys.
- Never truncate the JSON syntax or strings; if the payload is too large, safely limit the number of items returned in this response rather than cutting off mid-text.
- Silently validate the JSON syntax before responding.
PROMPT;
    }

    public function defaultAssetsPrompt(): string
    {
        return <<<'PROMPT'
Generate SEO metadata for every asset in the input. Preserve the bundle structure and all identity fields. Fill only title and alt; keep aiInstructions unchanged.

OUTPUT CONTRACT (mandatory):
- Return exactly one valid JSON object and nothing else.
- Enclose the final JSON output inside a single markdown code block (```json ... ```).
- Do not output any conversational text, explanations, or markdown outside the code block.
- Use double quotes and correct JSON escaping. The JSON inside the code block must parse with JSON.parse() without preprocessing.
- Preserve root keys, identity fields, array order, and data types exactly. Do not add keys.
- Never truncate the JSON syntax or strings; if the payload is too large, safely limit the number of items returned in this response rather than cutting off mid-text.
- Silently validate the JSON syntax before responding.
PROMPT;
    }
}
