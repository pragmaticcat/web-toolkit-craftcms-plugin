<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260930_000011_add_exclude_from_hreflang extends Migration
{
    private const TABLE = '{{%pragmatic_toolkit_seo_meta_site_settings}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE) && !$this->db->columnExists(self::TABLE, 'excludeFromHreflang')) {
            $this->addColumn(
                self::TABLE,
                'excludeFromHreflang',
                $this->boolean()->notNull()->defaultValue(false)
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE) && $this->db->columnExists(self::TABLE, 'excludeFromHreflang')) {
            $this->dropColumn(self::TABLE, 'excludeFromHreflang');
        }

        return true;
    }
}
