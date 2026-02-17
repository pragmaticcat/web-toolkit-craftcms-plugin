<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\events\RegisterCpNavItemsEvent;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class NavService
{
    public function registerToolkitNav(RegisterCpNavItemsEvent $event): void
    {
        $label = Craft::t('pragmatic-web-toolkit', 'Pragmatic');
        $groupKey = 'pragmatic-web-toolkit';

        if (!isset($event->navItems[$groupKey])) {
            $event->navItems[$groupKey] = [
                'label' => $label,
                'url' => 'pragmatic-toolkit',
                'icon' => __DIR__ . '/../icon.svg',
                'subnav' => [],
            ];
        }

        foreach (PragmaticWebToolkit::$plugin->domains->enabled() as $provider) {
            $event->navItems[$groupKey]['subnav'][$provider::domainKey()] = [
                'label' => $provider::navLabel(),
                'url' => 'pragmatic-toolkit/' . $provider::cpSubpath(),
            ];
        }

        $path = Craft::$app->getRequest()->getPathInfo();
        if ($path === 'pragmatic-toolkit' || str_starts_with($path, 'pragmatic-toolkit/')) {
            $event->navItems[$groupKey]['url'] = 'pragmatic-toolkit';
        }
    }
}
