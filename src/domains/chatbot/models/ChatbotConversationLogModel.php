<?php

namespace pragmatic\webtoolkit\domains\chatbot\models;

use craft\base\Model;

class ChatbotConversationLogModel extends Model
{
    public int $id = 0;
    public string $conversationId = '';
    public int $siteId = 0;
    public string $language = '';
    public string $pageUrl = '';
    public string $pageTitle = '';
    public int $messageCount = 0;
    public string $latestUserMessage = '';
    public string $latestAssistantMessage = '';
    public string $startedAt = '';
    public string $lastMessageAt = '';
    public array $transcript = [];
}
