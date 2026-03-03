<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use RuntimeException;
use yii\db\ColumnSchema;

class MysqlDumpService
{
    /**
     * @return array{engine:string,serverVersion:string,charset:string,collation:string,tableCount:int,rowCountEstimate:int,tables:array<int,string>,unsupportedObjects:array{views:array<int,string>,triggers:array<int,string>,routines:array<int,string>,events:array<int,string>},warnings:array<int,string>,dumpFormat:string}
     */
    public function dumpToFile(string $targetPath, int $insertBatchRowCount = 500, int $selectChunkSize = 1000, ?callable $progress = null): array
    {
        $db = Craft::$app->getDb();
        if ((string)$db->getDriverName() !== 'mysql') {
            throw new RuntimeException('Sync supports only MySQL or MariaDB databases.');
        }

        $inspection = \pragmatic\webtoolkit\PragmaticWebToolkit::$plugin->syncDatabaseInspector->inspectCurrentDatabase();
        $warnings = \pragmatic\webtoolkit\PragmaticWebToolkit::$plugin->syncDatabaseInspector->warningsForUnsupportedObjects($inspection['unsupportedObjects']);

        $handle = fopen($targetPath, 'wb');
        if (!$handle) {
            throw new RuntimeException('Unable to create the SQL dump file.');
        }

        try {
            $this->writeHeader($handle, $inspection);
            $tables = array_values($inspection['tables']);
            $totalTables = max(1, count($tables));

            foreach ($tables as $index => $tableName) {
                if ($progress) {
                    $progress('Dumping tables', ($index + 1) / $totalTables);
                }
                $this->dumpTable($handle, (string)$tableName, $insertBatchRowCount, $selectChunkSize);
            }

            fwrite($handle, "SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        return [
            'engine' => $inspection['engine'],
            'serverVersion' => $inspection['serverVersion'],
            'charset' => $inspection['charset'],
            'collation' => $inspection['collation'],
            'tableCount' => $inspection['tableCount'],
            'rowCountEstimate' => $inspection['rowCountEstimate'],
            'tables' => $inspection['tables'],
            'unsupportedObjects' => $inspection['unsupportedObjects'],
            'warnings' => $warnings,
            'dumpFormat' => 'pwt-mysql-tables-v1',
        ];
    }

    private function writeHeader($handle, array $info): void
    {
        $lines = [
            '-- PWT_SYNC_DB_FORMAT=pwt-mysql-tables-v1',
            '-- PWT_DB_ENGINE=' . $info['engine'],
            '-- PWT_DB_SERVER_VERSION=' . $info['serverVersion'],
            '-- PWT_TABLE_COUNT=' . $info['tableCount'],
            '-- PWT_TABLES=' . implode(',', $info['tables']),
            '-- PWT_UNSUPPORTED_VIEWS=' . implode(',', $info['unsupportedObjects']['views']),
            '-- PWT_UNSUPPORTED_TRIGGERS=' . implode(',', $info['unsupportedObjects']['triggers']),
            '-- PWT_UNSUPPORTED_ROUTINES=' . implode(',', $info['unsupportedObjects']['routines']),
            '-- PWT_UNSUPPORTED_EVENTS=' . implode(',', $info['unsupportedObjects']['events']),
            'SET FOREIGN_KEY_CHECKS=0;',
            'SET UNIQUE_CHECKS=0;',
            '',
        ];

        fwrite($handle, implode("\n", $lines) . "\n");
    }

    private function dumpTable($handle, string $tableName, int $insertBatchRowCount, int $selectChunkSize): void
    {
        $db = Craft::$app->getDb();
        $quotedTable = $db->quoteTableName($tableName);
        $createRow = $db->createCommand('SHOW CREATE TABLE ' . $quotedTable)->queryOne();
        if (!is_array($createRow) || !isset($createRow['Create Table'])) {
            throw new RuntimeException(sprintf('Unable to read table definition for %s.', $tableName));
        }

        fwrite($handle, sprintf("-- PWT_TABLE=%s\n", $tableName));
        fwrite($handle, sprintf("DROP TABLE IF EXISTS %s;\n", $quotedTable));
        fwrite($handle, $createRow['Create Table'] . ";\n");

        $tableSchema = $db->getSchema()->getTableSchema($tableName, true);
        if ($tableSchema === null) {
            throw new RuntimeException(sprintf('Unable to read schema for table %s.', $tableName));
        }

        $columnNames = array_keys($tableSchema->columns);
        if ($columnNames === []) {
            fwrite($handle, "\n");
            return;
        }

        $quotedColumns = array_map(fn(string $column): string => $db->quoteColumnName($column), $columnNames);
        $insertPrefix = sprintf("INSERT INTO %s (%s) VALUES ", $quotedTable, implode(', ', $quotedColumns));

        $command = $db->createCommand('SELECT * FROM ' . $quotedTable);
        $query = $command->query();
        $batch = [];
        $rowCounter = 0;

        while (($row = $query->read()) !== false) {
            $batch[] = '(' . implode(', ', $this->encodeRow((array)$row, $tableSchema->columns)) . ')';
            $rowCounter++;

            if (count($batch) >= $insertBatchRowCount) {
                fwrite($handle, $insertPrefix . implode(",\n", $batch) . ";\n");
                $batch = [];
            }

            if ($selectChunkSize > 0 && $rowCounter % $selectChunkSize === 0) {
                fflush($handle);
            }
        }

        $query->close();

        if ($batch !== []) {
            fwrite($handle, $insertPrefix . implode(",\n", $batch) . ";\n");
        }

        fwrite($handle, "\n");
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,ColumnSchema> $columns
     * @return string[]
     */
    private function encodeRow(array $row, array $columns): array
    {
        $values = [];
        $db = Craft::$app->getDb();

        foreach ($columns as $columnName => $column) {
            $value = $row[$columnName] ?? null;
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }

            if ($this->isBinaryColumn($column)) {
                $values[] = '0x' . bin2hex((string)$value);
                continue;
            }

            if ($this->isNumericColumn($column) && is_numeric($value)) {
                $values[] = (string)$value;
                continue;
            }

            $values[] = $db->quoteValue((string)$value);
        }

        return $values;
    }

    private function isBinaryColumn(ColumnSchema $column): bool
    {
        $dbType = strtolower((string)$column->dbType);
        return str_contains($dbType, 'blob') || str_contains($dbType, 'binary');
    }

    private function isNumericColumn(ColumnSchema $column): bool
    {
        return in_array($column->phpType, ['integer', 'double'], true);
    }
}
