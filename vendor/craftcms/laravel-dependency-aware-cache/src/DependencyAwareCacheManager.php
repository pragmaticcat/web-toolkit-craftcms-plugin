<?php

namespace CraftCms\DependencyAwareCache;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Arr;

class DependencyAwareCacheManager extends CacheManager
{
    /**
     * Create a new cache repository with the given implementation.
     *
     * @return DependencyAwareRepository
     */
    public function repository(Store $store, array $config = [])
    {
        return tap(new DependencyAwareRepository($store, Arr::only($config, ['store'])), function ($repository) use ($config) {
            if ($config['events'] ?? true) {
                $this->setEventDispatcher($repository);
            }
        });
    }
}
