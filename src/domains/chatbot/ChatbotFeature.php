<?php

namespace pragmatic\webtoolkit\domains\chatbot;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class ChatbotFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'chatbot'; }
    public static function navLabel(): string { return 'Chatbot'; }
    public static function cpSubpath(): string { return 'chatbot'; }

    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/chatbot' => 'pragmatic-web-toolkit/chatbot/index',
            'pragmatic-toolkit/chatbot/general' => 'pragmatic-web-toolkit/chatbot/general',
            'pragmatic-toolkit/chatbot/options' => 'pragmatic-web-toolkit/chatbot/options',
            'pragmatic-toolkit/chatbot/preview' => 'pragmatic-web-toolkit/chatbot/preview',
        ];
    }

    public function siteRoutes(): array
    {
        return [
            'pragmatic-toolkit/chatbot/session' => 'pragmatic-web-toolkit/chatbot/session',
            'pragmatic-toolkit/chatbot/message' => 'pragmatic-web-toolkit/chatbot/message',
            'pragmatic-toolkit/chatbot/action-track' => 'pragmatic-web-toolkit/chatbot/action-track',
            'pragmatic-toolkit/chatbot/config' => 'pragmatic-web-toolkit/chatbot/config',
        ];
    }

    public function permissions(): array
    {
        return ['pragmatic-toolkit:chatbot' => ['label' => 'Manage Chatbot']];
    }

    public function injectFrontendHtml(string $html): string
    {
        return $html;
    }
}
