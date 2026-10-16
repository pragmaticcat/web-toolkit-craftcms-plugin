<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\domains\redirects\services\RedirectMatcher;

/**
 * Tests redirect URI normalization and matching.
 */
class RedirectMatcherTest extends TestCase
{
    /** @var RedirectMatcher */
    private RedirectMatcher $matcher;

    /** @return void */
    protected function setUp(): void
    {
        $this->matcher = new RedirectMatcher();
    }

    /** @return void */
    public function testExactMatchIgnoresCase(): void
    {
        self::assertNotNull($this->matcher->match('/Old/Page/', 'old/page', 'exact'));
    }

    /** @return void */
    public function testCraftTokenPatternCapturesSegment(): void
    {
        $captures = $this->matcher->match('news/hello-world', 'news/<slug>', 'pattern');
        self::assertSame('hello-world', $captures['slug'] ?? null);
    }

    /** @return void */
    public function testRegexCapturesExpandDestination(): void
    {
        $captures = $this->matcher->match('old/123', '^old/(\d+)$', 'regex');
        self::assertSame('/new/123', $this->matcher->expandDestination('/new/$1', $captures ?? []));
    }

    /** @return void */
    public function testAccentedSourceMatchesEncodedAndDecodedPath(): void
    {
        self::assertNotNull($this->matcher->match('celler/fèlix-massana-ràfols', 'celler/fèlix-massana-ràfols', 'exact'));
        self::assertNotNull($this->matcher->match('celler/f%C3%A8lix-massana-r%C3%A0fols', 'celler/fèlix-massana-ràfols', 'exact'));
    }

    /** @return void */
    public function testTrailingSlashAndQueryAreIgnored(): void
    {
        self::assertNotNull($this->matcher->match('/old/page/?fbclid=123&start=77', 'old/page', 'exact'));
    }

    /** @return void */
    public function testSitePrefixIsPartOfPath(): void
    {
        self::assertNotNull($this->matcher->match('/es/old/page', 'es/old/page', 'exact'));
        self::assertNull($this->matcher->match('/es/old/page', 'old/page', 'exact'));
    }

    /** @return void */
    public function testRootSiteMatchesUnprefixedRule(): void
    {
        self::assertNotNull($this->matcher->match('/old/page', 'old/page', 'exact'));
    }

    /** @return void */
    public function testSiteScopedRuleDoesNotMatchAnotherSite(): void
    {
        self::assertTrue($this->matcher->matchesSiteScope(2, 2));
        self::assertFalse($this->matcher->matchesSiteScope(2, 3));
        self::assertTrue($this->matcher->matchesSiteScope(null, 3));
    }

    /** @return void */
    public function testRegexExpandsDoubleDigitCaptureGroups(): void
    {
        $captures = [];
        for ($index = 1; $index <= 10; $index++) {
            $captures[$index] = 'g' . $index;
        }
        self::assertSame('/g1/g10', $this->matcher->expandDestination('/$1/$10', $captures));
    }

    /** @return void */
    public function testDetectsSelfAndTwoRuleLoops(): void
    {
        self::assertTrue($this->matcher->createsSimpleLoop('/a', '/a/', []));
        self::assertTrue($this->matcher->createsSimpleLoop('/a', '/b', ['b' => '/a']));
        self::assertFalse($this->matcher->createsSimpleLoop('/a', '/b', []));
    }
}
