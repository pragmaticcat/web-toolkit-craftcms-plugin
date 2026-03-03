<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260303_000001_add_sync_transfer_logs extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%pragmatic_toolkit_sync_transfer_logs}}')) {
            return true;
        }

        $this->createTable('{{%pragmatic_toolkit_sync_transfer_logs}}', [
            'id' => $this->primaryKey(),
            'direction' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull(),
            'triggeredByUserId' => $this->integer(),
            'packageName' => $this->string()->notNull(),
            'packageSummaryJson' => $this->text(),
            'errorMessage' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex('pwt_sync_transfer_logs_status', '{{%pragmatic_toolkit_sync_transfer_logs}}', ['status']);
        $this->createIndex('pwt_sync_transfer_logs_direction', '{{%pragmatic_toolkit_sync_transfer_logs}}', ['direction']);
        $this->addForeignKey(
            'pwt_sync_transfer_logs_user_fk',
            '{{%pragmatic_toolkit_sync_transfer_logs}}',
            ['triggeredByUserId'],
            '{{%users}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_sync_transfer_logs}}');

        return true;
    }
}
