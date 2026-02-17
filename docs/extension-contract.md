# Pragmatic Web Toolkit Extension Contract

Premium plugins should depend on `pragmatic/web-toolkit-craftcms-plugin` and register features on bootstrap.

## Registration Event
- Event class: `pragmatic\\webtoolkit\\services\\ExtensionManager`
- Event name: `registerFeatures`
- Payload: `pragmatic\\webtoolkit\\events\\RegisterToolkitFeaturesEvent`
- Add fully-qualified provider class names into `$event->providers`.

## Provider Requirements
Provider classes must implement `pragmatic\\webtoolkit\\interfaces\\FeatureProviderInterface`.

## Expectations
- Extensions must not override core services directly.
- Extensions should contribute routes/nav/permissions through provider methods.
- Core plugin remains installable without premium extensions.
