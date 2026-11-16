<?php

namespace pragmatic\webtoolkit\http\middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirigeix les peticions del lloc a l’idioma preferit del visitant (domini Language redirect).
 */
class LanguageRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $redirect = PragmaticWebToolkit::$plugin->languageRedirect->handleCurrentRequest();
        $response = $redirect ?? $next($request);

        foreach (Cookie::getQueuedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
            Cookie::unqueue($cookie->getName(), $cookie->getPath());
        }

        return $response;
    }
}
