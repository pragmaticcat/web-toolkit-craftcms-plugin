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

        $this->createCookiesTables();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_category_site_values}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_site_settings}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_consent_logs}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_cookies}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_categories}}');

        if ($this->db->tableExists('{{%pragmatic_toolkit_migration_log}}')) {
            $this->dropTable('{{%pragmatic_toolkit_migration_log}}');
        }

        return true;
    }

    private function createCookiesTables(): void
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_cookies_categories}}')) {
            $this->createTable('{{%pragmatic_toolkit_cookies_categories}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'description' => $this->text(),
                'isRequired' => $this->boolean()->notNull()->defaultValue(false),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%pragmatic_toolkit_cookies_categories}}', 'handle', true);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_cookies_cookies}}')) {
            $this->createTable('{{%pragmatic_toolkit_cookies_cookies}}', [
                'id' => $this->primaryKey(),
                'categoryId' => $this->integer(),
                'name' => $this->string()->notNull(),
                'provider' => $this->string(),
                'description' => $this->text(),
                'duration' => $this->string(),
                'isRegex' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%pragmatic_toolkit_cookies_cookies}}', 'categoryId');
            $this->addForeignKey(
                null,
                '{{%pragmatic_toolkit_cookies_cookies}}',
                'categoryId',
                '{{%pragmatic_toolkit_cookies_categories}}',
                'id',
                'SET NULL'
            );
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_cookies_consent_logs}}')) {
            $this->createTable('{{%pragmatic_toolkit_cookies_consent_logs}}', [
                'id' => $this->primaryKey(),
                'visitorId' => $this->string()->notNull(),
                'consent' => $this->text(),
                'ipAddress' => $this->string(),
                'userAgent' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%pragmatic_toolkit_cookies_consent_logs}}', 'visitorId');
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_cookies_site_settings}}')) {
            $this->createTable('{{%pragmatic_toolkit_cookies_site_settings}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'popupTitle' => $this->string()->notNull(),
                'popupDescription' => $this->text(),
                'acceptAllLabel' => $this->string()->notNull(),
                'rejectAllLabel' => $this->string()->notNull(),
                'savePreferencesLabel' => $this->string()->notNull(),
                'cookiePolicyUrl' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%pragmatic_toolkit_cookies_site_settings}}', 'siteId', true);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_cookies_category_site_values}}')) {
            $this->createTable('{{%pragmatic_toolkit_cookies_category_site_values}}', [
                'id' => $this->primaryKey(),
                'categoryId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'description' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%pragmatic_toolkit_cookies_category_site_values}}', ['categoryId', 'siteId'], true);
            $this->createIndex(null, '{{%pragmatic_toolkit_cookies_category_site_values}}', 'siteId');
            $this->addForeignKey(
                null,
                '{{%pragmatic_toolkit_cookies_category_site_values}}',
                'categoryId',
                '{{%pragmatic_toolkit_cookies_categories}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%pragmatic_toolkit_cookies_category_site_values}}',
                'siteId',
                '{{%sites}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
        }

        $existing = (new \craft\db\Query())
            ->from('{{%pragmatic_toolkit_cookies_categories}}')
            ->count('*');

        if ((int)$existing === 0) {
            $this->batchInsert('{{%pragmatic_toolkit_cookies_categories}}', ['name', 'handle', 'description', 'isRequired', 'sortOrder'], [
                ['Necessary', 'necessary', 'Essential cookies required for the website to function properly.', true, 1],
                ['Analytics', 'analytics', 'Cookies used to analyze website traffic and usage patterns.', false, 2],
                ['Marketing', 'marketing', 'Cookies used for advertising and tracking across websites.', false, 3],
                ['Preferences', 'preferences', 'Cookies that remember user preferences and settings.', false, 4],
            ]);
        }
    }
}
