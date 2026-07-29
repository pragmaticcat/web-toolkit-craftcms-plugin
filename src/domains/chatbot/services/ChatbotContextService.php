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
                ->status('live')
                ->search($message)
                ->limit($limit);

            if (!empty($siteSettings->allowedSections)) {
                $query->section($siteSettings->allowedSections);
            }

            $entries = $query->all();
            $results = array_map([$this, 'formatEntry'], $entries);
        }

        if (empty($results)) {
            $results = $this->findEntriesByHeuristics($message, $siteSettings->allowedSections, $siteSettings->excludedSections, $limit);
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

    /**
     * @param string[] $allowedSections
     * @param string[] $excludedSections
     * @return array<int, array<string, mixed>>
     */
    private function findEntriesByHeuristics(string $message, array $allowedSections, array $excludedSections, int $limit): array
    {
        $tokens = $this->tokenize($message);
        if ($tokens === []) {
            return [];
        }

        $query = Entry::find()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->status('live')
            ->limit(120);

        if ($allowedSections !== []) {
            $query->section($allowedSections);
        }

        $entries = $query->all();
        $scored = [];

        foreach ($entries as $entry) {
            if (in_array($entry->section->handle, $excludedSections, true)) {
                continue;
            }

            $haystackParts = [
                mb_strtolower((string)$entry->title),
                mb_strtolower((string)$entry->slug),
                mb_strtolower((string)($entry->url ?? '')),
            ];

            $score = 0;
            foreach ($tokens as $token) {
                foreach ($haystackParts as $part) {
                    if ($part === '') {
                        continue;
                    }

                    if ($part === $token) {
                        $score += 8;
                    } elseif (str_contains($part, $token)) {
                        $score += 4;
                    }
                }
            }

            $fullQuery = mb_strtolower(trim($message));
            if ($fullQuery !== '' && str_contains(mb_strtolower((string)$entry->title), $fullQuery)) {
                $score += 10;
            }

            if ($score > 0) {
                $scored[] = [
                    'score' => $score,
                    'entry' => $this->formatEntry($entry),
                ];
            }
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            static fn(array $row): array => $row['entry'],
            array_slice($scored, 0, $limit)
        );
    }

    /**
     * @return string[]
     */
    private function tokenize(string $message): array
    {
        $message = mb_strtolower(trim($message));
        if ($message === '') {
            return [];
        }

        $parts = preg_split('/[^[:alnum:]]+/u', $message) ?: [];
        $parts = array_map(static fn(string $part): string => trim($part), $parts);
        $parts = array_values(array_filter($parts, static fn(string $part): bool => mb_strlen($part) >= 2));

        return array_values(array_unique($parts));
    }

    private function formatEntry(Entry $entry): array
    {
        if (PragmaticWebToolkit::$plugin->domains->isEnabled('mcp')) {
            return PragmaticWebToolkit::$plugin->mcpResource->formatEntry($entry);
        }

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'url' => $entry->url,
            'section' => $entry->section->name,
            'sectionHandle' => $entry->section->handle,
        ];
    }
}
