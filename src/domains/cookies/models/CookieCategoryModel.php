<?php

namespace pragmatic\webtoolkit\domains\cookies\models;

use CraftCms\Cms\Component\Component;

class CookieCategoryModel extends Component
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public bool $isRequired = false;
    public int $sortOrder = 0;
    public ?string $uid = null;

    public function getRules(): array
    {
        return [
            'name' => ['required'],
            'handle' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
        ];
    }
}
