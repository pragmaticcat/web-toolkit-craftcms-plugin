<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\domains\chatbot\models\ChatbotConversationLogModel;
use yii\db\Query;
use yii\db\Schema;

class ChatbotHistoryService
{
    public const TABLE = '{{%pragmatic_toolkit_chatbot_conversations}}';

    public function startConversation(string $conversationId, array $pageContext = []): void
    {
        if (!$this->ensureTableReady()) {
            return;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $now = Db::prepareDateForDb(new \DateTime());
        $data = [
            'conversationId' => $conversationId,
            'siteId' => (int)$site->id,
            'language' => (string)$site->language,
            'pageUrl' => (string)($pageContext['url'] ?? ''),
            'pageTitle' => (string)($pageContext['title'] ?? ''),
            'messageCount' => 0,
            'transcriptJson' => Json::encode([]),
            'startedAt' => $now,
            'lastMessageAt' => $now,
        ];

        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();
    }

    public function appendExchange(string $conversationId, array $pageContext, string $userMessage, string $assistantMessage, array $history): void
    {
        if (!$this->ensureTableReady()) {
            return;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $now = Db::prepareDateForDb(new \DateTime());

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['conversationId' => $conversationId])
            ->one();

        $count = max(0, (int)($row['messageCount'] ?? 0)) + 1;

        $payload = [
            'siteId' => (int)$site->id,
            'language' => (string)$site->language,
            'pageUrl' => (string)($pageContext['url'] ?? ($row['pageUrl'] ?? '')),
            'pageTitle' => (string)($pageContext['title'] ?? ($row['pageTitle'] ?? '')),
            'messageCount' => $count,
            'latestUserMessage' => $userMessage,
            'latestAssistantMessage' => $assistantMessage,
            'transcriptJson' => Json::encode($history),
            'lastMessageAt' => $now,
        ];

        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
            'conversationId' => $conversationId,
            ...$payload,
            'startedAt' => $row['startedAt'] ?? $now,
            'dateCreated' => $row['dateCreated'] ?? $now,
            'dateUpdated' => $now,
            'uid' => $row['uid'] ?? StringHelper::UUID(),
        ], [
            ...$payload,
            'dateUpdated' => $now,
        ])->execute();
    }

    /**
     * @return ChatbotConversationLogModel[]
     */
    public function recent(?int $siteId = null, int $limit = 50): array
    {
        if (!$this->ensureTableReady()) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE)
            ->orderBy(['lastMessageAt' => SORT_DESC, 'dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($siteId) {
            $query->where(['siteId' => $siteId]);
        }

        return array_map(fn(array $row): ChatbotConversationLogModel => $this->toModel($row), $query->all());
    }

    public function getByConversationId(string $conversationId): ?ChatbotConversationLogModel
    {
        if ($conversationId === '' || !$this->ensureTableReady()) {
            return null;
        }

        $row = (new Query())->from(self::TABLE)->where(['conversationId' => $conversationId])->one();
        return is_array($row) ? $this->toModel($row) : null;
    }

    private function ensureTableReady(): bool
    {
        $db = Craft::$app->getDb();
        try {
            if (!$db->tableExists(self::TABLE)) {
                $db->createCommand()->createTable(self::TABLE, [
                    'id' => Schema::TYPE_PK,
                    'conversationId' => Schema::TYPE_STRING . ' NOT NULL',
                    'siteId' => Schema::TYPE_INTEGER,
                    'language' => Schema::TYPE_STRING . '(16)',
                    'pageUrl' => Schema::TYPE_TEXT,
                    'pageTitle' => Schema::TYPE_STRING,
                    'messageCount' => Schema::TYPE_INTEGER . ' NOT NULL DEFAULT 0',
                    'latestUserMessage' => Schema::TYPE_TEXT,
                    'latestAssistantMessage' => Schema::TYPE_TEXT,
                    'transcriptJson' => Schema::TYPE_TEXT,
                    'startedAt' => Schema::TYPE_DATETIME,
                    'lastMessageAt' => Schema::TYPE_DATETIME,
                    'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
                    'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
                    'uid' => Schema::TYPE_CHAR . '(36)',
                ])->execute();
                $db->createCommand()->createIndex('pwt_chatbot_conversation_unique', self::TABLE, ['conversationId'], true)->execute();
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function toModel(array $row): ChatbotConversationLogModel
    {
        $model = new ChatbotConversationLogModel();
        $model->id = (int)($row['id'] ?? 0);
        $model->conversationId = (string)($row['conversationId'] ?? '');
        $model->siteId = (int)($row['siteId'] ?? 0);
        $model->language = (string)($row['language'] ?? '');
        $model->pageUrl = (string)($row['pageUrl'] ?? '');
        $model->pageTitle = (string)($row['pageTitle'] ?? '');
        $model->messageCount = (int)($row['messageCount'] ?? 0);
        $model->latestUserMessage = (string)($row['latestUserMessage'] ?? '');
        $model->latestAssistantMessage = (string)($row['latestAssistantMessage'] ?? '');
        $model->startedAt = (string)($row['startedAt'] ?? '');
        $model->lastMessageAt = (string)($row['lastMessageAt'] ?? '');
        $decoded = Json::decodeIfJson((string)($row['transcriptJson'] ?? ''));
        $model->transcript = is_array($decoded) ? $decoded : [];
        return $model;
    }
}
