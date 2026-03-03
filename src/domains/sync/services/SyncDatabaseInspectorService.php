<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use RuntimeException;

class SyncDatabaseInspectorService
{
    /**
     * @return array{
     *   engine:string,
     *   serverVersion:string,
     *   charset:string,
     *   collation:string,
     *   databaseName:string,
     *   tableCount:int,
     *   rowCountEstimate:int,
     *   tables:array<int,string>,
     *   unsupportedObjects:array{views:array<int,string>,triggers:array<int,string>,routines:array<int,string>,events:array<int,string>}
     * }
     */
    public function inspectCurrentDatabase(): array
    {
        $db = Craft::$app->getDb();
        if ((string)$db->getDriverName() !== 'mysql') {
            throw new RuntimeException('Sync supports only MySQL or MariaDB databases.');
        }

        $databaseName = (string)$db->createCommand('SELECT DATABASE()')->queryScalar();
        if ($databaseName === '') {
            throw new RuntimeException('Unable to resolve the active database name.');
        }

        $serverVersion = (string)$db->createCommand('SELECT VERSION()')->queryScalar();
        $engine = stripos($serverVersion, 'mariadb') !== false ? 'mariadb' : 'mysql';

        $dbInfo = $db->createCommand(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS charsetName, DEFAULT_COLLATION_NAME AS collationName FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :schema',
            [':schema' => $databaseName]
        )->queryOne() ?: [];

        $tables = $db->createCommand(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = :type ORDER BY TABLE_NAME ASC',
            [':schema' => $databaseName, ':type' => 'BASE TABLE']
        )->queryColumn();

        $rowCountEstimate = (int)$db->createCommand(
            'SELECT COALESCE(SUM(TABLE_ROWS), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = :type',
            [':schema' => $databaseName, ':type' => 'BASE TABLE']
        )->queryScalar();

        return [
            'engine' => $engine,
            'serverVersion' => $serverVersion,
            'charset' => (string)($dbInfo['charsetName'] ?? ''),
            'collation' => (string)($dbInfo['collationName'] ?? ''),
            'databaseName' => $databaseName,
            'tableCount' => count($tables),
            'rowCountEstimate' => $rowCountEstimate,
            'tables' => array_map('strval', $tables),
            'unsupportedObjects' => [
                'views' => $this->queryNames('SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME ASC', $databaseName),
                'triggers' => $this->queryNames('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema ORDER BY TRIGGER_NAME ASC', $databaseName),
                'routines' => $this->queryNames('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema ORDER BY ROUTINE_NAME ASC', $databaseName),
                'events' => $this->queryNames('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = :schema ORDER BY EVENT_NAME ASC', $databaseName),
            ],
        ];
    }

    public function isMysqlCompatibleEngine(?string $engine): bool
    {
        return in_array(strtolower((string)$engine), ['mysql', 'mariadb'], true);
    }

    /**
     * @return string[]
     */
    public function warningsForUnsupportedObjects(array $unsupportedObjects): array
    {
        $warnings = [];
        foreach (['views', 'triggers', 'routines', 'events'] as $type) {
            $items = array_values(array_filter(array_map('strval', (array)($unsupportedObjects[$type] ?? []))));
            if ($items !== []) {
                $warnings[] = sprintf('Package excludes %s: %s', $type, implode(', ', $items));
            }
        }

        return $warnings;
    }

    /**
     * @return string[]
     */
    private function queryNames(string $sql, string $databaseName): array
    {
        return array_map(
            'strval',
            Craft::$app->getDb()->createCommand($sql, [':schema' => $databaseName])->queryColumn()
        );
    }
}
