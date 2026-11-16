<?php

namespace pragmatic\webtoolkit\tests\unit\domains\analytics;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\domains\analytics\services\AnalyticsService;

class AnalyticsTrackingCookieTest extends TestCase
{
    public function testNewVisitorCookieIsAttachedToTrackingResponse(): void
    {
        $service = new AnalyticsService();
        $request = Request::create('https://example.test/track', 'POST');
        $response = new Response('', 204);

        $visitorId = (new \ReflectionMethod($service, 'resolveVisitorId'))->invoke($service, $request, $response);
        $cookies = $response->headers->getCookies();

        self::assertCount(1, $cookies);
        self::assertSame('pa_vid', $cookies[0]->getName());
        self::assertSame($visitorId, $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertTrue($cookies[0]->isSecure());
        self::assertSame('lax', $cookies[0]->getSameSite());
    }

    public function testReturningVisitorKeepsTheExistingIdentifier(): void
    {
        $service = new AnalyticsService();
        $request = Request::create('https://example.test/track', 'POST', [], ['pa_vid' => 'known-visitor']);
        $response = new Response('', 204);

        self::assertSame('known-visitor', (new \ReflectionMethod($service, 'resolveVisitorId'))->invoke($service, $request, $response));
        self::assertSame([], $response->headers->getCookies());
    }
}
