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
        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        $projectId = trim($settings->googleProjectId);
        $location = trim($settings->googleLocation ?: 'global');
        $apiKey = App::env($settings->googleApiKeyEnv);

        if ($projectId === '') {
            throw new \RuntimeException('Google Translate project ID is not configured.');
        }
        if (!$apiKey) {
            throw new \RuntimeException('Google Translate API key is not configured.');
        }

        $url = sprintf(
            'https://translation.googleapis.com/language/translate/v3/projects/%s/locations/%s:translateText',
            urlencode($projectId),
            urlencode($location)
        );

        $client = Craft::createGuzzleClient();
        $response = $client->post($url, [
            'query' => ['key' => $apiKey],
            'json' => [
                'contents' => [$text],
                'mimeType' => $mimeType,
                'sourceLanguageCode' => $sourceLang,
                'targetLanguageCode' => $targetLang,
            ],
        ]);

        $payload = json_decode((string)$response->getBody(), true);
        $translation = $payload['translations'][0]['translatedText'] ?? null;
        if ($translation === null) {
            throw new \RuntimeException('Google Translate returned an empty response.');
        }

        return $translation;
    }

    public function translateBatch(array $texts, string $sourceLang, string $targetLang, string $mimeType): array
    {
        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        $projectId = trim($settings->googleProjectId);
        $location = trim($settings->googleLocation ?: 'global');
        $apiKey = App::env($settings->googleApiKeyEnv);

        if ($projectId === '') {
            throw new \RuntimeException('Google Translate project ID is not configured.');
        }
        if (!$apiKey) {
            throw new \RuntimeException('Google Translate API key is not configured.');
        }

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

    public function resolveLanguageCode(string $siteLanguage): string
    {
        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        $map = is_array($settings->languageMap) ? $settings->languageMap : [];

        return $map[$siteLanguage] ?? $siteLanguage;
    }
}
