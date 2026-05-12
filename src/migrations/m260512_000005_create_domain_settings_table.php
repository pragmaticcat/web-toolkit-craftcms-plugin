<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260512_000005_create_domain_settings_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_domain_settings}}')) {
            $this->createTable('{{%pragmatic_toolkit_domain_settings}}', [
                'id' => $this->primaryKey(),
                'domainKey' => $this->string()->notNull(),
                'settingsJson' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(
                'pwt_domain_settings_domain_key_unique',
                '{{%pragmatic_toolkit_domain_settings}}',
                ['domainKey'],
                true
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_domain_settings}}');
        return true;
    }
}
