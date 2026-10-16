<?php

namespace pragmatic\webtoolkit\tests\unit;

use PHPUnit\Framework\TestCase;
use yii\web\MethodNotAllowedHttpException;

/**
 * Verifies public action routes use non-indexing responses and intended HTTP methods.
 */
class FrontEndActionRoutesTest extends TestCase
{
    /** @return void */
    public function testAnalyticsTrackUsesPostAndReturnsNoContent(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/src/controllers/AnalyticsController.php');
        $service = (string)file_get_contents($root . '/src/domains/analytics/services/AnalyticsService.php');
        $action = $this->extractMethod($controller, 'actionTrack');

        self::assertStringContainsString('$this->setNoindexHeader();', $action);
        self::assertStringContainsString('getIsPost()', $action);
        self::assertStringContainsString('MethodNotAllowedHttpException', $action);
        self::assertStringContainsString('setStatusCode(204)', $action);
        self::assertStringContainsString('Response::FORMAT_RAW', $action);
        self::assertLessThan(strpos($action, 'getIsPost()'), strpos($action, '$this->setNoindexHeader();'));
        self::assertStringContainsString("'X-Robots-Tag', 'noindex, nofollow'", $controller);
        self::assertStringContainsString("method: 'POST'", $service);
        self::assertStringContainsString('keepalive: true', $service);
        self::assertSame(405, (new MethodNotAllowedHttpException('POST is required.'))->statusCode);
    }

    /** @return void */
    public function testEveryRegisteredFrontEndActionMarksResponsesNoindex(): void
    {
        $root = dirname(__DIR__, 2);
        $actions = [
            ['src/controllers/CookiesController.php', 'actionSaveConsent', '$this->setNoindexHeader();'],
            ['src/controllers/LanguageRedirectController.php', 'actionPreference', '$this->setNoindexHeader();'],
            ['src/controllers/FaviconController.php', 'actionManifest', "'X-Robots-Tag', 'noindex, nofollow'"],
            ['src/controllers/SeoController.php', 'beforeAction', "'robots-txt', 'sitemap-xml', 'sitemap-section-xml'"],
        ];

        foreach ($actions as [$file, $method, $expected]) {
            $source = (string)file_get_contents($root . '/' . $file);
            $action = $this->extractMethod($source, $method);
            self::assertStringContainsString($expected, $action, $file . '::' . $method);
            if ($file !== 'src/controllers/SeoController.php') {
                self::assertStringContainsString("'X-Robots-Tag', 'noindex, nofollow'", $source, $file);
            } else {
                self::assertStringContainsString('$this->response->getHeaders()->set(\'X-Robots-Tag\', \'noindex, nofollow\')', $action);
            }
        }
    }

    /** @return void */
    public function testRobotsTxtDisallowsToolkitRoutePrefixes(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/src/controllers/SeoController.php');

        self::assertStringContainsString('Disallow: /pragmatic-toolkit/', $controller);
        self::assertStringContainsString('Disallow: /*/pragmatic-toolkit/', $controller);
    }

    /**
     * Extract a class method from source for focused route contract assertions.
     *
     * @param string $source PHP source.
     * @param string $method Method name.
     * @return string Method declaration and body.
     */
    private function extractMethod(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, 'Expected method ' . $method . ' to exist.');
        $end = strpos($source, "\n    }", $start);
        self::assertIsInt($end, 'Expected method ' . $method . ' to have a closing brace.');
        return substr($source, $start, $end - $start + 6);
    }
}
