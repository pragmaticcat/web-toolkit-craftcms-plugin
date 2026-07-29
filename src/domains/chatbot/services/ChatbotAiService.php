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

        $payload = [
            'model' => $settings->model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->buildSystemPrompt($siteContext, $siteSettings->disclaimerText, $settings->systemPrompt),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->buildUserPrompt($message, $entries, $pageContext, $siteContext, $sessionHistory),
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

        $response = $this->request($payload, $settings->requestTimeout);
        if (!$response) {
            $this->lastFailureReason = 'ai_request_failed';
            return null;
        }

        $json = $this->extractResponseJson($response);
        if (!$json || !is_array($json)) {
            $this->lastFailureReason = 'ai_invalid_response';
            return null;
        }

        return [
            'message' => trim((string)($json['message'] ?? '')),
            'suggestedActions' => is_array($json['suggestedActions'] ?? null) ? $json['suggestedActions'] : [],
            'suggestedLinks' => is_array($json['suggestedLinks'] ?? null) ? $json['suggestedLinks'] : [],
            'citations' => is_array($json['citations'] ?? null) ? $json['citations'] : [],
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
            $this->lastFailureReason = 'http_' . $status;
            return null;
        }

        return $decoded;
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

    private function buildUserPrompt(string $message, array $entries, array $pageContext, array $siteContext, array $sessionHistory): string
    {
        $trimmedHistory = array_slice($sessionHistory, -6);

        return Json::encode([
            'visitorMessage' => $message,
            'pageContext' => $pageContext,
            'siteContext' => $siteContext,
            'recentHistory' => $trimmedHistory,
            'candidateEntries' => array_slice($entries, 0, 6),
            'instructions' => [
                'Return JSON only.',
                'Use the candidate entries to answer.',
                'Prefer 0-3 suggested links.',
                'Only cite URLs that exist in candidate entries.',
                'Only return safe action types that were already described by the site.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
                            'url' => ['type' => 'string'],
                            'selector' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'label'],
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
