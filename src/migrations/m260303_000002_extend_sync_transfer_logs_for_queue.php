<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;

class m260303_000002_extend_sync_transfer_logs_for_queue extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%pragmatic_toolkit_sync_transfer_logs}}';
        if (!$this->db->tableExists($table)) {
            return true;
        }

        $this->addColumnIfMissing($table, 'jobId', $this->integer());
        $this->addColumnIfMissing($table, 'packageManifestJson', $this->text());
        $this->addColumnIfMissing($table, 'warningJson', $this->text());
        $this->addColumnIfMissing($table, 'artifactPath', $this->text());
        $this->addColumnIfMissing($table, 'artifactFilename', $this->string());
        $this->addColumnIfMissing($table, 'artifactExpiresAt', $this->dateTime());
        $this->addColumnIfMissing($table, 'progressLabel', $this->string(255));
        $this->addColumnIfMissing($table, 'startedAt', $this->dateTime());
        $this->addColumnIfMissing($table, 'finishedAt', $this->dateTime());

        $this->createIndexIfMissing('pwt_sync_transfer_logs_job', $table, ['jobId']);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }

    private function addColumnIfMissing(string $table, string $column, mixed $type): void
    {
        $schema = $this->db->getTableSchema($table, true);
        if (!isset($schema->columns[$column])) {
            $this->addColumn($table, $column, $type);
        }
    }

    private function createIndexIfMissing(string $name, string $table, array $columns): void
    {
        try {
            $this->createIndex($name, $table, $columns);
        } catch (\Throwable) {
            return;
        }
    }
}
