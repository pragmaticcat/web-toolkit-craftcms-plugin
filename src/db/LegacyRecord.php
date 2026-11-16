<?php

namespace pragmatic\webtoolkit\db;

/**
 * Mètodes estàtics de `craft\db\ActiveRecord` que el plugin feia servir, per als models Eloquent.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait LegacyRecord
{
    public static function tableName(): string
    {
        return (new static())->getTable();
    }

    public static function findOne(mixed $condition): ?static
    {
        if (is_array($condition)) {
            return static::query()->where($condition)->first();
        }

        return static::query()->find($condition);
    }
}
