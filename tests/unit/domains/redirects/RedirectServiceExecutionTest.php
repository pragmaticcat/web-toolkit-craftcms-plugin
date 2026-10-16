<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use Craft;
use craft\elements\Entry;
use craft\events\ElementEvent;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\redirects\services\RedirectService;
use pragmatic\webtoolkit\domains\redirects\services\RedirectResponseHandler;
use ReflectionClass;
use ReflectionProperty;

/**
 * Executes redirect logging, pruning, and entry-save service handlers.
 */
class RedirectServiceExecutionTest extends TestCase
{
    private mixed $previousApp = null;
    private bool $hadPreviousApp = false;
    private ?PragmaticWebToolkit $previousPlugin = null;
    private bool $hadPreviousPlugin = false;
    private RedirectServiceExecutionFixture $service;

    /** @return void */
    protected function setUp(): void
    {
        parent::setUp();
        $this->hadPreviousApp = isset(Craft::$app);
        $this->previousApp = Craft::$app ?? null;
        $pluginProperty = new ReflectionProperty(PragmaticWebToolkit::class, 'plugin');
        $this->hadPreviousPlugin = $pluginProperty->isInitialized();
        $this->previousPlugin = $this->hadPreviousPlugin ? $pluginProperty->getValue() : null;

        $plugin = (new ReflectionClass(RedirectServiceExecutionPlugin::class))->newInstanceWithoutConstructor();
        $plugin->settings = new RedirectServiceExecutionSettings();
        PragmaticWebToolkit::$plugin = $plugin;
        Craft::$app = new RedirectServiceExecutionApplication();
        $this->service = new RedirectServiceExecutionFixture();
    }

    /** @return void */
    protected function tearDown(): void
    {
        $pluginProperty = new ReflectionProperty(PragmaticWebToolkit::class, 'plugin');
        if ($this->hadPreviousPlugin && $this->previousPlugin !== null) {
            $pluginProperty->setValue(null, $this->previousPlugin);
        } else {
            unset(PragmaticWebToolkit::$plugin);
        }
        Craft::$app = $this->hadPreviousApp ? $this->previousApp : null;
        parent::tearDown();
    }

    /** @return void */
    public function testRecord404ExecutesAndStoresNormalizedLogRow(): void
    {
        $this->service->record404('/legacy/path/?fbclid=ignored', 3, 'https://example.test/referrer', 'test-agent');

        self::assertCount(1, $this->service->inserted404Rows);
        self::assertSame('legacy/path', $this->service->inserted404Rows[0]['path']);
        self::assertSame(3, $this->service->inserted404Rows[0]['siteId']);
        self::assertSame('test-agent', $this->service->inserted404Rows[0]['userAgent']);
    }

    /** @return void */
    public function testPrune404sExecutesWithConfiguredRetention(): void
    {
        $this->service->deletedRows = 4;

        self::assertSame(4, $this->service->prune404s());
        self::assertNotNull($this->service->pruneCutoff);
        self::assertLessThanOrEqual(time() - 86400, strtotime($this->service->pruneCutoff));
    }

    /** @return void */
    public function testEntrySaveHandlersCreateRedirectForChangedUri(): void
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
        $entry->uri = 'new-path';
        $event = new ElementEvent(['element' => $entry, 'isNew' => false]);

        $this->service->captureEntryUriBeforeSave($event);
        $this->service->handleEntrySave($event);

