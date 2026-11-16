<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Element\Events\ElementSaving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\redirects\services\RedirectService;
use ReflectionClass;
use ReflectionProperty;

class RedirectServiceExecutionTest extends TestCase
{
    private ?PragmaticWebToolkit $previousPlugin = null;
    private bool $hadPreviousPlugin = false;
    private RedirectServiceExecutionFixture $service;

    protected function setUp(): void
    {
        parent::setUp();
        $property = new ReflectionProperty(PragmaticWebToolkit::class, 'plugin');
        $this->hadPreviousPlugin = $property->isInitialized();
        $this->previousPlugin = $this->hadPreviousPlugin ? $property->getValue() : null;

        $plugin = (new ReflectionClass(RedirectServiceExecutionPlugin::class))->newInstanceWithoutConstructor();
        $plugin->settings = new RedirectServiceExecutionSettings();
        PragmaticWebToolkit::$plugin = $plugin;
        $this->service = new RedirectServiceExecutionFixture();
    }

    protected function tearDown(): void
    {
        if ($this->hadPreviousPlugin && $this->previousPlugin !== null) {
            PragmaticWebToolkit::$plugin = $this->previousPlugin;
        }
        parent::tearDown();
    }

    public function testRecord404StoresNormalizedPathAndSite(): void
    {
        $this->service->record404('/legacy/path/?fbclid=ignored', 3, 'https://example.test/referrer', 'test-agent');

        self::assertCount(1, $this->service->inserted404Rows);
        self::assertSame('legacy/path', $this->service->inserted404Rows[0]['path']);
        self::assertSame(3, $this->service->inserted404Rows[0]['siteId']);
        self::assertSame('test-agent', $this->service->inserted404Rows[0]['userAgent']);
    }

    public function testPrune404sUsesConfiguredRetention(): void
    {
        $this->service->deletedRows = 4;

        self::assertSame(4, $this->service->prune404s());
        self::assertNotNull($this->service->pruneCutoff);
        self::assertLessThanOrEqual(time() - 86400, strtotime($this->service->pruneCutoff));
    }

    public function testPreservedQueryDropsCraftPathParam(): void
    {
        $this->service->rule = [
            'id' => 321,
            'siteId' => 2,
            'sourceUri' => 'old-page',
            'matchType' => 'exact',
            'destination' => '/new-page',
            'destinationElementId' => null,
            'statusCode' => 301,
            'enabled' => true,
            'preserveQueryString' => true,
        ];

        $match = $this->service->match('old-page', 2, 'p=old-page&utm=x', false);

        self::assertNotNull($match);
        self::assertSame('/new-page?utm=x', $match['destination']);
    }

    public function testUnchangedEntryUriDoesNotCreateRedirect(): void
    {
        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIsCanonical', 'getIsDraft', 'getIsRevision'])
            ->getMock();
        $entry->method('getIsCanonical')->willReturn(true);
        $entry->method('getIsDraft')->willReturn(false);
        $entry->method('getIsRevision')->willReturn(false);
        $entry->id = 123;
        $entry->siteId = 2;
        $entry->uri = 'same-path';

        $this->service->captureEntryUriBeforeSave(new ElementSaving($entry));
        $this->service->handleEntrySave(new ElementSaved($entry));

        self::assertSame([], $this->service->savedRules);
    }

    public function testChangedEntryUriCreatesRedirectToEntry(): void
    {
        $previousFacadeApp = Facade::getFacadeApplication();
        $facadeApp = new Container();
        Facade::setFacadeApplication($facadeApp);
        Sites::swap(new class {
            public function getSiteById(int $id): object
            {
                return (object)['baseUrl' => 'https://example.test/'];
            }
        });

        try {
            $entry = $this->getMockBuilder(Entry::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getIsCanonical', 'getIsDraft', 'getIsRevision', 'getUrl'])
                ->getMock();
            $entry->method('getIsCanonical')->willReturn(true);
            $entry->method('getIsDraft')->willReturn(false);
            $entry->method('getIsRevision')->willReturn(false);
            $entry->method('getUrl')->willReturn('https://example.test/new-path');
            $entry->id = 123;
            $entry->siteId = 2;
            $entry->uri = 'new-path';
            $this->service->storedUri = 'old-path';

            $this->service->captureEntryUriBeforeSave(new ElementSaving($entry));
            $this->service->handleEntrySave(new ElementSaved($entry));

            self::assertCount(1, $this->service->savedRules);
            self::assertSame('old-path', $this->service->savedRules[0]['sourceUri']);
            self::assertSame(123, $this->service->savedRules[0]['destinationElementId']);
            self::assertSame(301, $this->service->savedRules[0]['statusCode']);
        } finally {
            Facade::clearResolvedInstance(\CraftCms\Cms\Site\Sites::class);
            Facade::setFacadeApplication($previousFacadeApp);
        }
    }
}

class RedirectServiceExecutionPlugin extends PragmaticWebToolkit
{
    public RedirectServiceExecutionSettings $settings;

    public function __get(string $name): mixed
    {
        if ($name === 'redirectSettings') {
            return $this->settings;
        }

        return parent::__get($name);
    }
}

class RedirectServiceExecutionSettings
{
    public bool $log404s = true;
    public array $ignorePatterns = [];
    public int $retentionDays = 1;
    public bool $autoRedirects = true;

    public function get(): self
    {
        return $this;
    }
}

class RedirectServiceExecutionFixture extends RedirectService
{
    public array $inserted404Rows = [];
    public int $deletedRows = 0;
    public ?string $pruneCutoff = null;
    public ?array $rule = null;
    public array $savedRules = [];
    public string $storedUri = 'same-path';
    public array $disabledSources = [];

    protected function findStoredEntryUri(int $elementId, int $siteId): ?string
    {
        return $this->storedUri;
    }

    protected function disableRulesForSource(string $sourceUri, int $siteId): void
    {
        $this->disabledSources[] = [$sourceUri, $siteId];
    }

    public function saveRule(array $values, ?int $id = null): int|string
    {
        $this->savedRules[] = $values;
        return 1;
    }

    protected function find404LogRow(int $siteKey, string $pathHash): ?array
    {
        return null;
    }

    protected function insert404LogRow(array $values): void
    {
        $this->inserted404Rows[] = $values;
    }

    protected function deleteExpired404Rows(string $cutoff): int
    {
        $this->pruneCutoff = $cutoff;
        return $this->deletedRows;
    }

    protected function findExactRule(string $normalized, ?int $siteId): ?array
    {
        return $this->rule !== null && $normalized === $this->rule['sourceUri'] && $siteId === $this->rule['siteId']
            ? $this->rule
            : null;
    }

    protected function getPathParam(): string
    {
        return 'p';
    }
}
