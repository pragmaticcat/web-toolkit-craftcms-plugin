<?php

namespace pragmatic\premiumexample;

use craft\base\Plugin;
use pragmatic\webtoolkit\events\RegisterToolkitFeaturesEvent;
use pragmatic\webtoolkit\services\ExtensionManager;
use yii\base\Event;

class PremiumExtensionPlugin extends Plugin
{
    public function init(): void
    {
        parent::init();

        Event::on(
            ExtensionManager::class,
            ExtensionManager::EVENT_REGISTER_FEATURES,
            static function (RegisterToolkitFeaturesEvent $event) {
                $event->providers[] = providers\ExamplePremiumFeature::class;
            }
        );
    }
}
