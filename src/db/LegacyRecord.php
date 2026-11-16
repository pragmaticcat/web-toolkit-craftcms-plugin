<?php

namespace pragmatic\webtoolkit\db;

use Illuminate\Database\Eloquent\Builder;

/**
 * Mètodes estàtics de `craft\db\ActiveRecord` que el plugin feia servir, per als models Eloquent.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait LegacyRecord
{
    public static function tableName(): string
    {
        return static::query()->getModel()->getTable();
    }

    public static function findOne(mixed $condition): ?static
    {
        if (is_array($condition)) {
            return static::applyConditions(static::query(), $condition)->first();
        }

        return static::query()->find($condition);
    }

    public static function updateAll(array $attributes, array $condition): int
    {
        return static::applyConditions(static::query(), $condition)->update($attributes);
    }

    public static function deleteAll(array $condition): int
    {
        return static::applyConditions(static::query(), $condition)->delete();
    }

    private static function applyConditions(Builder $query, array $condition): Builder
    {
        foreach ($condition as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } elseif ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        return $query;
    }
}