        self::assertCount(1, $this->service->savedRules);
        self::assertSame('old-path', $this->service->savedRules[0]['sourceUri']);
        self::assertSame(123, $this->service->savedRules[0]['destinationElementId']);
        self::assertSame(301, $this->service->savedRules[0]['statusCode']);
    }

    /** @return void */
    public function testEntryUriChangeRedirectsAndRevertDisablesSelfPointingRule(): void
    {
        $oldEntry = $this->createEntry('new-path', 'https://example.test/new-path');
        $this->service->destinationElement = $oldEntry;
        $event = new ElementEvent(['element' => $oldEntry, 'isNew' => false]);
        $this->service->storedUri = 'old-path';
        $this->service->captureEntryUriBeforeSave($event);
        $this->service->storedUri = 'new-path';
        $this->service->handleEntrySave($event);

        $match = $this->service->match('old-path', 2, null, false);
        self::assertNotNull($match);
        $response = new \craft\web\Response();
        RedirectResponseHandler::apply($response, $match);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://example.test/new-path', $response->getHeaders()->get('Location'));

        $revertedEntry = $this->createEntry('old-path', 'https://example.test/old-path');
        $this->service->destinationElement = $revertedEntry;
        $revertEvent = new ElementEvent(['element' => $revertedEntry, 'isNew' => false]);
        $this->service->captureEntryUriBeforeSave($revertEvent);
        $this->service->storedUri = 'old-path';
        $this->service->handleEntrySave($revertEvent);

        $activeRules = array_filter($this->service->simulatedRules, static fn(array $rule): bool => $rule['enabled']);
        self::assertCount(1, $activeRules);
        self::assertNotSame('old-path', array_values($activeRules)[0]['sourceUri']);
        self::assertSame('new-path', array_values($activeRules)[0]['sourceUri']);
        self::assertNull($this->service->match('old-path', 2, null, false));
    }

    /** @return void */
    public function testPreservedQueryDropsCraftPathParamButKeepsTrackingParameters(): void
    {
        $this->service->simulatedRules[] = [
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
        self::assertStringNotContainsString('p=', $match['destination']);
    }

    /**
     * Create an entry double with its current URL.
     *
     * @param string $uri Entry URI.
     * @param string $url Entry URL.
     * @return Entry Entry double.
     */
    private function createEntry(string $uri, string $url): Entry
    {
        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIsCanonical', 'getIsDraft', 'getIsRevision', 'getUrl'])
            ->getMock();
        $entry->method('getIsCanonical')->willReturn(true);
        $entry->method('getIsDraft')->willReturn(false);
        $entry->method('getIsRevision')->willReturn(false);
        $entry->method('getUrl')->willReturn($url);
        $entry->id = 123;
        $entry->siteId = 2;
        $entry->uri = $uri;
        return $entry;
    }
}

/**
 * Provides redirect settings through the plugin property accessed by service methods.
 */
class RedirectServiceExecutionPlugin extends PragmaticWebToolkit
{
    /** @var RedirectServiceExecutionSettings Settings test double. */
    public RedirectServiceExecutionSettings $settings;

    /**
     * Return the fixture redirect settings service.
     *
     * @param string $name Property name.
     * @return mixed Property value.
     */
    public function __get($name)
    {
        if ($name === 'redirectSettings') {
            return $this->settings;
        }
        return parent::__get($name);
    }
}

/**
 * Redirect settings used by service execution tests.
 */
class RedirectServiceExecutionSettings
{
    /** @var bool Whether 404 logging is enabled. */
    public bool $log404s = true;
    /** @var array<int, string> Ignored 404 path patterns. */
    public array $ignorePatterns = [];
    /** @var int Log retention period in days. */
    public int $retentionDays = 1;
    /** @var bool Whether automatic redirects are enabled. */
    public bool $autoRedirects = true;

    /**
     * Return this settings object as the service model.
     *
     * @return self Settings model.
     */
    public function get(): self
    {
        return $this;
    }
}

/**
 * Minimal application fixture needed for entry site URL resolution.
 */
class RedirectServiceExecutionApplication
{
    /** @var int Application state used by Craft's response class. */
    public int $state = 0;

    /**
     * Return a request fixture used by Yii response redirects.
     *
     * @return object Request fixture.
     */
    public function getRequest(): object
    {
        return new class {
            /** @return bool Whether this is an AJAX request. */
            public function getIsAjax(): bool
            {
                return false;
            }
        };
    }

    /**
     * Return the test site service.
     *
     * @return RedirectServiceExecutionSites Site service.
     */
    public function getSites(): RedirectServiceExecutionSites
    {
        return new RedirectServiceExecutionSites();
    }
}

/**
 * Minimal site service fixture.
 */
