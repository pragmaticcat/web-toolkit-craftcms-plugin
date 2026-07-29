<?php

namespace pragmatic\webtoolkit\domains\chatbot\models;

use craft\base\Model;

class ChatbotRuntimeLogModel extends Model
{
    public int $id = 0;
    public string $conversationId = '';
    public int $siteId = 0;
    public string $level = '';
    public string $event = '';
    public string $message = '';
    public string $createdAt = '';
    public array $context = [];
}
