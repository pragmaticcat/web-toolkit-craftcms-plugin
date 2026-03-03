<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use RuntimeException;

class MysqlRestoreService
{
    /**
     * @return array{executedStatements:int}
     */
    public function restoreFromFile(string $sqlPath, ?callable $progress = null): array
    {
        $db = Craft::$app->getDb();
        if ((string)$db->getDriverName() !== 'mysql') {
            throw new RuntimeException('Sync supports only MySQL or MariaDB databases.');
        }

        $handle = fopen($sqlPath, 'rb');
        if (!$handle) {
            throw new RuntimeException('Unable to open the staged SQL dump.');
        }

        $statement = '';
        $executedStatements = 0;
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inBlockComment = false;
        $escape = false;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = ltrim($line);
                if (!$inBlockComment && (str_starts_with($trimmed, '-- ') || str_starts_with($trimmed, '-- PWT_') || $trimmed === "\n" || $trimmed === "\r\n")) {
                    continue;
                }

                $length = strlen($line);
                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];
                    $next = $i + 1 < $length ? $line[$i + 1] : '';

                    if ($inBlockComment) {
                        if ($char === '*' && $next === '/') {
                            $inBlockComment = false;
                            $i++;
                        }
                        continue;
                    }

                    if (!$inSingle && !$inDouble && !$inBacktick && $char === '/' && $next === '*') {
                        $inBlockComment = true;
                        $i++;
                        continue;
                    }

                    if ($char === '\\' && ($inSingle || $inDouble)) {
                        $statement .= $char;
                        $escape = !$escape;
                        continue;
                    }

                    if ($char === "'" && !$inDouble && !$inBacktick && !$escape) {
                        $inSingle = !$inSingle;
                    } elseif ($char === '"' && !$inSingle && !$inBacktick && !$escape) {
                        $inDouble = !$inDouble;
                    } elseif ($char === '`' && !$inSingle && !$inDouble) {
                        $inBacktick = !$inBacktick;
                    }

                    $statement .= $char;

                    if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                        $sql = trim($statement);
                        if ($sql !== '') {
                            $db->createCommand($sql)->execute();
                            $executedStatements++;
                            if ($progress) {
                                $progress('Restoring database tables');
                            }
                        }
                        $statement = '';
                    }

                    if ($escape && $char !== '\\') {
                        $escape = false;
                    } elseif (!$escape) {
                        $escape = false;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if (trim($statement) !== '') {
            $db->createCommand(trim($statement))->execute();
            $executedStatements++;
        }

        return ['executedStatements' => $executedStatements];
    }
}
