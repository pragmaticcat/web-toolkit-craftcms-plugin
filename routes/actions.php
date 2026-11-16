<?php

use Illuminate\Support\Facades\Route;
use pragmatic\webtoolkit\web\ActionDispatcher;

// Rutes d’acció de Craft 5: `actions/pragmatic-web-toolkit/{controlador}/{acció}`, i el
// paràmetre `action` dels formularis (`actionInput('pragmatic-web-toolkit/seo/save-general')`).
Route::match(['GET', 'HEAD', 'POST'], '{controller}/{action}', ActionDispatcher::class)
    ->where(['controller' => '[a-z0-9-]+', 'action' => '[a-z0-9-]+']);
