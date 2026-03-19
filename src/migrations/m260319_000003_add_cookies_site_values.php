<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;

class m260319_000003_add_cookies_site_values extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_cookies_cookie_site_values}}')) {
            $this->createTable('{{%pragmatic_toolkit_cookies_cookie_site_values}}', [
                'id' => $this->primaryKey(),
                'cookieId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'provider' => $this->string(),
                'description' => $this->text(),
                'duration' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(
                'pwt_cookies_cookie_site_values_cookie_site',
                '{{%pragmatic_toolkit_cookies_cookie_site_values}}',
                ['cookieId', 'siteId'],
                true
            );
            $this->createIndex(
                'pwt_cookies_cookie_site_values_site',
                '{{%pragmatic_toolkit_cookies_cookie_site_values}}',
                ['siteId']
            );
            $this->addForeignKey(
                'pwt_cookies_cookie_site_values_cookie_fk',
                '{{%pragmatic_toolkit_cookies_cookie_site_values}}',
                ['cookieId'],
                '{{%pragmatic_toolkit_cookies_cookies}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                'pwt_cookies_cookie_site_values_site_fk',
                '{{%pragmatic_toolkit_cookies_cookie_site_values}}',
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        $sites = (new Query())->select(['id'])->from('{{%sites}}')->all();
        $cookies = (new Query())->from('{{%pragmatic_toolkit_cookies_cookies}}')->all();

        if (empty($sites) || empty($cookies)) {
            return true;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        foreach ($sites as $site) {
            $siteId = (int)$site['id'];
            $rows = [];
            foreach ($cookies as $cookie) {
                $rows[] = [
                    'cookieId' => (int)$cookie['id'],
                    'siteId' => $siteId,
                    'name' => (string)$cookie['name'],
                    'provider' => $cookie['provider'],
                    'description' => $cookie['description'],
                    'duration' => $cookie['duration'],
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ];
            }

            if (!empty($rows)) {
                $this->batchInsert(
                    '{{%pragmatic_toolkit_cookies_cookie_site_values}}',
                    ['cookieId', 'siteId', 'name', 'provider', 'description', 'duration', 'dateCreated', 'dateUpdated', 'uid'],
                    $rows
                );
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_cookies_cookie_site_values}}');

        return true;
    }
}
