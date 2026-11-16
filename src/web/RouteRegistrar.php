<?php

namespace pragmatic\webtoolkit\web;

use Illuminate\Support\Facades\Route;

/**
 * Converteix les regles d’URL de Craft 5 (`'patró' => 'pragmatic-web-toolkit/controlador/acció'`)
 * en rutes de Laravel.
 */
class RouteRegistrar
{
    /**
     * @param array<string, string> $rules
     */
    public static function register(array $rules): void
    {
        foreach ($rules as $pattern => $route) {
            $methods = ['GET', 'HEAD', 'POST'];
            if (preg_match('/^(GET|POST|PUT|PATCH|DELETE)\s+(.+)$/', $pattern, $m)) {
                $methods = [$m[1]];
                $pattern = $m[2];
            }

            $wheres = [];
            $uri = preg_replace_callback('/<(\w+)(?::([^>]+))?>/', function (array $match) use (&$wheres): string {
                if (!empty($match[2])) {
                    $wheres[$match[1]] = $match[2];
                }

                return '{' . $match[1] . '}';
            }, $pattern);

            Route::match($methods, $uri, fn () => app(ActionDispatcher::class)->runRoute($route, request()->route()?->parameters() ?? []))
                ->where($wheres);
        }
    }
}
