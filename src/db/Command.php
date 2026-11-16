<?php

namespace pragmatic\webtoolkit\db;

use Illuminate\Support\Facades\DB;

/**
 * Ordres d’escriptura amb l’API de `yii\db\Command` que feia servir el plugin, sobre Laravel.
 * Cada mètode retorna una ordre pendent que s’executa amb `execute()`.
 */
class Command
{
    private ?\Closure $callback = null;

    public static function make(): self
    {
        return new self();
    }

    public function insert(string $table, array $columns): static
    {
        $this->callback = fn () => DB::table(Sql::table($table))->insert($columns) ? 1 : 0;
        return $this;
    }

    public function batchInsert(string $table, array $columns, array $rows): static
    {
        $this->callback = function () use ($table, $columns, $rows) {
            $records = array_map(fn (array $row) => array_combine($columns, array_values($row)), $rows);
            return $records === [] ? 0 : (DB::table(Sql::table($table))->insert($records) ? count($records) : 0);
        };
        return $this;
    }

    /**
     * @param array|bool $updateColumns Columnes a actualitzar si la fila ja existeix, `true` per a totes o `false` per a cap.
     */
    public function upsert(string $table, array $insertColumns, array|bool $updateColumns = true): static
    {
        $this->callback = function () use ($table, $insertColumns, $updateColumns) {
            $builder = DB::table(Sql::table($table));
            if ($updateColumns === false) {
                return $builder->insertOrIgnore($insertColumns);
            }
            $update = $updateColumns === true ? $insertColumns : $updateColumns;
            $grammar = $builder->getGrammar();
            $sets = [];
            $bindings = [];
            foreach ($update as $column => $value) {
                if ($value instanceof \Illuminate\Contracts\Database\Query\Expression) {
                    $sets[] = $grammar->wrap($column) . ' = ' . $value->getValue($grammar);
                } else {
                    $sets[] = $grammar->wrap($column) . ' = ?';
                    $bindings[] = $value;
                }
            }
            $columns = array_keys($insertColumns);
            $sql = sprintf(
                'insert into %s (%s) values (%s) on duplicate key update %s',
                $grammar->wrapTable(Sql::table($table)),
                implode(', ', array_map(fn ($c) => $grammar->wrap($c), $columns)),
                implode(', ', array_map(fn ($v) => $v instanceof \Illuminate\Contracts\Database\Query\Expression ? $v->getValue($grammar) : '?', $insertColumns)),
                implode(', ', $sets),
            );
            $values = array_values(array_filter($insertColumns, fn ($v) => !$v instanceof \Illuminate\Contracts\Database\Query\Expression));

            return DB::affectingStatement($sql, array_map(fn ($v) => is_bool($v) ? (int)$v : $v, [...$values, ...$bindings]));
        };
        return $this;
    }

    public function update(string $table, array $columns, mixed $condition = '', array $params = []): static
    {
        $this->callback = function () use ($table, $columns, $condition, $params) {
            $query = DB::table(Sql::table($table));
            Sql::applyCondition($query, $condition, $params);
            return $query->update($columns);
        };
        return $this;
    }

    public function delete(string $table, mixed $condition = '', array $params = []): static
    {
        $this->callback = function () use ($table, $condition, $params) {
            $query = DB::table(Sql::table($table));
            Sql::applyCondition($query, $condition, $params);
            return $query->delete();
        };
        return $this;
    }

    public function execute(): int
    {
        return (int)($this->callback)();
    }
}
