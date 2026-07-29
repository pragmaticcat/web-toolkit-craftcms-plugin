<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use Craft;
use craft\helpers\Json;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ChatbotAiService
{
    private ?string $lastFailureReason = null;

    public function isConfigured(): bool
    {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        if (!$settings->useAi) {
            return false;
        }

        if ($settings->aiProvider !== 'openai') {
            return false;
        }

        return $this->resolveApiKey() !== '';
    }

    public function isEnabled(): bool
    {
        return PragmaticWebToolkit::$plugin->chatbotSettings->get()->useAi;
    }

    public function getLastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    public function generateReply(string $message, array $entries, array $pageContext, array $siteContext, array $sessionHistory = []): ?array
    {
        $this->lastFailureReason = null;

        if (!$this->isEnabled()) {
            $this->lastFailureReason = 'ai_disabled';
            return null;
        }

        if (!$this->isConfigured()) {
            $this->lastFailureReason = 'ai_not_configured';
            return null;
        }

        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $siteSettings = PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings((int)Craft::$app->getSites()->getCurrentSite()->id);

        $payload = $this->buildPayload($message, $entries, $pageContext, $siteContext, $sessionHistory, $siteSettings->disclaimerText, $settings->systemPrompt, false);

        $response = $this->request($payload, $settings->requestTimeout);
        if (!$response && $this->shouldRetryWithCompactPrompt()) {
            $retryTimeout = min(max($settings->requestTimeout + 15, 30), 120);
            $this->lastFailureReason = null;
            $compactPayload = $this->buildPayload($message, $entries, $pageContext, $siteContext, $sessionHistory, $siteSettings->disclaimerText, $settings->systemPrompt, true);
            $response = $this->request($compactPayload, $retryTimeout);
        }

        if (!$response) {
            if ($this->lastFailureReason === null) {
                $this->lastFailureReason = 'ai_request_failed';
            }
            return null;
        }

        $json = $this->extractResponseJson($response);
        if (!$json || !is_array($json)) {
            if ($this->lastFailureReason === null) {
                $this->lastFailureReason = 'ai_invalid_response';
            }
            return null;
        }

        return [
            'message' => trim((string)($json['message'] ?? '')),
            'suggestedActions' => is_array($json['suggestedActions'] ?? null) ? $json['suggestedActions'] : [],
            'suggestedLinks' => is_array($json['suggestedLinks'] ?? null) ? $json['suggestedLinks'] : [],
            'citations' => is_array($json['citations'] ?? null) ? $json['citations'] : [],
        ];
    }

    private function buildPayload(
        string $message,
        array $entries,
        array $pageContext,
        array $siteContext,
        array $sessionHistory,
        string $disclaimerText,
        string $systemPrompt,
        bool $compact
    ): array {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();

        return [
            'model' => $settings->model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->buildSystemPrompt($this->prepareSiteContext($siteContext), $disclaimerText, $systemPrompt),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->buildUserPrompt(
                                $message,
                                $this->prepareEntriesForPrompt($entries, $compact ? 3 : 6, $compact ? 280 : 700),
                                $this->preparePageContext($pageContext, $compact),
                                $this->prepareSiteContext($siteContext),
                                $sessionHistory,
                                $compact
                            ),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'chatbot_response',
                    'strict' => true,
                    'schema' => $this->responseSchema(),
                ],
            ],
        ];
    }

    private function request(array $payload, int $timeout): ?array
    {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $baseUrl = rtrim(trim($settings->apiBaseUrl), '/');
        $apiKey = $this->resolveApiKey();
        if ($baseUrl === '' || $apiKey === '') {
            $this->lastFailureReason = 'missing_api_configuration';
            return null;
        }

        $ch = curl_init($baseUrl . '/responses');
        if ($ch === false) {
            $this->lastFailureReason = 'curl_init_failed';
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => Json::encode($payload),
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            if ($error !== '') {
                Craft::warning('Chatbot AI request failed: ' . $error, __METHOD__);
                $this->lastFailureReason = 'curl_error:' . $error;
            } else {
                $this->lastFailureReason = 'empty_response';
            }
            return null;
        }

        $decoded = Json::decodeIfJson($raw);
        if (!is_array($decoded)) {
            Craft::warning('Chatbot AI response was not valid JSON.', __METHOD__);
            $this->lastFailureReason = 'response_not_json';
            return null;
        }

        if ($status < 200 || $status >= 300) {
            Craft::warning('Chatbot AI request returned HTTP ' . $status . ': ' . $raw, __METHOD__);
            $apiMessage = trim((string)($decoded['error']['message'] ?? ''));
            $this->lastFailureReason = $apiMessage !== ''
                ? 'http_' . $status . ':' . $apiMessage
                : 'http_' . $status;
            return null;
        }

        return $decoded;
    }

    private function shouldRetryWithCompactPrompt(): bool
    {
        return is_string($this->lastFailureReason)
            && str_starts_with($this->lastFailureReason, 'curl_error:Operation timed out');
    }

    private function resolveApiKey(): string
    {
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $stored = trim($settings->apiKey);
        if ($stored !== '') {
            return $stored;
        }

        $env = getenv('OPENAI_API_KEY');
        return is_string($env) ? trim($env) : '';
    }

    private function buildSystemPrompt(array $siteContext, string $disclaimerText, string $basePrompt): string
    {
        return trim($basePrompt . "\n\n"
            . 'You are a website assistant. Answer only from the provided website context. '
            . 'If context is weak, say so briefly and guide the visitor to the closest page. '
            . 'Keep answers concise and practical. '
            . 'Never invent pages or URLs. '
            . 'Suggested actions must be transparent and safe. '
            . 'Use the active site language when possible.' . "\n\n"
            . 'Site context: ' . Json::encode($siteContext) . "\n"
            . 'Disclaimer: ' . $disclaimerText);
    }

    private function buildUserPrompt(string $message, array $entries, array $pageContext, array $siteContext, array $sessionHistory, bool $compact = false): string
    {
        $trimmedHistory = array_slice($sessionHistory, $compact ? -3 : -6);

        return Json::encode([
            'visitorMessage' => $message,
            'pageContext' => $pageContext,
            'siteContext' => $siteContext,
            'recentHistory' => $trimmedHistory,
            'candidateEntries' => $entries,
            'instructions' => [
                'Return JSON only.',
                'Use the candidate entries to answer.',
                'Prefer 0-3 suggested links.',
                'Only cite URLs that exist in candidate entries.',
                'Only return safe action types that were already described by the site.',
                $compact ? 'Be extra concise.' : 'Keep the answer concise.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function prepareSiteContext(array $siteContext): array
    {
        return [
            'siteId' => $siteContext['siteId'] ?? null,
            'siteName' => $siteContext['siteName'] ?? '',
            'siteLanguage' => $siteContext['siteLanguage'] ?? '',
            'allowedSections' => array_slice(array_values((array)($siteContext['allowedSections'] ?? [])), 0, 10),
            'excludedSections' => array_slice(array_values((array)($siteContext['excludedSections'] ?? [])), 0, 10),
        ];
    }

    private function preparePageContext(array $pageContext, bool $compact): array
    {
        $limit = $compact ? 3 : 6;

        return [
            'url' => $this->truncateString((string)($pageContext['url'] ?? ''), 300),
            'title' => $this->truncateString((string)($pageContext['title'] ?? ''), 180),
            'sectionHandle' => $this->truncateString((string)($pageContext['sectionHandle'] ?? ''), 120),
            'entry' => $this->sanitizePromptValue($pageContext['entry'] ?? null, $compact ? 160 : 260, 6),
            'selectors' => array_slice((array)($pageContext['selectors'] ?? []), 0, $limit),
            'ctaLinks' => array_slice((array)($pageContext['ctaLinks'] ?? []), 0, $limit),
        ];
    }

    private function prepareEntriesForPrompt(array $entries, int $limit, int $stringLimit): array
    {
        $prepared = [];

        foreach (array_slice($entries, 0, $limit) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $prepared[] = [
                'id' => $entry['id'] ?? null,
                'title' => $this->truncateString((string)($entry['title'] ?? ''), 180),
                'slug' => $this->truncateString((string)($entry['slug'] ?? ''), 140),
                'url' => $this->truncateString((string)($entry['url'] ?? ''), 320),
                'section' => $this->truncateString((string)($entry['section'] ?? ''), 120),
                'sectionHandle' => $this->truncateString((string)($entry['sectionHandle'] ?? ''), 120),
                'type' => $this->truncateString((string)($entry['type'] ?? ''), 120),
                'typeHandle' => $this->truncateString((string)($entry['typeHandle'] ?? ''), 120),
                'summary' => $this->sanitizePromptValue($entry['summary'] ?? $entry['excerpt'] ?? null, $stringLimit, 4),
                'customFields' => $this->sanitizePromptValue($entry['customFields'] ?? null, $stringLimit, 8),
            ];
        }

        return $prepared;
    }

    private function sanitizePromptValue(mixed $value, int $stringLimit, int $itemLimit): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncateString($value, $stringLimit);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach (array_slice($value, 0, $itemLimit, true) as $key => $item) {
                $normalized[$key] = $this->sanitizePromptValue($item, $stringLimit, max(2, $itemLimit - 2));
            }
            return $normalized;
        }

        if (is_object($value)) {
            return $this->truncateString(Json::encode($value), $stringLimit);
        }

        return $this->truncateString((string)$value, $stringLimit);
    }

    private function truncateString(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $limit - 1))) . '…';
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'message' => ['type' => 'string'],
                'suggestedActions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'url' => ['type' => ['string', 'null']],
                            'selector' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['type', 'label', 'url', 'selector'],
                    ],
                ],
                'suggestedLinks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'section' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'url', 'section'],
                    ],
                ],
                'citations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'section' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'url', 'section'],
                    ],
                ],
            ],
            'required' => ['message', 'suggestedActions', 'suggestedLinks', 'citations'],
        ];
    }

    private function extractResponseJson(array $response): ?array
    {
        $candidates = [];

        if (isset($response['output_text']) && is_string($response['output_text'])) {
            $candidates[] = $response['output_text'];
        }

        foreach ((array)($response['output'] ?? []) as $outputItem) {
            foreach ((array)($outputItem['content'] ?? []) as $contentItem) {
                $text = $contentItem['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $candidates[] = $text;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $decoded = Json::decodeIfJson($candidate);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $this->lastFailureReason = 'no_json_payload_found';
        return null;
    }
}
