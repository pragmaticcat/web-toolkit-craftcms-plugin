<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\domains\chatbot\models\ChatbotRuntimeLogModel;
use yii\db\Query;
use yii\db\Schema;

class ChatbotLogService
{
    public const TABLE = '{{%pragmatic_toolkit_chatbot_runtime_logs}}';

    public function info(string $event, string $message, array $context = [], ?string $conversationId = null): void
    {
        $this->write('info', $event, $message, $context, $conversationId);
    }

    public function error(string $event, string $message, array $context = [], ?string $conversationId = null): void
    {
        $this->write('error', $event, $message, $context, $conversationId);
    }

    public function debug(string $event, string $message, array $context = [], ?string $conversationId = null): void
    {
        $settings = \pragmatic\webtoolkit\PragmaticWebToolkit::$plugin->chatbotSettings->get();
        if ($settings->logLevel !== 'debug') {
            return;
        }
        $this->write('debug', $event, $message, $context, $conversationId);
    }

    /**
     * @return ChatbotRuntimeLogModel[]
     */
    public function recent(?int $siteId = null, int $limit = 100): array
    {
        if (!$this->ensureTableReady()) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($siteId) {
            $query->where(['siteId' => $siteId]);
        }

        return array_map(fn(array $row): ChatbotRuntimeLogModel => $this->toModel($row), $query->all());
    }

    private function write(string $level, string $event, string $message, array $context, ?string $conversationId): void
    {
        if (!$this->ensureTableReady()) {
            return;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $now = Db::prepareDateForDb(new \DateTime());

        try {
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'conversationId' => $conversationId,
                'siteId' => (int)$site->id,
                'level' => $level,
                'event' => $event,
                'message' => $message,
                'contextJson' => Json::encode($context),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable) {
        }
    }

    private function ensureTableReady(): bool
    {
        $db = Craft::$app->getDb();
        try {
            if (!$db->tableExists(self::TABLE)) {
                $db->createCommand()->createTable(self::TABLE, [
                    'id' => Schema::TYPE_PK,
                    'conversationId' => Schema::TYPE_STRING,
                    'siteId' => Schema::TYPE_INTEGER,
                    'level' => Schema::TYPE_STRING . '(16) NOT NULL',
                    'event' => Schema::TYPE_STRING . '(64) NOT NULL',
                    'message' => Schema::TYPE_TEXT,
                    'contextJson' => Schema::TYPE_TEXT,
                    'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
                    'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
                    'uid' => Schema::TYPE_CHAR . '(36)',
                ])->execute();
                $db->createCommand()->createIndex('pwt_chatbot_runtime_logs_conversation', self::TABLE, ['conversationId'])->execute();
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function toModel(array $row): ChatbotRuntimeLogModel
    {
        $model = new ChatbotRuntimeLogModel();
        $model->id = (int)($row['id'] ?? 0);
        $model->conversationId = (string)($row['conversationId'] ?? '');
        $model->siteId = (int)($row['siteId'] ?? 0);
        $model->level = (string)($row['level'] ?? '');
        $model->event = (string)($row['event'] ?? '');
        $model->message = (string)($row['message'] ?? '');
        $model->createdAt = (string)($row['dateCreated'] ?? '');
        $decoded = Json::decodeIfJson((string)($row['contextJson'] ?? ''));
        $model->context = is_array($decoded) ? $decoded : [];
        return $model;
    }
}
