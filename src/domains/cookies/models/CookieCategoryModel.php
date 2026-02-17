<?php

namespace pragmatic\webtoolkit\domains\cookies\models;

use craft\base\Model;

class CookieCategoryModel extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public bool $isRequired = false;
    public int $sortOrder = 0;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/'],
        ];
    }
}
