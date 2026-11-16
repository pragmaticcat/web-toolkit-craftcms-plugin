# Aliases

[![Latest Version on Packagist](https://img.shields.io/packagist/v/craftcms/laravel-aliases.svg?style=flat-square)](https://packagist.org/packages/craftcms/laravel-aliases)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/craftcms/laravel-aliases/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/craftcms/laravel-aliases/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/craftcms/laravel-aliases/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/craftcms/laravel-aliases/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/craftcms/laravel-aliases.svg?style=flat-square)](https://packagist.org/packages/craftcms/laravel-aliases)

A Laravel wrapper around yiisoft/aliases

## Installation

You can install the package via composer:

```bash
composer require craftcms/laravel-aliases
```

## Usage

```php
use CraftCms\Aliases\Aliases;

Aliases::set('@root', __DIR__);
Aliases::set('@vendor', '@root/vendor');
Aliases::set('@bin', '@vendor/bin');

echo Aliases::get('@bin/phpunit'); // /path/to/vendor/bin/phpunit
```

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
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
