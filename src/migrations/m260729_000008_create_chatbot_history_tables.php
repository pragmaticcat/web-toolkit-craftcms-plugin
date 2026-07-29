<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260729_000008_create_chatbot_history_tables extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_chatbot_conversations}}')) {
            $this->createTable('{{%pragmatic_toolkit_chatbot_conversations}}', [
                'id' => $this->primaryKey(),
                'conversationId' => $this->string()->notNull(),
                'siteId' => $this->integer(),
                'language' => $this->string(16),
                'pageUrl' => $this->text(),
                'pageTitle' => $this->string(),
                'messageCount' => $this->integer()->notNull()->defaultValue(0),
                'latestUserMessage' => $this->text(),
                'latestAssistantMessage' => $this->text(),
                'transcriptJson' => $this->text(),
                'startedAt' => $this->dateTime(),
                'lastMessageAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_chatbot_conversation_unique', '{{%pragmatic_toolkit_chatbot_conversations}}', ['conversationId'], true);
            $this->createIndex('pwt_chatbot_conversation_site', '{{%pragmatic_toolkit_chatbot_conversations}}', ['siteId'], false);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_chatbot_runtime_logs}}')) {
            $this->createTable('{{%pragmatic_toolkit_chatbot_runtime_logs}}', [
                'id' => $this->primaryKey(),
                'conversationId' => $this->string(),
                'siteId' => $this->integer(),
                'level' => $this->string(16)->notNull(),
                'event' => $this->string(64)->notNull(),
                'message' => $this->text(),
                'contextJson' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_chatbot_runtime_logs_conversation', '{{%pragmatic_toolkit_chatbot_runtime_logs}}', ['conversationId'], false);
            $this->createIndex('pwt_chatbot_runtime_logs_level', '{{%pragmatic_toolkit_chatbot_runtime_logs}}', ['level'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_chatbot_runtime_logs}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_chatbot_conversations}}');
        return true;
    }
}
