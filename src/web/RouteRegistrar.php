<?php

namespace pragmatic\webtoolkit\web;

use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Route;

/**
 * Converteix les regles d’URL de Craft 5 (`'patró' => 'pragmatic-web-toolkit/controlador/acció'`)
 * en rutes de Laravel.
 */
class RouteRegistrar
{
    /**
     * @param array<string, string> $rules
     * @param bool $siteRelative Si les regles són relatives al lloc, com a Craft 5: també es
     *                           registren sota el camí base de cada lloc (`es/robots.txt`).
     */
    public static function register(array $rules, bool $siteRelative = false): void
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

            if ($siteRelative) {
                Route::match($methods, '{pwtSitePath}/' . $uri, function () use ($route) {
                    $params = request()->route()?->parameters() ?? [];
                    abort_unless(self::isCurrentSitePath((string)($params['pwtSitePath'] ?? '')), 404);
                    unset($params['pwtSitePath']);

                    return app(ActionDispatcher::class)->runRoute($route, $params);
                })->where(['pwtSitePath' => '.+', ...$wheres]);
            }
        }
    }

    /**
     * Si el camí correspon al camí base del lloc actual (p. ex. `es` o `pragmatic/es`).
     */
    private static function isCurrentSitePath(string $path): bool
    {
        if (!Sites::getHasCurrentSite()) {
            return false;
        }

        $basePath = trim((string)parse_url((string)Sites::getCurrentSite()->getBaseUrl(), PHP_URL_PATH), '/');

        return $basePath !== '' && rawurldecode(trim($path, '/')) === rawurldecode($basePath);
    }
}
