<?php

namespace CraftCms\DependencyAwareCache\Facades;

use CraftCms\DependencyAwareCache\Dependency\Dependency;
use CraftCms\DependencyAwareCache\DependencyAwareRepository;
use Illuminate\Support\Facades\Cache;

/**
 * @method static DependencyAwareRepository store(string|null $name = null)
 * @method static DependencyAwareRepository driver(string|null $driver = null)
 * @method static DependencyAwareRepository memo(string|null $driver = null)
 * @method static DependencyAwareRepository resolve(string $name)
 * @method static DependencyAwareRepository build(array $config)
 * @method static DependencyAwareRepository repository(\Illuminate\Contracts\Cache\Store $store, array $config = [])
 * @method static bool put(array|string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null, Dependency|null $dependency = null)
 * @method static bool set(string $key, mixed $value, null|int|\DateInterval $ttl = null, Dependency|null $dependency = null)
 * @method static bool add(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null, Dependency|null $dependency = null)
 * @method static bool forever(string $key, mixed $value, Dependency|null $dependency = null)
 * @method static mixed remember(string $key, \Closure|\DateTimeInterface|\DateInterval|int|null $ttl, \Closure $callback, Dependency|null $dependency = null)
 * @method static mixed sear(string $key, \Closure $callback, Dependency|null $dependency = null)
 * @method static mixed rememberForever(string $key, \Closure $callback, Dependency|null $dependency = null)
 */
class DependencyCache extends Cache {}
