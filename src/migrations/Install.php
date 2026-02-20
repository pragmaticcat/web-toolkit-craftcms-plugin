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
        $this->createFaviconTables();
        $this->createSeoTables();
        $this->createTranslationsTables();
        $this->createAnalyticsTables();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_seo_sitemap_entrytypes}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_seo_blocks}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_seo_meta_site_settings}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_favicon_site_settings}}');

        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_category_site_values}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_site_settings}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_consent_logs}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_cookies}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_categories}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_analytics_daily_unique_visitors}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_analytics_page_daily_stats}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_analytics_daily_stats}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_translations_values}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_translations_keys}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_translations_groups}}');

        if ($this->db->tableExists('{{%pragmatic_toolkit_migration_log}}')) {
            $this->dropTable('{{%pragmatic_toolkit_migration_log}}');
        }

        return true;
    }

    private function createFaviconTables(): void
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_favicon_site_settings}}')) {
            $this->createTable('{{%pragmatic_toolkit_favicon_site_settings}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'autoGenerateManifest' => $this->boolean()->notNull()->defaultValue(true),
                'faviconIcoAssetId' => $this->integer(),
                'faviconSvgAssetId' => $this->integer(),
                'appleTouchIconAssetId' => $this->integer(),
                'maskIconAssetId' => $this->integer(),
                'maskIconColor' => $this->string(32)->notNull()->defaultValue('#000000'),
                'manifestAssetId' => $this->integer(),
                'themeColor' => $this->string(32)->notNull()->defaultValue('#ffffff'),
                'msTileColor' => $this->string(32)->notNull()->defaultValue('#ffffff'),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_favicon_site_unique', '{{%pragmatic_toolkit_favicon_site_settings}}', ['siteId'], true);
            $this->addForeignKey(
                'pwt_favicon_site_settings_site_fk',
                '{{%pragmatic_toolkit_favicon_site_settings}}',
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }
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

    private function createSeoTables(): void
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_seo_meta_site_settings}}')) {
            $this->createTable('{{%pragmatic_toolkit_seo_meta_site_settings}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'ogType' => $this->string(16)->notNull()->defaultValue('auto'),
                'robots' => $this->string(128),
                'googleSiteVerification' => $this->string(255),
                'twitterSite' => $this->string(64),
                'twitterCreator' => $this->string(64),
                'siteNameOverride' => $this->string(255),
                'enableHreflang' => $this->boolean()->notNull()->defaultValue(true),
                'xDefaultSiteId' => $this->integer(),
                'schemaMode' => $this->string(16)->notNull()->defaultValue('auto'),
                'enableArticleMeta' => $this->boolean()->notNull()->defaultValue(true),
                'includeImageMeta' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_seo_meta_site_unique', '{{%pragmatic_toolkit_seo_meta_site_settings}}', ['siteId'], true);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_seo_blocks}}')) {
            $this->createTable('{{%pragmatic_toolkit_seo_blocks}}', [
                'id' => $this->primaryKey(),
                'canonicalId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'fieldId' => $this->integer()->notNull(),
                'title' => $this->text(),
                'description' => $this->text(),
                'imageId' => $this->integer(),
                'imageDescription' => $this->text(),
                'sitemapEnabled' => $this->boolean(),
                'sitemapIncludeImages' => $this->boolean(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_seo_blocks_unique', '{{%pragmatic_toolkit_seo_blocks}}', ['canonicalId', 'siteId', 'fieldId'], true);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_seo_sitemap_entrytypes}}')) {
            $this->createTable('{{%pragmatic_toolkit_seo_sitemap_entrytypes}}', [
                'entryTypeId' => $this->integer()->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'includeImages' => $this->boolean()->notNull()->defaultValue(false),
            ]);
            $this->addPrimaryKey('pwt_seo_sitemap_entrytypes_pk', '{{%pragmatic_toolkit_seo_sitemap_entrytypes}}', ['entryTypeId']);
            $this->addForeignKey(
                'pwt_seo_sitemap_entrytypes_entrytype_fk',
                '{{%pragmatic_toolkit_seo_sitemap_entrytypes}}',
                ['entryTypeId'],
                '{{%entrytypes}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }
    }

    private function createTranslationsTables(): void
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_translations_groups}}')) {
            $this->createTable('{{%pragmatic_toolkit_translations_groups}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_translations_groups_name_unique', '{{%pragmatic_toolkit_translations_groups}}', ['name'], true);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_translations_keys}}')) {
            $this->createTable('{{%pragmatic_toolkit_translations_keys}}', [
                'id' => $this->primaryKey(),
                'key' => $this->string()->notNull(),
                'group' => $this->string()->notNull()->defaultValue('site'),
                'description' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_translations_keys_group_key_unique', '{{%pragmatic_toolkit_translations_keys}}', ['group', 'key'], true);
            $this->createIndex('pwt_translations_keys_key_idx', '{{%pragmatic_toolkit_translations_keys}}', ['key']);
            $this->createIndex('pwt_translations_keys_group_idx', '{{%pragmatic_toolkit_translations_keys}}', ['group']);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_translations_values}}')) {
            $this->createTable('{{%pragmatic_toolkit_translations_values}}', [
                'id' => $this->primaryKey(),
                'translationId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'value' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_translations_values_unique', '{{%pragmatic_toolkit_translations_values}}', ['translationId', 'siteId'], true);
            $this->addForeignKey(
                'pwt_translations_values_translation_fk',
                '{{%pragmatic_toolkit_translations_values}}',
                ['translationId'],
                '{{%pragmatic_toolkit_translations_keys}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                'pwt_translations_values_site_fk',
                '{{%pragmatic_toolkit_translations_values}}',
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        $hasSite = (new \craft\db\Query())
            ->from('{{%pragmatic_toolkit_translations_groups}}')
            ->where(['name' => 'site'])
            ->exists();

        if (!$hasSite) {
            $this->insert('{{%pragmatic_toolkit_translations_groups}}', ['name' => 'site', 'sortOrder' => 0]);
        }
    }

    private function createAnalyticsTables(): void
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_analytics_daily_stats}}')) {
            $this->createTable('{{%pragmatic_toolkit_analytics_daily_stats}}', [
                'date' => $this->date()->notNull(),
                'visits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'uniqueVisitors' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'PRIMARY KEY([[date]])',
            ]);
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_analytics_page_daily_stats}}')) {
            $this->createTable('{{%pragmatic_toolkit_analytics_page_daily_stats}}', [
                'date' => $this->date()->notNull(),
                'path' => $this->string(191)->notNull(),
                'visits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'PRIMARY KEY([[date]], [[path]])',
            ]);
            $this->createIndex(
                'pwt_analytics_page_daily_stats_path_idx',
                '{{%pragmatic_toolkit_analytics_page_daily_stats}}',
                ['path'],
                false
            );
        }

        if (!$this->db->tableExists('{{%pragmatic_toolkit_analytics_daily_unique_visitors}}')) {
            $this->createTable('{{%pragmatic_toolkit_analytics_daily_unique_visitors}}', [
                'date' => $this->date()->notNull(),
                'visitorHash' => $this->char(64)->notNull(),
                'PRIMARY KEY([[date]], [[visitorHash]])',
            ]);
        }
    }
}
