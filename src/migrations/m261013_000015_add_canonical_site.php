<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m261013_000015_add_canonical_site extends Migration
{
    private const TABLE = '{{%pragmatic_toolkit_seo_meta_site_settings}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE) && !$this->db->columnExists(self::TABLE, 'canonicalSiteId')) {
            $this->addColumn(self::TABLE, 'canonicalSiteId', $this->integer()->after('xDefaultSiteId'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE) && $this->db->columnExists(self::TABLE, 'canonicalSiteId')) {
            $this->dropColumn(self::TABLE, 'canonicalSiteId');
        }

        return true;
    }
}
