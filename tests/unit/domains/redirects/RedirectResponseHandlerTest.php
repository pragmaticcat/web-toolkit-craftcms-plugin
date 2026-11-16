<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\domains\redirects\services\RedirectResponseHandler;

class RedirectResponseHandlerTest extends TestCase
{
    public function testGoneResponseUses410StatusWithoutRedirect(): void
    {
        $response = RedirectResponseHandler::response(['statusCode' => 410, 'destination' => '']);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('Gone', $response->getContent());
        self::assertFalse($response->headers->has('Location'));
    }

    public function testSameHostNormalizedPathIsDetectedAsRedirectLoop(): void
    {
        self::assertTrue(RedirectResponseHandler::isSameHostPath(
            '/es/old-page/?p=old-page&utm=x',
            'https://example.test/es/old-page?utm=x',
            'https://example.test',
        ));
        self::assertFalse(RedirectResponseHandler::isSameHostPath(
            '/old-page',
            'https://other.test/old-page',
            'https://example.test',
        ));
        self::assertFalse(RedirectResponseHandler::isSameHostPath(
            '/old-page',
            '/new-page',
            'https://example.test',
        ));
    }
}
