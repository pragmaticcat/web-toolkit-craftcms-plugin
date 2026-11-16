<?php

namespace pragmatic\webtoolkit\domains\redirects\services;

use Symfony\Component\HttpFoundation\Response;

/**
 * Prepares HTTP responses for matched redirect rules.
 */
class RedirectResponseHandler
{
    /**
     * Determine whether a destination resolves to the request's host and path.
     *
     * @param string $requestPath Full request path.
     * @param string $destination Final redirect destination.
     * @param string $requestHostInfo Request host information.
     * @return bool Whether the redirect would return to the same path on this host.
     */
    public static function isSameHostPath(string $requestPath, string $destination, string $requestHostInfo): bool
    {
        $requestHost = parse_url($requestHostInfo, PHP_URL_HOST);
        $destinationHost = parse_url($destination, PHP_URL_HOST);
        $destinationHost = is_string($destinationHost) ? $destinationHost : $requestHost;
        if (!is_string($requestHost) || !is_string($destinationHost) || strcasecmp($requestHost, $destinationHost) !== 0) {
            return false;
        }
        $requestPathOnly = parse_url($requestPath, PHP_URL_PATH) ?: '';
        $destinationPath = parse_url($destination, PHP_URL_PATH) ?: '';
        $normalize = static fn(string $path): string => mb_strtolower(rawurldecode(trim($path, '/')));
        return $normalize($requestPathOnly) === $normalize($destinationPath);
    }

    /**
     * Build the response for a matched rule.
     *
     * @param array<string, mixed> $match Matched redirect data.
     * @return Response Redirect, or 410 Gone.
     */
    public static function response(array $match): Response
    {
        $statusCode = (int)$match['statusCode'];
        if ($statusCode === 410) {
            return new Response('Gone', 410);
        }

        return redirect()->to((string)$match['destination'], $statusCode);
    }
}
