<?php

namespace CraftCms\DependencyAwareCache\Dependency;

use CraftCms\DependencyAwareCache\Events\TagsInvalidated;
use CraftCms\DependencyAwareCache\Facades\DependencyCache;
use Illuminate\Cache\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * TagDependency associates a cached value with one or multiple {@see TagDependency::$tags}.
 *
 * By calling {@see TagDependency::invalidate()}, you can invalidate all
 * cached values that are associated with the specified tag name(s).
 *
 * ```php
 * // setting multiple cache keys to store data forever and tagging them with "user-123"
 * $cache->getOrSet('user_42_profile', '', null, new TagDependency('user-123'));
 * $cache->getOrSet('user_42_stats', '', null, new TagDependency('user-123'));
 *
 *  // setting a cache key to store data and tagging them with "user-123" with the specified TTL for the tag
 * $cache->getOrSet('user_42_profile', '', null, new TagDependency('user-123', 3600));
 *
 * // invalidating all keys tagged with "user-123"
 * TagDependency::invalidate($cache, 'user-123');
 * ```
 */
class TagDependency extends Dependency
{
    /** @var string[] */
    public array $tags;

    public function __construct(
        array|string $tags = [],
        public ?int $ttl = null
    ) {
        $this->tags = Arr::wrap($tags);

        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException(
                'TTL must be a positive number or null, to invalidate tags, use the'
                .' static `\CraftCms\DependencyAwareCache\Dependency\TagDependency::invalidate()` method.',
            );
        }
    }

    protected function generateData(Repository $cache): array
    {
        if (empty($this->tags)) {
            return [];
        }

        $tags = $this->getTagsData($cache)
            ->map(fn ($time) => $time ?? microtime())
            ->all();

        $cache->setMultiple($tags, $this->ttl);

        return $tags;
    }

    public function isChanged(Repository $cache): bool
    {
        if (empty($this->tags)) {
            return $this->data !== [];
        }

        return $this->data !== $this->getTagsData($cache)->all();
    }

    /**
     * @param  string[]|string  $tags
     */
    public static function invalidate(array|string $tags): void
    {
        DependencyCache::store()->deleteMultiple(self::buildCacheKeys((array) $tags));

        Event::dispatch(new TagsInvalidated($tags));
    }

    private static function buildCacheKey(string $tag): string
    {
        $jsonTag = json_encode([self::class, $tag]);

        if ($jsonTag === false) {
            throw new InvalidArgumentException('Invalid tag. '.json_last_error_msg().'.');
        }

        return md5($jsonTag);
    }

    /**
     * @param  string[]  $tags
     * @return string[]
     */
    private static function buildCacheKeys(array $tags): array
    {
        return array_map([self::class, 'buildCacheKey'], $tags);
    }

    /**
     * @return Collection<array-key, string|null>
     */
    private function getTagsData(Repository $cache): Collection
    {
        return Collection::make($cache->getMultiple(self::buildCacheKeys($this->tags)));
    }
}
