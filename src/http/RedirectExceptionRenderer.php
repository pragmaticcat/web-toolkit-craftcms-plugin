<?php

namespace pragmatic\webtoolkit\http;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use pragmatic\webtoolkit\domains\redirects\services\RedirectResponseHandler;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Quan una petició del lloc acaba en 404, aplica la regla de redirecció que hi coincideix
 * o desa el 404 al registre (domini Redirects).
 */
class RedirectExceptionRenderer
{
    public function render(Request $request): ?Response
    {
        try {
            $plugin = PragmaticWebToolkit::$plugin;
            if ($plugin === null || $request->isCpRequest() || !$plugin->domains->isEnabled('redirects')) {
                return null;
            }

            $siteId = Sites::getHasCurrentSite() ? (int)Sites::getCurrentSite()->id : null;
            $path = ltrim(rawurldecode($request->getPathInfo()), '/');
            $referrer = $request->headers->get('referer');
            $userAgent = $request->userAgent();

            $match = $plugin->redirects->match($path, $siteId, $this->queryStringWithoutPath($request));
            if ($match === null) {
                $plugin->redirects->record404($path, $siteId, $referrer, $userAgent);
                return null;
            }

            $preview = $plugin->redirects->test($path, $siteId);
            $finalDestination = (string)($preview['finalDestination'] ?? $match['destination']);
            if ((int)$match['statusCode'] !== 410 && RedirectResponseHandler::isSameHostPath($path, $finalDestination, $request->getSchemeAndHttpHost())) {
                $plugin->redirects->record404($path, $siteId, $referrer, $userAgent);
                return null;
            }

            return RedirectResponseHandler::response($match);
        } catch (Throwable) {
            // Si alguna cosa falla, Craft mostra el 404 habitual.
            return null;
        }
    }

    private function queryStringWithoutPath(Request $request): string
    {
        $pathParam = (string)(Cms::config()->pathParam ?? 'p');
        $query = $request->query();
        unset($query[$pathParam]);

        return http_build_query($query);
    }
}
