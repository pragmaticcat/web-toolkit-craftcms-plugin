<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\domains\redirects\services\RedirectService;

/**
 * Tests redirect CSV imports when the database has no matching rules.
 */
class RedirectServiceImportTest extends TestCase
{
    /** @return void */
    public function testRelativeDestinationImportsInDryRunWithoutReverseRule(): void
    {
        $service = new RedirectServiceImportTestService();
        $result = $service->importRows([self::csvRow()], true);

        self::assertSame('create', $result[0]['status']);
        self::assertSame([], $service->savedRules);
    }

    /** @return void */
    public function testRelativeDestinationImportsWithoutReverseRule(): void
    {
        $service = new RedirectServiceImportTestService();
        $result = $service->importRows([self::csvRow()]);

        self::assertSame('create', $result[0]['status']);
        self::assertCount(1, $service->savedRules);
        self::assertSame('old-page', $service->savedRules[0]['sourceUri']);
        self::assertSame('/new-page', $service->savedRules[0]['destination']);
    }

    /**
     * Return the reported CSV row.
     *
     * @return array<string, string|int> CSV row.
     */
    private static function csvRow(): array
    {
        return [
            'source' => 'old-page',
            'destination' => '/new-page',
            'statusCode' => 301,
            'siteHandle' => '',
            'matchType' => 'exact',
        ];
    }
}

/**
 * Supplies an empty rule table while exercising the real import validation flow.
 */
class RedirectServiceImportTestService extends RedirectService
{
    /** @var array<int, array<string, mixed>> Saved test rules. */
    public array $savedRules = [];

    /**
     * Return no existing imported rule.
     *
     * @param string $source Normalized source URI.
     * @param int|null $siteId Site scope.
     * @param string $matchType Match type.
     * @return array<string, mixed>|null No existing rule.
     */
    protected function findImportRule(string $source, ?int $siteId, string $matchType): ?array
    {
        return null;
    }

    /**
     * Return no reverse rule for the relative destination.
     *
     * @param string $destination Proposed destination.
     * @param int|null $siteId Site scope.
     * @param int|null $excludeId Rule ID to exclude.
     * @return array<string, mixed>|null No existing rule.
     */
    protected function findSimpleLoopRule(string $destination, ?int $siteId, ?int $excludeId): ?array
    {
        return null;
    }

    /**
     * Capture a real-mode save without a database.
     *
     * @param array<string, mixed> $values Rule data.
     * @param int|null $id Existing rule ID.
     * @return int Saved identifier.
     */
    public function saveRule(array $values, ?int $id = null): int|string
    {
        $this->savedRules[] = $values;
        return 1;
    }
}
