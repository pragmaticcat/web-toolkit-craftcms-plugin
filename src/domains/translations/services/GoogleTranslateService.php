<?php

namespace pragmatic\webtoolkit\domains\translations\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class GoogleTranslateService extends Component
{
    public function translate(string $text, string $sourceLang, string $targetLang, string $mimeType): string
    {
        $apiKey = $this->resolveGoogleApiKey((string)PragmaticWebToolkit::$plugin->translationsSettings->get()->googleApiKeyEnv);
        if (!$apiKey) {
            throw new \RuntimeException('Google Translate API key is not configured.');
        }

        $results = $this->translateBatch([$text], $sourceLang, $targetLang, $mimeType);
        if (!isset($results[0])) {
            throw new \RuntimeException('Google Translate returned an empty response.');
        }

        return (string)$results[0];
    }

    public function translateBatch(array $texts, string $sourceLang, string $targetLang, string $mimeType): array
    {
        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        $projectId = trim($settings->googleProjectId);
        $location = trim($settings->googleLocation ?: 'global');
        $apiKey = $this->resolveGoogleApiKey((string)$settings->googleApiKeyEnv);

        if (!$apiKey) {
            throw new \RuntimeException('Google Translate API key is not configured.');
        }

        $lastError = null;
        if ($projectId !== '') {
            try {
                return $this->translateBatchViaV3($texts, $sourceLang, $targetLang, $mimeType, $apiKey, $projectId, $location);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        try {
            return $this->translateBatchViaV2($texts, $sourceLang, $targetLang, $apiKey);
        } catch (\Throwable $e) {
            if ($lastError) {
                throw new \RuntimeException($lastError->getMessage() . ' Fallback to v2 failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    private function translateBatchViaV3(
        array $texts,
        string $sourceLang,
        string $targetLang,
        string $mimeType,
        string $apiKey,
        string $projectId,
        string $location
    ): array {
        $url = sprintf(
            'https://translation.googleapis.com/language/translate/v3/projects/%s/locations/%s:translateText',
            urlencode($projectId),
            urlencode($location)
        );
        $client = Craft::createGuzzleClient();
        $response = $client->post($url, [
            'query' => ['key' => $apiKey],
            'json' => [
                'contents' => $texts,
                'mimeType' => $mimeType,
                'sourceLanguageCode' => $sourceLang,
                'targetLanguageCode' => $targetLang,
            ],
        ]);

        $payload = json_decode((string)$response->getBody(), true);
        $translations = $payload['translations'] ?? [];

        $results = [];
        foreach ($translations as $t) {
            $results[] = $t['translatedText'] ?? '';
        }

        return $results;
    }

    private function translateBatchViaV2(array $texts, string $sourceLang, string $targetLang, string $apiKey): array
    {
        $url = 'https://translation.googleapis.com/language/translate/v2';
        $client = Craft::createGuzzleClient();
        $response = $client->post($url, [
            'query' => ['key' => $apiKey],
            'form_params' => [
                'q' => $texts,
                'source' => $sourceLang,
                'target' => $targetLang,
                'format' => 'text',
            ],
        ]);

        $payload = json_decode((string)$response->getBody(), true);
        $translations = $payload['data']['translations'] ?? [];
        $results = [];
        foreach ($translations as $t) {
            $results[] = $t['translatedText'] ?? '';
        }

        if (count($results) === 0 && count($texts) > 0) {
            throw new \RuntimeException('Google Translate returned an empty response.');
        }

        return $results;
    }

    public function resolveLanguageCode(string $siteLanguage): string
    {
        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        $map = is_array($settings->languageMap) ? $settings->languageMap : [];

        return $map[$siteLanguage] ?? $siteLanguage;
    }

    private function resolveGoogleApiKey(string $envReference): string
    {
        $reference = trim($envReference);
        if ($reference === '') {
            return '';
        }

        $parsed = App::parseEnv($reference);
        if (is_string($parsed) && $parsed !== '' && $parsed !== $reference) {
            return trim($parsed);
        }

        $normalized = ltrim($reference, '$');
        $resolved = App::env($normalized);
        if (!is_string($resolved)) {
            return '';
        }

        return trim($resolved);
    }
}
