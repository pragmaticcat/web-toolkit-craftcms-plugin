<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260922_000010_remove_chatbot extends Migration
{
    public function safeUp(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_chatbot_runtime_logs}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_chatbot_conversations}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_chatbot_site_settings}}');

        foreach (['{{%pragmatic_toolkit_domain_config}}', '{{%pragmatic_toolkit_domain_settings}}'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->delete($table, ['domainKey' => 'chatbot']);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
