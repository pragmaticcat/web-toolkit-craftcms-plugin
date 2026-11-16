<?php

namespace CraftCms\DependencyAwareCache;

use CraftCms\DependencyAwareCache\Dependency\AllDependencies;
use CraftCms\DependencyAwareCache\Dependency\AnyDependency;
use CraftCms\DependencyAwareCache\Dependency\CallbackDependency;
use CraftCms\DependencyAwareCache\Dependency\FileDependency;
use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use CraftCms\DependencyAwareCache\Dependency\ValueDependency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use Laravel\SerializableClosure\UnsignedSerializableClosure;

class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Register Laravel's service provider, that way ours runs after.
         */
        $this->app->register(\Illuminate\Cache\CacheServiceProvider::class);

        $this->app->singleton('cache', function ($app) {
            /** @phpstan-ignore larastan.octaneCompatibility */
            return new DependencyAwareCacheManager($app);
        });

        Cache::clearResolvedInstances();

        $this->registerSerializableClasses();
    }

    private function registerSerializableClasses(): void
    {
        $existing = $this->app['config']->get('cache.serializable_classes');

        if ($existing === null || $existing === true) {
            return;
        }

        $existing = is_array($existing) ? $existing : [];

        $this->app['config']->set('cache.serializable_classes', array_merge($existing, [
            AllDependencies::class,
            AnyDependency::class,
            CallbackDependency::class,
            FileDependency::class,
            TagDependency::class,
            ValueDependency::class,

            // Laravel serializable closure
            UnsignedSerializableClosure::class,
            SerializableClosure::class,
            Native::class,
            Signed::class,
        ]));
    }
}
