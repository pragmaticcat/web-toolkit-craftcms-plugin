<?php

declare(strict_types=1);

namespace CraftCms\DependencyAwareCache\Events;

final readonly class TagsInvalidated
{
    public function __construct(
        public string|array $tags,
    ) {}
}
