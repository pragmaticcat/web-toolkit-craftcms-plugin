<?php

namespace pragmatic\webtoolkit\domains\chatbot\models;

use craft\base\Model;

class ChatbotSettingsModel extends Model
{
    public bool $enabled = true;
    public string $providerMode = 'mcp';
    public string $defaultLanguageStrategy = 'site';
    public int $maxContextItems = 3;
    public int $maxSuggestions = 3;
    public array $allowedActionTypes = ['open_url', 'scroll_to_selector', 'highlight_selector', 'open_cta', 'show_suggestions'];
    public string $systemPrompt = 'Help visitors find the most relevant content on this website. Keep answers short, clear, and grounded in the website content.';
    public array $emptyStatePrompts = [
        'What services do you offer?',
        'Can you take me to the right page?',
        'What should I read first?',
    ];
    public string $logLevel = 'errors';

    public function rules(): array
    {
        return [
            [['enabled'], 'boolean'],
            [['providerMode', 'defaultLanguageStrategy', 'systemPrompt', 'logLevel'], 'string'],
            [['maxContextItems', 'maxSuggestions'], 'integer', 'min' => 1, 'max' => 10],
            [['allowedActionTypes', 'emptyStatePrompts'], 'safe'],
            [['providerMode'], 'in', 'range' => ['mcp']],
            [['defaultLanguageStrategy'], 'in', 'range' => ['site', 'request']],
            [['logLevel'], 'in', 'range' => ['none', 'errors', 'debug']],
        ];
    }
}