class RedirectServiceExecutionSites
{
    /**
     * Return a root-path site model.
     *
     * @param int $siteId Site ID.
     * @return object Site model.
     */
    public function getSiteById(int $siteId): object
    {
        return (object)['id' => $siteId, 'baseUrl' => 'https://example.test/'];
    }
}

/**
 * Captures database and save-rule effects while exercising public service handlers.
 */
class RedirectServiceExecutionFixture extends RedirectService
{
    /** @var array<int, array<string, mixed>> Inserted 404 log rows. */
    public array $inserted404Rows = [];
    /** @var int Number of rows returned by the fake prune operation. */
    public int $deletedRows = 0;
    /** @var string|null Cutoff passed to the fake prune operation. */
    public ?string $pruneCutoff = null;
    /** @var array<int, array<string, mixed>> Created redirect rules. */
    public array $savedRules = [];
    /** @var array<int, array<string, mixed>> Redirect rules used by matching integration coverage. */
    public array $simulatedRules = [];
    /** @var string URI returned by the fake elements_sites table. */
    public string $storedUri = 'old-path';
    /** @var Entry|null Current element returned for dynamic destination resolution. */
    public ?Entry $destinationElement = null;

    /** @return array<string, mixed>|null */
    protected function find404LogRow(int $siteKey, string $pathHash): ?array
    {
        return null;
    }

    /**
     * @param array<string, mixed> $values Log row values.
     * @return void
     */
    protected function insert404LogRow(array $values): void
    {
        $this->inserted404Rows[] = $values;
    }

    /**
     * @param string $cutoff Database timestamp cutoff.
     * @return int Deleted rows.
     */
    protected function deleteExpired404Rows(string $cutoff): int
    {
        $this->pruneCutoff = $cutoff;
        return $this->deletedRows;
    }

    /**
     * @param int $elementId Entry ID.
     * @param int $siteId Site ID.
     * @return string|null Existing URI.
     */
    protected function findStoredEntryUri(int $elementId, int $siteId): ?string
    {
        return $this->storedUri;
    }

    /**
     * Return an enabled exact rule from the in-memory rule store.
     *
     * @param string $normalized Normalized path.
     * @param int|null $siteId Current site ID.
     * @return array<string, mixed>|null Matching rule.
     */
    protected function findExactRule(string $normalized, ?int $siteId): ?array
    {
        foreach ($this->simulatedRules as $rule) {
            if ($rule['enabled'] && $rule['sourceUri'] === $normalized && ($rule['siteId'] === $siteId || $rule['siteId'] === null)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Return the test entry as the element destination.
     *
     * @param int $elementId Destination element ID.
     * @param int|null $siteId Current site ID.
     * @return \craft\base\Element|null Element destination.
     */
    protected function getDestinationElement(int $elementId, ?int $siteId): ?\craft\base\Element
    {
        return $elementId === 123 && $siteId === 2 ? $this->destinationElement : null;
    }

    /**
     * Disable simulated rules when a saved entry claims their source URI.
     *
     * @param string $sourceUri Current source URI.
     * @param int $siteId Current site.
     * @return void
     */
    protected function disableRulesForSource(string $sourceUri, int $siteId): void
    {
        foreach ($this->simulatedRules as &$rule) {
            if ($rule['sourceUri'] === strtolower(trim($sourceUri, '/')) && ($rule['siteId'] === $siteId || $rule['siteId'] === null)) {
                $rule['enabled'] = false;
            }
        }
        unset($rule);
    }

    /**
     * Return the Craft route parameter name for query preservation tests.
     *
     * @return string Craft route parameter.
     */
    protected function getPathParam(): string
    {
        return 'p';
    }

    /**
     * Capture an automatic rule without writing to the database.
     *
     * @param array<string, mixed> $values Rule values.
     * @param int|null $id Existing rule ID.
     * @return int Saved rule ID.
     */
    public function saveRule(array $values, ?int $id = null): int|string
    {
        $this->savedRules[] = $values;
        $this->simulatedRules[] = $values + ['id' => count($this->simulatedRules) + 1, 'enabled' => true];
        return 77;
    }

}
