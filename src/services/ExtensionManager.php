<?php

namespace pragmatic\webtoolkit\services;

use pragmatic\webtoolkit\events\RegisterToolkitFeaturesEvent;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ExtensionManager
{
    public function discoverInstalledExtensions(): void
    {
        event($event = new RegisterToolkitFeaturesEvent());

        foreach ($event->providers as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }

            $provider = new $providerClass();
            if ($provider instanceof FeatureProviderInterface) {
                PragmaticWebToolkit::$plugin->domains->register($provider);
            }
        }
    }
}
