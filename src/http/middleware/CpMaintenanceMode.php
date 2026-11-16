<?php

namespace pragmatic\webtoolkit\http\middleware;

use Closure;
use CraftCms\Cms\Support\Url;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mostra la pantalla de manteniment del panell quan `PWT_CP_MAINTENANCE_MODE` està activat.
 */
class CpMaintenanceMode
{
    private const ALLOWED_PATHS = ['login', 'logout', 'set-password', 'verify-email', 'update'];

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isCpRequest() || !$request->isMethod('GET') || $request->isActionRequest()) {
            return $next($request);
        }

        $path = trim($request->craftPath(), '/');
        if ($path === 'pragmatic-toolkit/maintenance' || in_array($path, self::ALLOWED_PATHS, true)) {
            return $next($request);
        }

        return redirect()->to(Url::cpUrl('pragmatic-toolkit/maintenance'));
    }
}
