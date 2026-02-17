<?php

namespace pragmatic\webtoolkit\domains\cookies\models;

use craft\base\Model;

class CookieModel extends Model
{
    public ?int $id = null;
    public ?int $categoryId = null;
    public string $name = '';
    public ?string $provider = null;
    public ?string $description = null;
    public ?string $duration = null;
    public bool $isRegex = false;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['name'], 'required'],
        ];
    }
}
