<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use Craft;
use craft\elements\Entry;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ChatbotContextService
{
    public function buildPageContext(array $payload = []): array
    {
        $context = [
            'url' => trim((string)($payload['url'] ?? Craft::$app->getRequest()->getAbsoluteUrl())),
            'title' => trim((string)($payload['title'] ?? '')),
            'sectionHandle' => trim((string)($payload['sectionHandle'] ?? '')),
            'entryId' => isset($payload['entryId']) ? (int)$payload['entryId'] : null,
            'selectors' => $this->normalizeSelectorMap($payload['selectors'] ?? []),
            'ctaLinks' => $this->normalizeLinks($payload['ctaLinks'] ?? []),
        ];

        if ($context['entryId']) {
            $entry = Entry::find()->id($context['entryId'])->status(null)->one();
            if ($entry) {
                $context['entry'] = [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'url' => $entry->url,
                    'sectionHandle' => $entry->section->handle,
                ];
            }
        }

        return $context;
    }

    public function findRelevantEntries(string $message, array $pageContext, array $siteContext): array
    {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $siteSettings = PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings((int)Craft::$app->getSites()->getCurrentSite()->id);
        $limit = min($settings->maxContextItems, 6);
        $results = [];

        if (PragmaticWebToolkit::$plugin->domains->isEnabled('mcp')) {
            try {
                $results = PragmaticWebToolkit::$plugin->mcpTool->executeTool('search_entries', [
                    'query' => $message,
                    'limit' => $limit,
                ]);
            } catch (\Throwable $e) {
                Craft::warning('Chatbot MCP search failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        if (empty($results)) {
            $query = Entry::find()
                ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
                ->search($message)
                ->limit($limit);

            if (!empty($siteSettings->allowedSections)) {
                $query->section($siteSettings->allowedSections);
            }

            $entries = $query->all();
            $results = array_map(static fn(Entry $entry): array => PragmaticWebToolkit::$plugin->mcpResource->formatEntry($entry), $entries);
        }

        return $this->filterEntries($results, $siteContext);
    }

    public function buildSiteContext(): array
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $settings = PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings((int)$site->id);

        return [
            'siteId' => (int)$site->id,
            'siteName' => $site->name,
            'siteLanguage' => $site->language,
            'allowedSections' => $settings->allowedSections,
            'excludedSections' => $settings->excludedSections,
        ];
    }

    private function filterEntries(array $entries, array $siteContext): array
    {
        $allowed = array_values(array_filter(array_map('strval', (array)($siteContext['allowedSections'] ?? []))));
        $excluded = array_values(array_filter(array_map('strval', (array)($siteContext['excludedSections'] ?? []))));

        return array_values(array_filter($entries, static function (array $entry) use ($allowed, $excluded): bool {
            $section = (string)($entry['sectionHandle'] ?? '');
            if ($allowed !== [] && !in_array($section, $allowed, true)) {
                return false;
            }

            if ($excluded !== [] && in_array($section, $excluded, true)) {
                return false;
            }

            return !empty($entry['url']);
        }));
    }

    private function normalizeSelectorMap(mixed $selectors): array
    {
        if (!is_array($selectors)) {
            return [];
        }

        $normalized = [];
        foreach ($selectors as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string)($item['label'] ?? ''));
            $selector = trim((string)($item['selector'] ?? ''));
            if ($label === '' || $selector === '') {
                continue;
            }

            $normalized[] = ['label' => $label, 'selector' => $selector];
        }

        return $normalized;
    }

    private function normalizeLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }

        $normalized = [];
        foreach ($links as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string)($item['label'] ?? ''));
            $url = trim((string)($item['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }

            $normalized[] = ['label' => $label, 'url' => $url];
        }

        return $normalized;
    }
}
