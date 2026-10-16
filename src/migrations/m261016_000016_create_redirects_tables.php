<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

/**
 * Creates redirect rules and deduplicated 404 log tables.
 */
class m261016_000016_create_redirects_tables extends Migration
{
    /**
     * Create tables and indexes.
     *
     * @return bool Migration result.
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%pragmatic_toolkit_redirects}}')) {
            $this->createTable('{{%pragmatic_toolkit_redirects}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer(),
                'sourceUri' => $this->string(512)->notNull(),
                'matchType' => $this->string(16)->notNull()->defaultValue('exact'),
                'destination' => $this->text(),
                'destinationElementId' => $this->integer(),
                'statusCode' => $this->smallInteger()->notNull()->defaultValue(301),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'preserveQueryString' => $this->boolean()->notNull()->defaultValue(true),
                'notes' => $this->text(),
                'hitCount' => $this->bigInteger()->notNull()->defaultValue(0),
                'lastHitAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_redirect_exact_lookup', '{{%pragmatic_toolkit_redirects}}', ['matchType', 'sourceUri', 'siteId', 'enabled']);
            $this->createIndex('pwt_redirect_filters', '{{%pragmatic_toolkit_redirects}}', ['statusCode', 'matchType', 'enabled', 'hitCount']);
            $this->createIndex('pwt_redirect_destination_element', '{{%pragmatic_toolkit_redirects}}', ['destinationElementId']);
            $this->addForeignKey('pwt_redirect_site_fk', '{{%pragmatic_toolkit_redirects}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey('pwt_redirect_element_fk', '{{%pragmatic_toolkit_redirects}}', ['destinationElementId'], '{{%elements}}', ['id'], 'SET NULL', 'CASCADE');
        }
        if (!$this->db->tableExists('{{%pragmatic_toolkit_redirect_404s}}')) {
            $this->createTable('{{%pragmatic_toolkit_redirect_404s}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer(),
                'path' => $this->text()->notNull(),
                'siteKey' => $this->integer()->notNull()->defaultValue(0),
                'pathHash' => $this->char(64)->notNull(),
                'referrer' => $this->text(),
                'userAgent' => $this->text(),
                'hitCount' => $this->bigInteger()->notNull()->defaultValue(1),
                'firstSeenAt' => $this->dateTime()->notNull(),
                'lastSeenAt' => $this->dateTime()->notNull(),
                'handled' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('pwt_redirect_404_unique', '{{%pragmatic_toolkit_redirect_404s}}', ['siteKey', 'pathHash'], true);
            $this->createIndex('pwt_redirect_404_hits', '{{%pragmatic_toolkit_redirect_404s}}', ['hitCount', 'lastSeenAt']);
            $this->createIndex('pwt_redirect_404_retention', '{{%pragmatic_toolkit_redirect_404s}}', ['lastSeenAt']);
            $this->addForeignKey('pwt_redirect_404_site_fk', '{{%pragmatic_toolkit_redirect_404s}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
        }
        return true;
    }

    /**
     * Drop the Redirects tables.
     *
     * @return bool Migration result.
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_toolkit_redirect_404s}}');
        $this->dropTableIfExists('{{%pragmatic_toolkit_redirects}}');
        return true;
    }
}
