<?php

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\web\RouteRegistrar;

// Rutes públiques: Pragmatic Web Monitor, robots.txt, sitemaps, manifest, consentiment de cookies…
// Les rutes dels dominis desactivats responen 404 des d’ActionDispatcher.
RouteRegistrar::register([
    'pragmatic-web-toolkit/monitor/health' => 'pragmatic-web-toolkit/monitor/health',
    'pragmatic-web-toolkit/monitor/inventory' => 'pragmatic-web-toolkit/monitor/inventory',
    'POST pragmatic-web-toolkit/monitor/resolve' => 'pragmatic-web-toolkit/monitor/resolve',
    'POST pragmatic-web-toolkit/monitor/run-queue' => 'pragmatic-web-toolkit/monitor/run-queue',
]);

// Les rutes dels dominis són relatives al lloc, com a Craft 5: `/es/manifest.webmanifest`, `/pragmatic/sitemap.xml`…
RouteRegistrar::register(PragmaticWebToolkit::getInstance()->domains->siteRoutes(), siteRelative: true);
