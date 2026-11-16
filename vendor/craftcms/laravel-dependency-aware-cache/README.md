# Dependency-aware cache for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/craftcms/laravel-dependency-aware-cache.svg?style=flat-square)](https://packagist.org/packages/craftcms/laravel-dependency-aware-cache)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/craftcms/laravel-dependency-aware-cache/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/craftcms/laravel-dependency-aware-cache/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/craftcms/laravel-dependency-aware-cache/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/craftcms/laravel-dependency-aware-cache/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/craftcms/laravel-dependency-aware-cache.svg?style=flat-square)](https://packagist.org/packages/craftcms/laravel-dependency-aware-cache)

An implementation of [Yii's cache with dependencies](https://github.com/yiisoft/cache) for Laravel

## Installation

You can install the package via composer:

```bash
composer require craftcms/laravel-dependency-aware-cache
```

## Usage

The dependency aware repository is hooked up automatically and will be returned when using the `Cache` Facade.

This package also provides a `\CraftCms\DependencyAwareCache\Facades\DependencyCache` Facade which extends the default `Cache` Facade but provides updated docblocks.

```php
use Illuminate\Support\Facades\Cache;
use CraftCms\DependencyAwareCache\DependencyAwareRepository;
use CraftCms\DependencyAwareCache\Facades\DependencyCache;

/** @var DependencyAwareRepository $cache */
$cache = Cache::store();

/** @var DependencyAwareRepository $cache */
$cache = DependencyCache::store();
```

### Invalidation

When using the cache, you can specify a dependency that may trigger cache invalidation. Below is an example using the tag dependency:

```php
use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use Illuminate\Support\Facades\Cache;

Cache::put('item_42_price', 10, null, new TagDependency('item_42'));
Cache::put('item_42_total', 100, null, new TagDependency('item_42'));

Cache::get('item_42_price'); // 10
Cache::get('item_42_total'); // 100

TagDependency::invalidate('item_42');

Cache::get('item_42_price'); // null
Cache::get('item_42_total'); // null
```

Other dependencies:

- `CallbackDependency` - invalidates when the result of a callback changes.
- `FileDependency` - invalidates the cache based on file modification time.
- `ValueDependency` - invalidates the cache when specified value changes.

- You may combine multiple dependencies using `AnyDependency` or `AllDependencies`.

You can implement your own dependency by extending `Dependency`.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Pixel & Tonic](https://github.com/craftcms)
- [Yii Software](https://www.yiiframework.com/)
- [All Contributors](../../contributors)

## License

Please see [License File](LICENSE.md) for more information.
