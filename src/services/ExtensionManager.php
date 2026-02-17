<?php

namespace pragmatic\webtoolkit\services;

use craft\base\Component;
use pragmatic\webtoolkit\events\RegisterToolkitFeaturesEvent;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;
use yii\base\Event;

class ExtensionManager extends Component
{
    public const EVENT_REGISTER_FEATURES = 'registerFeatures';

    public function discoverInstalledExtensions(): void
    {
        $event = new RegisterToolkitFeaturesEvent();
        Event::trigger(self::class, self::EVENT_REGISTER_FEATURES, $event);

        foreach ($event->providers as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }

            $provider = new $providerClass();
            if ($provider instanceof FeatureProviderInterface) {
                \pragmatic\webtoolkit\PragmaticWebToolkit::$plugin->domains->register($provider);
            }
        }
    }
}
