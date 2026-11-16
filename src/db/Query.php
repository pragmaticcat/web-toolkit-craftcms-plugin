<?php

namespace pragmatic\webtoolkit\db;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Constructor de consultes amb l’API que el plugin feia servir a Craft 5 (`yii\db\Query`),
 * implementat sobre el query builder de Laravel. Les files es retornen com a arrays.
 */
class Query
{
    private array $select = [];
    private bool $distinct = false;
    private string|array|null $from = null;
    /** @var array<int, array{0:string,1:mixed,2:array}> */
    private array $where = [];
    /** @var array<int, array{0:string,1:mixed,2:string,3:array}> */
    private array $joins = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $indexBy = null;

    public function select(string|array $columns): static
    {
        $this->select = is_array($columns) ? $columns : array_map('trim', explode(',', $columns));
        return $this;
    }

    public function addSelect(string|array $columns): static
    {
        $this->select = array_merge($this->select, is_array($columns) ? $columns : array_map('trim', explode(',', $columns)));
        return $this;
    }

    public function distinct(bool $value = true): static
    {
        $this->distinct = $value;
        return $this;
    }

    public function from(string|array $table): static
    {
        $this->from = $table;
        return $this;
    }

    public function where(mixed $condition, array $params = []): static
    {
        $this->where = [['and', $condition, $params]];
        return $this;
    }

    public function andWhere(mixed $condition, array $params = []): static
    {
        $this->where[] = ['and', $condition, $params];
        return $this;
    }

    public function orWhere(mixed $condition, array $params = []): static
    {
        $this->where[] = ['or', $condition, $params];
        return $this;
    }

    public function filterWhere(array $condition): static
    {
        return $this->where(array_filter($condition, fn ($v) => $v !== null && $v !== '' && $v !== []));
    }

    public function andFilterWhere(array $condition): static
    {
        return $this->andWhere(array_filter($condition, fn ($v) => $v !== null && $v !== '' && $v !== []));
    }

    public function innerJoin(string|array $table, mixed $on = '', array $params = []): static
    {
        $this->joins[] = ['inner', $table, $on, $params];
        return $this;
    }

    public function leftJoin(string|array $table, mixed $on = '', array $params = []): static
    {
        $this->joins[] = ['left', $table, $on, $params];
        return $this;
    }

    public function orderBy(string|array|null $columns): static
    {
        $this->orderBy = $this->normalizeOrder($columns);
        return $this;
    }

    public function addOrderBy(string|array $columns): static
    {
        $this->orderBy = array_merge($this->orderBy, $this->normalizeOrder($columns));
        return $this;
    }

    public function groupBy(string|array $columns): static
    {
        $this->groupBy = is_array($columns) ? $columns : array_map('trim', explode(',', $columns));
        return $this;
    }

    public function limit(?int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(?int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function indexBy(?string $column): static
    {
        $this->indexBy = $column;
        return $this;
    }

    public function toBuilder(): Builder
    {
        $query = DB::table(Sql::table($this->from ?? ''));

        if ($this->select !== []) {
            $columns = [];
            foreach ($this->select as $alias => $column) {
                if ($column instanceof \Illuminate\Contracts\Database\Query\Expression) {
                    $columns[] = is_string($alias) ? DB::raw($column->getValue($query->getGrammar()) . ' as ' . $query->getGrammar()->wrap($alias)) : $column;
                } elseif ($column instanceof Query) {
                    $columns[] = DB::raw('(' . $column->toBuilder()->toRawSql() . ')' . (is_string($alias) ? ' as ' . $query->getGrammar()->wrap($alias) : ''));
                } else {
                    $columns[] = is_string($alias) ? Sql::normalize((string)$column) . ' as ' . $alias : Sql::column($column);
                }
            }
            $query->select($columns);
        }

        if ($this->distinct) {
            $query->distinct();
        }

        foreach ($this->joins as [$type, $table, $on, $params]) {
            $query->join(Sql::table($table), function (JoinClause $join) use ($on, $params) {
                Sql::applyCondition($join, $on, $params);
            }, type: $type);
        }

        foreach ($this->where as [$boolean, $condition, $params]) {
            Sql::applyCondition($query, $condition, $params, $boolean);
        }

        foreach ($this->groupBy as $column) {
            $query->groupBy(Sql::column($column));
        }

        foreach ($this->orderBy as $column => $direction) {
            if (is_int($column)) {
                $query->orderByRaw(Sql::normalize((string)$direction));
            } else {
                $query->orderBy(Sql::column($column), $direction === SORT_DESC ? 'desc' : 'asc');
            }
        }

        if ($this->limit !== null && $this->limit >= 0) {
            $query->limit($this->limit);
        }
        if ($this->offset !== null && $this->offset > 0) {
            $query->offset($this->offset);
        }

        return $query;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->toBuilder()->get()->map(fn ($row) => (array)$row)->all();

        if ($this->indexBy !== null) {
            $indexed = [];
            foreach ($rows as $row) {
                $indexed[$row[$this->indexBy]] = $row;
            }
            return $indexed;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function one(): ?array
    {
        $row = $this->toBuilder()->first();

        return $row === null ? null : (array)$row;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function column(): array
    {
        $rows = $this->toBuilder()->get()->map(fn ($row) => (array)$row)->all();
        $result = [];
        foreach ($rows as $row) {
            $value = reset($row);
            if ($this->indexBy !== null) {
                $result[$row[$this->indexBy]] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    public function scalar(): mixed
    {
        $row = $this->toBuilder()->first();
        if ($row === null) {
            return false;
        }
        $row = (array)$row;

        return reset($row);
    }

    public function exists(): bool
    {
        return $this->toBuilder()->exists();
    }

    public function count(string $column = '*'): int
    {
        $builder = $this->toBuilder();
        $builder->orders = null;

        return (int)$builder->count($column === '*' ? '*' : Sql::column($column));
    }

    private function normalizeOrder(string|array|null $columns): array
    {
        if ($columns === null) {
            return [];
        }
        if (is_array($columns)) {
            return $columns;
        }

        $result = [];
        foreach (array_map('trim', explode(',', $columns)) as $part) {
            if (preg_match('/^([\w.\[\]]+)(?:\s+(asc|desc))?$/i', $part, $m)) {
                $result[str_replace(['[[', ']]'], '', $m[1])] = strtolower($m[2] ?? 'asc') === 'desc' ? SORT_DESC : SORT_ASC;
            } else {
                $result[] = $part;
            }
        }

        return $result;
    }
}
