<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

/**
 * Lets editors mark a redirect as intended, so Pragmatic Web Monitor does not alert about it.
 */
class m261111_000017_add_monitor_expected_to_redirects extends Migration
{
    private const RULES = '{{%pragmatic_toolkit_redirects}}';

    /**
     * Add the column.
     *
     * @return bool Migration result.
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::RULES) && !$this->db->columnExists(self::RULES, 'monitorExpected')) {
            $this->addColumn(self::RULES, 'monitorExpected', $this->boolean()->notNull()->defaultValue(false)->after('preserveQueryString'));
        }
        return true;
    }

    /**
     * Drop the column.
     *
     * @return bool Migration result.
     */
    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::RULES) && $this->db->columnExists(self::RULES, 'monitorExpected')) {
            $this->dropColumn(self::RULES, 'monitorExpected');
        }
        return true;
    }
}
