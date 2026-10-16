<?php

namespace pragmatic\webtoolkit\domains\redirects\services;

use craft\web\Response;

/**
 * Prepares HTTP responses for matched redirect rules.
 */
class RedirectResponseHandler
{
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
