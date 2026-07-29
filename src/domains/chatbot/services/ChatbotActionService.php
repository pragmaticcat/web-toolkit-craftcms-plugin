<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;

class ChatbotActionService
{
    public function buildActions(array $entries, array $pageContext = []): array
    {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $actions = [];

        foreach (array_slice($entries, 0, $settings->maxSuggestions) as $entry) {
            $url = trim((string)($entry['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $actions[] = $this->sanitizeAction([
                'type' => 'open_url',
                'label' => 'Open ' . trim((string)($entry['title'] ?? 'this page')),
                'url' => $url,
            ]);
        }

        foreach ((array)($pageContext['selectors'] ?? []) as $selector) {
            if (count($actions) >= $settings->maxSuggestions) {
                break;
            }

            $actions[] = $this->sanitizeAction([
                'type' => 'scroll_to_selector',
                'label' => 'Go to ' . trim((string)($selector['label'] ?? 'section')),
                'selector' => trim((string)($selector['selector'] ?? '')),
            ]);
        }

        return array_values(array_filter($actions));
    }

    public function buildSuggestionLinks(array $entries): array
    {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $links = [];
        foreach (array_slice($entries, 0, $settings->maxSuggestions) as $entry) {
            $url = trim((string)($entry['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $links[] = [
                'title' => trim((string)($entry['title'] ?? 'Untitled')),
                'url' => $url,
                'section' => trim((string)($entry['section'] ?? '')),
            ];
        }

        return $links;
    }

    public function sanitizeAction(array $action): ?array
    {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $type = trim((string)($action['type'] ?? ''));
        if ($type === '' || !in_array($type, $settings->allowedActionTypes, true)) {
            return null;
        }

        $normalized = [
            'type' => $type,
            'label' => trim((string)($action['label'] ?? 'Continue')),
        ];

        if (isset($action['url'])) {
            $normalized['url'] = trim((string)$action['url']);
        }

        if (isset($action['selector'])) {
            $normalized['selector'] = trim((string)$action['selector']);
        }

        if (isset($action['payload']) && is_array($action['payload'])) {
            $normalized['payload'] = $action['payload'];
        }

        return $normalized;
    }
}
