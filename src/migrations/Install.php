<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_migration_log}}')) {
            $this->createTable('{{%pragmatic_toolkit_migration_log}}', [
                'id' => $this->primaryKey(),
                'domain' => $this->string()->notNull(),
                'status' => $this->string()->notNull(),
                'details' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%pragmatic_toolkit_migration_log}}')) {
            $this->dropTable('{{%pragmatic_toolkit_migration_log}}');
        }

        return true;
    }
}
