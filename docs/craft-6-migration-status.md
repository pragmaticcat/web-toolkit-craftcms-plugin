# Craft 6 migration status

The `craft-6` branch targets Craft CMS 6 without the Yii2 adapter. The port is in progress and is not ready for deployment.

## Completed in this branch

- The plugin uses Craft 6's service provider, route files, Laravel migrations, and a Laravel-backed database layer.
- `composer.lock` resolves `craftcms/cms` 6.0.0-beta.1 and its dependencies. The repository tracks `vendor/`, so the installed dependency tree was updated as well.
- The base action dispatcher passes action IDs as strings to controllers and applies response headers set by those controllers.
- Analytics tracking uses Laravel requests, Symfony response cookies, and Laravel's unique-constraint exception.
- Monitor error reporting uses Laravel's `JobFailed` event and cache. Its health and queue endpoints use the Laravel database and queue worker.
- The URL resolver uses Laravel routes and Craft 6's template resolver.
- Email Tester uses Craft 6's system-message rendering actions and Laravel mail.

## Remaining work

- Port the remaining active controllers and services that still call `Craft::$app` or refer to `yii\*` and `craft\*` classes. SEO and Translations are the largest areas.
- Replace the obsolete Craft 5 fixtures and source assertions in the full PHPUnit suite.
- Run the plugin in a real Craft 6 project and verify installation, migrations, control-panel pages, frontend routes, queue jobs, and data upgrade from Craft 5.

## Verification constraints

- The local CLI is PHP 8.4.8; Craft 6 requires PHP 8.5. Craft 6 classes using PHP 8.5 syntax cannot be loaded or checked by PHPStan here.
- Source files passed `php -l` on PHP 8.4 after the syntax repair to Email Tester. Targeted unit tests pass, but the full suite still contains Craft 5 fixtures and fails before running.
