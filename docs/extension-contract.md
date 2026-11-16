# Pragmatic Web Toolkit Extension Contract

Extension plugins should depend on `pragmatic/web-toolkit-craftcms-plugin` and register features on bootstrap.

## Registration Event
- Event class (Laravel event): `pragmatic\\webtoolkit\\events\\RegisterToolkitFeaturesEvent`
- Listen with `Event::listen(function (RegisterToolkitFeaturesEvent $event) { ... })` from your plugin's `boot()`.
- Add fully-qualified provider class names into `$event->providers`.

## Provider Requirements
Provider classes must implement `pragmatic\\webtoolkit\\interfaces\\FeatureProviderInterface`.

## Expectations
- Extensions must not override core services directly.
- Extensions should contribute routes/nav/permissions through provider methods.
- Core plugin remains installable without extensions.
