<?php

namespace pragmatic\webtoolkit\services;

use craft\events\RegisterUrlRulesEvent;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class RouteService
{
    public function registerCpRoutes(RegisterUrlRulesEvent $event): void
    {
        $event->rules['pragmatic-toolkit'] = 'pragmatic-web-toolkit/dashboard/index';
        $event->rules['pragmatic-toolkit/dashboard'] = 'pragmatic-web-toolkit/dashboard/index';
        $event->rules['pragmatic-toolkit/dashboard/configuration'] = 'pragmatic-web-toolkit/dashboard/configuration';

        foreach (PragmaticWebToolkit::$plugin->domains->cpRoutes() as $pattern => $action) {
            $event->rules[$pattern] = $action;
        }
    }

    public function registerSiteRoutes(RegisterUrlRulesEvent $event): void
    {
        foreach (PragmaticWebToolkit::$plugin->domains->siteRoutes() as $pattern => $action) {
            $event->rules[$pattern] = $action;
        }
    }
}
