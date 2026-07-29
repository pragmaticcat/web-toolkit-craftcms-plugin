<?php

namespace pragmatic\webtoolkit\domains\chatbot\models;

use craft\base\Model;

class SiteChatbotSettingsModel extends Model
{
    public string $assistantName = 'Website Assistant';
    public string $welcomeMessage = 'Hi! I can help you find content on this website and take you to the right page.';
    public string $placeholderText = 'Ask about services, pages, or where to go next...';
    public string $popupTitle = 'Need help?';
    public string $launcherLabel = 'Need help?';
    public string $themePrimaryColor = '#0f766e';
    public string $themeBackgroundColor = '#ffffff';
    public string $themeTextColor = '#0f172a';
    public string $panelPosition = 'right';
    public string $displayMode = 'both';
    public int $borderRadius = 18;
    public bool $showLauncher = true;
    public bool $autoOpen = false;
    public array $allowedSections = [];
    public array $excludedSections = [];
    public array $emptyStatePrompts = [
        'What services do you offer?',
        'Show me your main pages',
        'Where should I start?',
    ];
    public string $fallbackContactUrl = '';
    public string $disclaimerText = 'Answers are based on the website content.';

    public function rules(): array
    {
        return [
            [['assistantName', 'welcomeMessage', 'placeholderText', 'popupTitle', 'launcherLabel', 'themePrimaryColor', 'themeBackgroundColor', 'themeTextColor', 'panelPosition', 'displayMode', 'fallbackContactUrl', 'disclaimerText'], 'string'],
            [['assistantName', 'placeholderText', 'popupTitle', 'launcherLabel'], 'required'],
            [['showLauncher', 'autoOpen'], 'boolean'],
            [['borderRadius'], 'integer', 'min' => 0, 'max' => 40],
            [['allowedSections', 'excludedSections', 'emptyStatePrompts'], 'safe'],
            [['panelPosition'], 'in', 'range' => ['left', 'right']],
            [['displayMode'], 'in', 'range' => ['popup', 'embed', 'both']],
            [['themePrimaryColor', 'themeBackgroundColor', 'themeTextColor'], 'match', 'pattern' => '/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
        ];
    }
}
