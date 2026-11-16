<?php

namespace CraftCms\DependencyAwareCache;

use Closure;
use CraftCms\DependencyAwareCache\Dependency\Dependency;
use Illuminate\Cache\Repository;

class DependencyAwareRepository extends Repository
{
    public function get($key, $default = null): mixed
    {
        $value = parent::get($key, $default);

        return $this->checkAndGetValue($value);
    }

    public function many(array $keys): array
    {
        $values = [];

        foreach (parent::many($keys) as $key => $value) {
            $values[$key] = $this->checkAndGetValue($value);
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     *
     * @param  Dependency|null  $dependency
     */
    public function put($key, $value, $ttl = null, $dependency = null)
    {
        if ($dependency instanceof Dependency) {
            $dependency->evaluate($this);

            $value = [$value, $dependency];
        }

        return parent::put($key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     *
     * @param  Dependency|null  $dependency
     */
    public function set($key, $value, $ttl = null, $dependency = null): bool
    {
        return $this->put($key, $value, $ttl, $dependency);
    }

    public function add($key, $value, $ttl = null, $dependency = null)
    {
        if ($dependency instanceof Dependency) {
            $dependency->evaluate($this);

            $value = [$value, $dependency];
        }

        return parent::add($key, $value, $ttl);
    }

    public function forever($key, $value, $dependency = null)
    {
        if ($dependency instanceof Dependency) {
            $dependency->evaluate($this);

            $value = [$value, $dependency];
        }

        return parent::forever($key, $value);
    }

    public function remember($key, $ttl, Closure $callback, $dependency = null)
    {
        $value = $this->get($key);

        // If the item exists in the cache we will just return this immediately and if
        // not we will execute the given Closure and cache the result of that for a
        // given number of seconds so it's available for all subsequent requests.
        if (! is_null($value)) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, value($ttl, $value), $dependency);

        return $value;
    }

    public function sear($key, Closure $callback, $dependency = null)
    {
        return $this->rememberForever($key, $callback, $dependency);
    }

    public function rememberForever($key, Closure $callback, $dependency = null)
    {
        $value = $this->get($key);

        // If the item exists in the cache we will just return this immediately
        // and if not we will execute the given Closure and cache the result
        // of that forever so it is available for all subsequent requests.
        if (! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback(), $dependency);

        return $value;
    }

    /**
     * Checks if the cache dependency has expired and returns a value.
     *
     * @param  mixed  $value  The value of this item in the cache.
     * @return mixed The cache value or `null` if the dependency has been changed.
     */
    private function checkAndGetValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value[1]) && $value[1] instanceof Dependency) {
            [$value, $dependency] = $value;

            /** @var Dependency $dependency */
            if ($dependency->isChanged($this)) {
                return null;
            }
        }

        return $value;
    }
}
