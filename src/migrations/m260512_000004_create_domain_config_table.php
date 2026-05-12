<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260512_000004_create_domain_config_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_domain_config}}')) {
            $this->createTable('{{%pragmatic_toolkit_domain_config}}', [
                'id' => $this->primaryKey(),
                'domainKey' => $this->string()->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'sortOrder' => $this->integer()->notNull()->defaultValue(1),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(
                'pwt_domain_config_domain_key_unique',
                '{{%pragmatic_toolkit_domain_config}}',
                ['domainKey'],
                true
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_domain_config}}');
        return true;
    }
}
