<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use PHPUnit\Framework\TestCase;

/**
 * Tests redirect command, documentation, and hit counter contracts.
 */
class RedirectsRegressionContractTest extends TestCase
{
    /** @return void */
    public function testDryRunOptionIsDocumentedAndRegistered(): void
    {
        $root = dirname(__DIR__, 4);
        $controller = (string)file_get_contents($root . '/src/console/controllers/RedirectsController.php');
        self::assertStringContainsString('public bool $dryRun = false;', $controller);
        self::assertStringContainsString("['dryRun']", $controller);
        self::assertStringContainsString('actionImport(string $file)', $controller);
        foreach (['README.md', 'docs/redirects.md'] as $document) {
            $contents = (string)file_get_contents($root . '/' . $document);
            self::assertStringContainsString('redirects/import path/to/redirects.csv --dry-run', $contents);
            self::assertStringNotContainsString('--dry-run=1', $contents);
        }
    }

    /** @return void */
    public function testEveryRuleHitIsPersistedAfterTheRequest(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string)file_get_contents($root . '/src/domains/redirects/services/RedirectService.php');
        $start = strpos($service, 'private function queueHit');
        $end = strpos($service, 'private function hasSimpleLoop', $start ?: 0);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $queueHit = substr($service, $start, $end - $start);
        self::assertStringContainsString('EVENT_AFTER_REQUEST', $queueHit);
        self::assertStringContainsString('[[hitCount]] + 1', $queueHit);
        self::assertStringNotContainsString('getCache()', $queueHit);
    }
}
