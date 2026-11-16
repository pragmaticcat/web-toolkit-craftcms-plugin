<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use pragmatic\webtoolkit\web\ActionDispatcher;

// Rutes d’acció de Craft 5: `actions/pragmatic-web-toolkit/{controlador}/{acció}`, i el
// paràmetre `action` dels formularis (`actionInput('pragmatic-web-toolkit/seo/save-general')`).
// Les crides signades del monitor no tenen sessió ni token CSRF.
Route::match(['GET', 'HEAD', 'POST'], 'monitor/{action}',
    fn (string $action) => app(ActionDispatcher::class)->run('monitor', $action))
    ->where('action', '[a-z0-9-]+')
    ->withoutMiddleware(PreventRequestForgery::class);

Route::match(['GET', 'HEAD', 'POST'], '{controller}/{action}', ActionDispatcher::class)
    ->where(['controller' => '[a-z0-9-]+', 'action' => '[a-z0-9-]+']);
