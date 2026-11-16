<?php

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\web\RouteRegistrar;

// Pantalles del panell de control (`/admin/pragmatic-toolkit/...`).
// Les rutes dels dominis desactivats responen 404 des d’ActionDispatcher.
RouteRegistrar::register([
    'pragmatic-toolkit/maintenance' => 'pragmatic-web-toolkit/maintenance/index',
    'pragmatic-toolkit' => 'pragmatic-web-toolkit/dashboard/index',
    'pragmatic-toolkit/dashboard' => 'pragmatic-web-toolkit/dashboard/index',
    'pragmatic-toolkit/dashboard/configuration' => 'pragmatic-web-toolkit/dashboard/configuration',
    ...PragmaticWebToolkit::getInstance()->domains->cpRoutes(),
]);
