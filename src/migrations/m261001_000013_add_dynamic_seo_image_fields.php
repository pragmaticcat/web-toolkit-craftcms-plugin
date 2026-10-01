<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m261001_000013_add_dynamic_seo_image_fields extends Migration
{
    public function safeUp(): bool
    {
        $blocks = '{{%pragmatic_toolkit_seo_blocks}}';
        if ($this->db->tableExists($blocks) && !$this->db->columnExists($blocks, 'imageFieldHandle')) {
            $this->addColumn($blocks, 'imageFieldHandle', $this->string(255));
        }

        $sections = '{{%pragmatic_toolkit_seo_meta_section_settings}}';
        if ($this->db->tableExists($sections) && !$this->db->columnExists($sections, 'defaultSiteImageFieldHandle')) {
            $this->addColumn($sections, 'defaultSiteImageFieldHandle', $this->string(255));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $blocks = '{{%pragmatic_toolkit_seo_blocks}}';
        if ($this->db->tableExists($blocks) && $this->db->columnExists($blocks, 'imageFieldHandle')) {
            $this->dropColumn($blocks, 'imageFieldHandle');
        }

        $sections = '{{%pragmatic_toolkit_seo_meta_section_settings}}';
        if ($this->db->tableExists($sections) && $this->db->columnExists($sections, 'defaultSiteImageFieldHandle')) {
            $this->dropColumn($sections, 'defaultSiteImageFieldHandle');
        }

        return true;
    }
}
