<?php

namespace pragmatic\webtoolkit\domains\redirects\services;

use craft\web\Response;

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
     * Apply the matched rule's status and destination to a response.
     *
     * @param Response $response Response to prepare.
     * @param array<string, mixed> $match Matched redirect data.
     * @return void
     */
    public static function apply(Response $response, array $match): void
    {
        $statusCode = (int)$match['statusCode'];
        if ($statusCode === 410) {
            $response->setStatusCode(410);
            $response->content = 'Gone';
            return;
        }
        $response->redirect((string)$match['destination'], $statusCode);
    }
}
