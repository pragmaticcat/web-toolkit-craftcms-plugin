<?php

namespace pragmatic\webtoolkit\domains\cookies\models;

use CraftCms\Cms\Component\Component;

class CookieModel extends Component
{
    public ?int $id = null;
    public ?int $categoryId = null;
    public string $name = '';
    public ?string $provider = null;
    public ?string $description = null;
    public ?string $duration = null;
    public bool $isRegex = false;
    public ?string $uid = null;

    public function getRules(): array
    {
        return [
            'name' => ['required'],
        ];
    }
}
