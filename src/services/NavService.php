<?php

namespace pragmatic\webtoolkit\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;

use function CraftCms\Cms\t;

class NavService
{
    /**
     * Element del menú del panell de control, amb un subelement per a cada domini activat.
     *
     * @return array<string, mixed>
     */
    public function navItem(): array
    {
        $subnav = [
            'dashboard' => [
                'label' => t('services.nav-service.dashboard', [], 'pragmatic-web-toolkit'),
                'url' => 'pragmatic-toolkit/dashboard',
            ],
        ];

        foreach (PragmaticWebToolkit::$plugin->domains->enabled() as $provider) {
            $subnav[$provider::domainKey()] = [
                'label' => $provider::navLabel(),
                'url' => 'pragmatic-toolkit/' . $provider::cpSubpath(),
            ];
        }

        return [
            'label' => t('services.nav-service.web-toolkit', [], 'pragmatic-web-toolkit'),
            'url' => 'pragmatic-toolkit',
            'icon' => dirname(__DIR__) . '/icon.svg',
            'subnav' => $subnav,
        ];
    }
}
