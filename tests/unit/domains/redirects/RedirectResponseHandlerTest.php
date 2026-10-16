<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use craft\web\Response;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\domains\redirects\services\RedirectResponseHandler;

/**
 * Tests status and headers prepared by the redirect response handler.
 */
class RedirectResponseHandlerTest extends TestCase
{
    /** @return void */
    public function testRedirectResponseUsesRuleStatusAndDestination(): void
    {
        $response = new Response();
        RedirectResponseHandler::apply($response, ['statusCode' => 301, 'destination' => '/new-page']);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/new-page', $response->headers->get('Location'));
        self::assertNotSame(404, $response->getStatusCode());
    }

    /** @return void */
    public function testGoneResponseUses410StatusWithoutRedirect(): void
    {
        $response = new Response();
        RedirectResponseHandler::apply($response, ['statusCode' => 410, 'destination' => '']);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('Gone', $response->content);
        self::assertNull($response->headers->get('Location'));
    }

    /** @return void */
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

    /** @return void */
    public function test404HandlerEndsAfterResponsePreparationAndQueuesTheHitFirst(): void
    {
        $root = dirname(__DIR__, 4);
        $plugin = (string)file_get_contents($root . '/src/PragmaticWebToolkit.php');
        $service = (string)file_get_contents($root . '/src/domains/redirects/services/RedirectService.php');
        $start = strpos($plugin, 'private function registerRedirectHandling');
        $end = strpos($plugin, 'private function ensureSeoFieldsAreTranslatable');
        self::assertIsInt($start);
        self::assertIsInt($end);
        $handler = substr($plugin, $start, $end - $start);
        self::assertMatchesRegularExpression('/RedirectResponseHandler::apply\(\$response,\s*\$match\);\s*\$event->handled = true;\s*Craft::\$app->end\(\);/s', $handler);
        self::assertStringContainsString('$request->getQueryStringWithoutPath()', $handler);
        self::assertStringContainsString('$this->queueHit((int)$row[\'id\']);', $service);
        self::assertStringContainsString('EVENT_AFTER_REQUEST', $service);
    }
}
