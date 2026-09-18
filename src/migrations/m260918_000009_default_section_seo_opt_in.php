<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260918_000009_default_section_seo_opt_in extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%pragmatic_toolkit_seo_blocks}}';
        if ($this->db->tableExists($table) && $this->db->columnExists($table, 'useSectionSeo')) {
            $this->alterColumn($table, 'useSectionSeo', $this->boolean()->notNull()->defaultValue(false));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%pragmatic_toolkit_seo_blocks}}';
        if ($this->db->tableExists($table) && $this->db->columnExists($table, 'useSectionSeo')) {
            $this->alterColumn($table, 'useSectionSeo', $this->boolean()->notNull()->defaultValue(true));
        }

        return true;
    }
}
