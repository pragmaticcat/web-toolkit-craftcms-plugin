<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\domains\redirects\services\EntryUriRedirectTracker;

/**
 * Tests capturing prior entry URIs separately for each propagated site save.
 */
class EntryUriRedirectTrackerTest extends TestCase
{
    /** @return void */
    public function testEachSiteConsumesItsOwnPriorUri(): void
    {
        $tracker = new EntryUriRedirectTracker();
        $tracker->capture(42, 1, 'old-root');
        $tracker->capture(42, 2, 'old-es');

        self::assertSame('old-root', $tracker->consume(42, 1));
        self::assertSame('old-es', $tracker->consume(42, 2));
        self::assertNull($tracker->consume(42, 1));
    }

    /** @return void */
    public function testUnchangedUriCanBeSkippedAfterSave(): void
    {
        $tracker = new EntryUriRedirectTracker();
        $tracker->capture(42, 2, 'same-uri');

        self::assertSame('same-uri', $tracker->consume(42, 2));
    }
}
