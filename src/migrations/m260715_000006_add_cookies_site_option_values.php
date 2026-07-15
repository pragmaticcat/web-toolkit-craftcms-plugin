<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260715_000006_add_cookies_site_option_values extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%pragmatic_toolkit_cookies_site_settings}}';
        if (!$this->db->tableExists($table)) {
            return true;
        }

        $columns = [
            'popupLayout' => $this->string(32)->notNull()->defaultValue('bar'),
            'popupPosition' => $this->string(32)->notNull()->defaultValue('bottom'),
            'primaryColor' => $this->string(32)->notNull()->defaultValue('#2563eb'),
            'backgroundColor' => $this->string(32)->notNull()->defaultValue('#ffffff'),
            'textColor' => $this->string(32)->notNull()->defaultValue('#1f2937'),
            'overlayEnabled' => $this->string(8)->notNull()->defaultValue('true'),
            'autoShowPopup' => $this->string(8)->notNull()->defaultValue('true'),
            'consentExpiry' => $this->string(16)->notNull()->defaultValue('365'),
            'logConsent' => $this->string(8)->notNull()->defaultValue('true'),
            'showPreferencesButton' => $this->string(8)->notNull()->defaultValue('true'),
            'preferencesButtonLabel' => $this->string()->notNull()->defaultValue('Cookie Settings'),
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->db->columnExists($table, $name)) {
                $this->addColumn($table, $name, $definition);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
