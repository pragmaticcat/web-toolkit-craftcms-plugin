<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260729_000007_create_chatbot_site_settings_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_chatbot_site_settings}}')) {
            $this->createTable('{{%pragmatic_toolkit_chatbot_site_settings}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'assistantName' => $this->string()->notNull(),
                'welcomeMessage' => $this->text(),
                'placeholderText' => $this->string()->notNull(),
                'popupTitle' => $this->string()->notNull(),
                'launcherLabel' => $this->string()->notNull(),
                'themePrimaryColor' => $this->string(32)->notNull()->defaultValue('#0f766e'),
                'themeBackgroundColor' => $this->string(32)->notNull()->defaultValue('#ffffff'),
                'themeTextColor' => $this->string(32)->notNull()->defaultValue('#0f172a'),
                'panelPosition' => $this->string(32)->notNull()->defaultValue('right'),
                'displayMode' => $this->string(32)->notNull()->defaultValue('both'),
                'borderRadius' => $this->integer()->notNull()->defaultValue(18),
                'showLauncher' => $this->boolean()->notNull()->defaultValue(true),
                'autoOpen' => $this->boolean()->notNull()->defaultValue(false),
                'allowedSections' => $this->text(),
                'excludedSections' => $this->text(),
                'emptyStatePrompts' => $this->text(),
                'fallbackContactUrl' => $this->string(),
                'disclaimerText' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex('pwt_chatbot_site_unique', '{{%pragmatic_toolkit_chatbot_site_settings}}', ['siteId'], true);
            $this->addForeignKey(
                'pwt_chatbot_site_settings_site_fk',
                '{{%pragmatic_toolkit_chatbot_site_settings}}',
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_chatbot_site_settings}}');

        return true;
    }
}
