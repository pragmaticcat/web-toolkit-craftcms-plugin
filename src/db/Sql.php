<?php

namespace pragmatic\webtoolkit\db;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Traducció de condicions i noms en el format de Yii al query builder de Laravel.
 *
 * @internal
 */
final class Sql
{
    public static function normalize(string $sql): string
    {
        $sql = preg_replace('/\{\{%?([\w]+)\}\}/', '$1', $sql);

        return preg_replace_callback('/\[\[([\w.]+)\]\]/', function (array $m): string {
            return implode('.', array_map(fn (string $part) => '`' . $part . '`', explode('.', $m[1])));
        }, $sql);
    }

    /**
     * `'{{%taula}} t'` o `['t' => 'taula']` → `'taula as t'`.
     */
    public static function table(string|array $table): string|ExpressionContract
    {
        if (is_array($table)) {
            $alias = (string)array_key_first($table);
            $name = self::normalize((string)$table[$alias]);

            return is_int(array_key_first($table)) ? $name : "$name as $alias";
        }

        $table = trim(self::normalize($table));
        if (preg_match('/^([\w]+)\s+(?:as\s+)?([\w]+)$/i', $table, $m)) {
            return "{$m[1]} as {$m[2]}";
        }

        return $table;
    }

    public static function column(mixed $column): mixed
    {
        if (is_string($column)) {
            $column = self::normalize($column);
            if (preg_match('/^([\w.]+)\s+(?:as\s+)?([\w]+)$/i', $column, $m)) {
                return "{$m[1]} as {$m[2]}";
            }
            if (!preg_match('/^[\w.*]+$/', $column)) {
                return DB::raw($column);
            }
        }

        return $column;
    }

    /**
     * Aplica una condició en el format de Yii (hash, operador o SQL) al builder.
     */
    public static function applyCondition(Builder $query, mixed $condition, array $params = [], string $boolean = 'and'): void
    {
        if ($condition === null || $condition === [] || $condition === '') {
            return;
        }

        if (is_string($condition) || $condition instanceof ExpressionContract) {
            $sql = is_string($condition) ? self::normalize($condition) : $condition->getValue($query->getGrammar());
            [$sql, $bindings] = self::namedToPositional((string)$sql, $params);
            $query->whereRaw($sql, $bindings, $boolean);

            return;
        }

        if (!array_is_list($condition) || !isset($condition[0]) || !is_string($condition[0])) {
            // Condició hash
            $query->where(function (Builder $q) use ($condition) {
                foreach ($condition as $column => $value) {
                    $column = self::normalize((string)$column);
                    if ($value === null) {
                        $q->whereNull($column);
                    } elseif (is_array($value)) {
                        $q->whereIn($column, $value);
                    } elseif ($value instanceof Query) {
                        $q->whereIn($column, $value->toBuilder());
                    } else {
                        $q->where($column, '=', $value);
                    }
                }
            }, boolean: $boolean);

            return;
        }

        $operator = strtolower(trim($condition[0]));
        $operands = array_slice($condition, 1);

        switch ($operator) {
            case 'and':
            case 'or':
                $query->where(function (Builder $q) use ($operands, $operator, $params) {
                    foreach ($operands as $operand) {
                        self::applyCondition($q, $operand, $params, $operator);
                    }
                }, boolean: $boolean);
                return;
            case 'not':
                $query->whereNot(function (Builder $q) use ($operands, $params) {
                    self::applyCondition($q, $operands[0], $params);
                }, boolean: $boolean);
                return;
            case 'in':
            case 'not in':
                $values = $operands[1] instanceof Query ? $operands[1]->toBuilder() : (array)$operands[1];
                $query->whereIn(self::normalize((string)$operands[0]), $values, $boolean, $operator === 'not in');
                return;
            case 'between':
            case 'not between':
                $query->whereBetween(self::normalize((string)$operands[0]), [$operands[1], $operands[2]], $boolean, $operator === 'not between');
                return;
            case 'like':
            case 'not like':
            case 'or like':
            case 'or not like':
                $not = str_contains($operator, 'not');
                $values = (array)$operands[1];
                $escape = ($operands[2] ?? true) !== false;
                $query->where(function (Builder $q) use ($operands, $values, $not, $escape, $operator) {
                    foreach ($values as $value) {
                        $value = $escape ? '%' . addcslashes((string)$value, '%_\\') . '%' : (string)$value;
                        $q->where(self::normalize((string)$operands[0]), $not ? 'not like' : 'like', $value, str_starts_with($operator, 'or') ? 'or' : 'and');
                    }
                }, boolean: $boolean);
                return;
            case 'exists':
            case 'not exists':
                $query->whereExists($operands[0]->toBuilder(), $boolean, $operator === 'not exists');
                return;
            default:
                $value = $operands[1] ?? null;
                if ($value instanceof Query) {
                    $value = $value->toBuilder();
                }
                $query->where(self::column($operands[0]), $operator, $value, $boolean);
        }
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function namedToPositional(string $sql, array $params): array
    {
        if ($params === []) {
            return [$sql, []];
        }

        $bindings = [];
        $sql = preg_replace_callback('/:(\w+)/', function (array $m) use ($params, &$bindings) {
            $key = array_key_exists(':' . $m[1], $params) ? ':' . $m[1] : $m[1];
            if (!array_key_exists($key, $params)) {
                return $m[0];
            }
            $bindings[] = $params[$key];

            return '?';
        }, $sql);

        return [$sql, $bindings];
    }
}
