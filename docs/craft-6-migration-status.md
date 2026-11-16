# Craft 6 migration status

The `craft-6` branch targets Craft CMS 6 without the Yii2 adapter. The port is in progress and is not ready for deployment.

## Completed in this branch

- The plugin uses Craft 6's service provider, route files, Laravel migrations, and a Laravel-backed database layer.
- `composer.lock` resolves `craftcms/cms` 6.0.0-beta.1 and its dependencies. The repository tracks `vendor/`, so the installed dependency tree was updated as well.
- The base action dispatcher passes action IDs as strings to controllers and applies response headers set by those controllers.
- Analytics tracking uses Laravel requests, Symfony response cookies, and Laravel's unique-constraint exception.
- The SEO controller uses Craft 6 asset services, upload handling, and HTTP responses; its field uses Craft 6 translation methods and validation rules.
- Monitor error reporting uses Laravel's `JobFailed` event and cache. Its health and queue endpoints use the Laravel database and queue worker.
- The URL resolver uses Laravel routes and Craft 6's template resolver.
- Email Tester uses Craft 6's system-message rendering actions and Laravel mail.
- Translation records use Laravel queries for bulk updates and deletes. Static translation import/export uses Laravel uploads and download responses.
- Content translation lists, section filters, and localized value lookup use Craft 6 entries. Legacy import rows for categories, tags, and global sets resolve by their migrated entry ID.
- Redirect tracking listens to Craft 6 element events and uses Laravel cache invalidation and termination callbacks.
- Favicon site-group saves now roll back their Laravel transaction when an exception occurs.
- Sync and MCP were removed from the plugin, including controllers, templates, settings, and new-install schema. Existing Sync tables are left untouched during upgrade and are only removed on plugin uninstall.

## Remaining work

- Verify content translation round trips against real Craft 5 data upgraded to Craft 6, particularly old category, tag, and global-set IDs and nested fields.
- Keep expanding behavior coverage for Craft 6 integration paths that currently depend on a real site and database.
- Run the plugin in a real Craft 6 project and verify installation, migrations, control-panel pages, frontend routes, queue jobs, and data upgrade from Craft 5.

## Verification

- The host CLI is PHP 8.4.8. Verification uses PHP 8.5.7 in the locally available `ddev/ddev-webserver:v1.25.3` image with the repository mounted read-only.
- The full PHP 8.5 unit suite passes (61 tests, 172 assertions), including Craft 6 entry-save events for automatic redirects, analytics rendering, and Symfony client-error filtering.
- PHPStan under PHP 8.5 passes for all plugin source files.
- Obsolete Craft 5-only fixtures and source assertions were removed or replaced with behavior tests. A real Craft 6 site has not yet been used for integration testing.
