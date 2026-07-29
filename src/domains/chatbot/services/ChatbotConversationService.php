<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use Craft;
use craft\helpers\Json;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ChatbotConversationService
{
    private const SESSION_KEY = 'pwt_chatbot_sessions';

    public function startSession(array $payload = []): array
    {
        $conversationId = $this->createConversationId();
        $pageContext = PragmaticWebToolkit::$plugin->chatbotContext->buildPageContext($payload['pageContext'] ?? []);
        $state = [
            'id' => $conversationId,
            'history' => [],
            'pageContext' => $pageContext,
            'createdAt' => gmdate('c'),
        ];

        $this->storeSession($conversationId, $state);

        return $state;
    }

    public function getSession(?string $conversationId): ?array
    {
        if (!$conversationId) {
            return null;
        }

        return $this->allSessions()[$conversationId] ?? null;
    }

    public function reply(string $conversationId, string $message, array $payload = []): array
    {
        $message = trim($message);
        if ($message === '') {
            return $this->buildEmptyReply($conversationId);
        }

        $session = $this->getSession($conversationId) ?? $this->startSession($payload);
        $siteContext = PragmaticWebToolkit::$plugin->chatbotContext->buildSiteContext();
        $pageContext = PragmaticWebToolkit::$plugin->chatbotContext->buildPageContext($payload['pageContext'] ?? $session['pageContext'] ?? []);
        $entries = PragmaticWebToolkit::$plugin->chatbotContext->findRelevantEntries($message, $pageContext, $siteContext);
        $actions = PragmaticWebToolkit::$plugin->chatbotActions->buildActions($entries, $pageContext);
        $links = PragmaticWebToolkit::$plugin->chatbotActions->buildSuggestionLinks($entries);
        $citations = array_map(static function (array $entry): array {
            return [
                'title' => (string)($entry['title'] ?? ''),
                'url' => (string)($entry['url'] ?? ''),
                'section' => (string)($entry['section'] ?? ''),
            ];
        }, array_slice($entries, 0, 3));

        $replyText = $this->buildReplyText($message, $entries, $pageContext);

        $aiReply = PragmaticWebToolkit::$plugin->chatbotAi->generateReply($message, $entries, $pageContext, $siteContext, $session['history'] ?? []);
        if (is_array($aiReply) && trim((string)($aiReply['message'] ?? '')) !== '') {
            $replyText = trim((string)$aiReply['message']);
            $actions = $this->normalizeActions((array)($aiReply['suggestedActions'] ?? []), $actions);
            $links = $this->normalizeLinks((array)($aiReply['suggestedLinks'] ?? []), $links);
            $citations = $this->normalizeCitations((array)($aiReply['citations'] ?? []), $citations);
        }

        $session['pageContext'] = $pageContext;
        $session['history'][] = ['role' => 'user', 'message' => $message];
        $session['history'][] = ['role' => 'assistant', 'message' => $replyText];
        $session['history'] = array_slice($session['history'], -8);
        $this->storeSession($session['id'], $session);

        $debug = null;
        if (PragmaticWebToolkit::$plugin->chatbotSettings->get()->logLevel === 'debug' && Craft::$app->getUser()->getIsAdmin()) {
            $debug = [
                'entries' => $entries,
                'pageContext' => $pageContext,
                'siteContext' => $siteContext,
            ];
        }

        return [
            'message' => $replyText,
            'suggestedActions' => array_values(array_filter($actions)),
            'suggestedLinks' => $links,
            'citations' => array_values(array_filter($citations, static fn(array $citation): bool => $citation['url'] !== '')),
            'conversationState' => [
                'id' => $session['id'],
                'historyLength' => count($session['history']),
            ],
            'debug' => $debug,
        ];
    }

    private function buildReplyText(string $message, array $entries, array $pageContext): string
    {
        $siteSettings = PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings((int)Craft::$app->getSites()->getCurrentSite()->id);
        $currentTitle = trim((string)($pageContext['title'] ?? ''));

        if ($entries === []) {
            $fallback = $siteSettings->fallbackContactUrl !== ''
                ? ' I could not find a precise page, but I can still point you to contact.'
                : ' I could not find a precise page yet.';

            return 'I looked through the website content for "' . $message . '".' . $fallback;
        }

        $top = $entries[0];
        $topTitle = trim((string)($top['title'] ?? 'that page'));
        $section = trim((string)($top['section'] ?? ''));
        $sectionPart = $section !== '' ? ' in ' . $section : '';

        if ($currentTitle !== '' && stripos($message, 'this page') !== false) {
            return 'On this page, the most relevant section seems to be "' . $currentTitle . '". I also found related content that can take you deeper.';
        }

        return 'The closest match I found for "' . $message . '" is "' . $topTitle . '"' . $sectionPart . '. I can take you there or show you a couple of nearby options.';
    }

    private function buildEmptyReply(string $conversationId): array
    {
        $siteSettings = PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings((int)Craft::$app->getSites()->getCurrentSite()->id);

        return [
            'message' => $siteSettings->welcomeMessage,
            'suggestedActions' => [],
            'suggestedLinks' => [],
            'citations' => [],
            'conversationState' => ['id' => $conversationId],
            'debug' => null,
        ];
    }

    private function storeSession(string $conversationId, array $data): void
    {
        $sessions = $this->allSessions();
        $sessions[$conversationId] = $data;
        Craft::$app->getSession()->set(self::SESSION_KEY, Json::encode($sessions));
    }

    private function allSessions(): array
    {
        $raw = Craft::$app->getSession()->get(self::SESSION_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = Json::decodeIfJson($raw);
        return is_array($decoded) ? $decoded : [];
    }

    private function createConversationId(): string
    {
        return 'chatbot_' . bin2hex(random_bytes(8));
    }

    private function normalizeActions(array $candidate, array $fallback): array
    {
        $normalized = [];
        foreach ($candidate as $action) {
            if (!is_array($action)) {
                continue;
            }

            $sanitized = PragmaticWebToolkit::$plugin->chatbotActions->sanitizeAction($action);
            if ($sanitized) {
                $normalized[] = $sanitized;
            }
        }

        return $normalized !== [] ? $normalized : $fallback;
    }

    private function normalizeLinks(array $candidate, array $fallback): array
    {
        $normalized = [];
        foreach ($candidate as $link) {
            if (!is_array($link)) {
                continue;
            }

            $title = trim((string)($link['title'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }

            $normalized[] = [
                'title' => $title,
                'url' => $url,
                'section' => trim((string)($link['section'] ?? '')),
            ];
        }

        return $normalized !== [] ? $normalized : $fallback;
    }

    private function normalizeCitations(array $candidate, array $fallback): array
    {
        $normalized = [];
        foreach ($candidate as $citation) {
            if (!is_array($citation)) {
                continue;
            }

            $title = trim((string)($citation['title'] ?? ''));
            $url = trim((string)($citation['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }

            $normalized[] = [
                'title' => $title,
                'url' => $url,
                'section' => trim((string)($citation['section'] ?? '')),
            ];
        }

        return $normalized !== [] ? $normalized : $fallback;
    }
}
