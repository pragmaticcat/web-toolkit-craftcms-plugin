<?php

namespace pragmatic\webtoolkit\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies public action routes use non-indexing responses and intended HTTP methods.
 */
class FrontEndActionRoutesTest extends TestCase
{
    /** @return void */
    public function testRobotsTxtDisallowsToolkitRoutePrefixes(): void
    {
        $root = dirname(__DIR__, 2);
        $sitemap = (string)file_get_contents($root . '/src/domains/seo/services/SitemapService.php');

        self::assertStringContainsString('Disallow: /pragmatic-toolkit/', $sitemap);
        self::assertStringContainsString('Disallow: /*/pragmatic-toolkit/', $sitemap);
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
