<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m261013_000014_add_robots_txt extends Migration
{
    private const TABLE = '{{%pragmatic_toolkit_seo_meta_site_settings}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE) && !$this->db->columnExists(self::TABLE, 'robotsTxt')) {
            $this->addColumn(self::TABLE, 'robotsTxt', $this->text()->after('robots'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE) && $this->db->columnExists(self::TABLE, 'robotsTxt')) {
            $this->dropColumn(self::TABLE, 'robotsTxt');
        }

        return true;
    }
}
