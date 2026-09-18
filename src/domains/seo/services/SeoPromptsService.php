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
        return 'Generate SEO metadata for every entry in the input. Preserve the bundle structure and all identity fields. Fill only title, description and imageId; keep aiInstructions unchanged.';
    }

    public function defaultAssetsPrompt(): string
    {
        return 'Generate SEO metadata for every asset in the input. Preserve the bundle structure and all identity fields. Fill only title and alt; keep aiInstructions unchanged.';
    }
}
