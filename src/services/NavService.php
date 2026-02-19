<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\events\RegisterCpNavItemsEvent;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class NavService
{
    public function registerToolkitNav(RegisterCpNavItemsEvent $event): void
    {
        $label = Craft::t('pragmatic-web-toolkit', 'Web Toolkit');
        $groupKey = 'pragmatic-web-toolkit';

        if (!isset($event->navItems[$groupKey])) {
            $newItem = [
                'label' => $label,
                'url' => 'pragmatic-toolkit/dashboard',
                'icon' => __DIR__ . '/../icon.svg',
                'subnav' => [],
            ];

            // Insert after the first matching nav item
            $afterKey = null;
            $insertAfter = ['users', 'assets', 'categories', 'entries'];
            foreach ($insertAfter as $target) {
                foreach ($event->navItems as $key => $item) {
                    if (($item['url'] ?? '') === $target) {
                        $afterKey = $key;
                        break 2;
                    }
                }
            }

            if ($afterKey !== null) {
                $pos = array_search($afterKey, array_keys($event->navItems)) + 1;
                $event->navItems = array_merge(
                    array_slice($event->navItems, 0, $pos, true),
                    [$groupKey => $newItem],
                    array_slice($event->navItems, $pos, null, true),
                );
            } else {
                $event->navItems[$groupKey] = $newItem;
            }
        }

        $event->navItems[$groupKey]['subnav'] = [
            'dashboard' => [
                'label' => Craft::t('pragmatic-web-toolkit', 'Dashboard'),
                'url' => 'pragmatic-toolkit/dashboard',
            ],
        ];

        foreach (PragmaticWebToolkit::$plugin->domains->enabled() as $provider) {
            $event->navItems[$groupKey]['subnav'][$provider::domainKey()] = [
                'label' => $provider::navLabel(),
                'url' => 'pragmatic-toolkit/' . $provider::cpSubpath(),
            ];
        }

        $path = Craft::$app->getRequest()->getPathInfo();
        if ($path === 'pragmatic-toolkit' || str_starts_with($path, 'pragmatic-toolkit/')) {
            $event->navItems[$groupKey]['url'] = 'pragmatic-toolkit/dashboard';
        }
    }
}
