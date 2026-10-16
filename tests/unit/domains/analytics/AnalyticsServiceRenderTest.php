<?php

namespace pragmatic\webtoolkit\tests\unit\domains\analytics;

use Craft;
use craft\web\Request;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\analytics\models\AnalyticsSettingsModel;
use pragmatic\webtoolkit\domains\analytics\services\AnalyticsService;
use ReflectionClass;
use ReflectionProperty;

/**
 * Covers rendering analytics tracking scripts with Craft request settings.
 */
class AnalyticsServiceRenderTest extends TestCase
{
    private mixed $previousApp = null;
    private bool $hadPreviousApp = false;
    private ?PragmaticWebToolkit $previousPlugin = null;
    private bool $hadPreviousPlugin = false;
    private string|false $previousEnvironment = false;

    /** @return void */
    protected function setUp(): void
    {
        parent::setUp();
        $this->hadPreviousApp = isset(Craft::$app);
        $this->previousApp = Craft::$app ?? null;
        $pluginProperty = new ReflectionProperty(PragmaticWebToolkit::class, 'plugin');
        $this->hadPreviousPlugin = $pluginProperty->isInitialized();
        $this->previousPlugin = $this->hadPreviousPlugin ? $pluginProperty->getValue() : null;
        $this->previousEnvironment = getenv('CRAFT_ENVIRONMENT');
        putenv('CRAFT_ENVIRONMENT=testing');

        $plugin = (new ReflectionClass(AnalyticsServiceRenderPlugin::class))->newInstanceWithoutConstructor();
        $plugin->analyticsSettingsModel = new AnalyticsSettingsModel([
            'enableTracking' => true,
            'excludeEnvironments' => '',
            'excludeLoggedInUsers' => true,
            'excludeBots' => true,
        ]);
        PragmaticWebToolkit::$plugin = $plugin;

        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCsrfToken', 'getUserAgent'])
            ->getMock();
        $request->csrfParam = '_csrf';
        $request->method('getCsrfToken')->willReturn('csrf-token-value');
        $request->method('getUserAgent')->willReturn('Mozilla/5.0');
        Craft::$app = new AnalyticsServiceRenderApplication($request);
    }

    /** @return void */
    protected function tearDown(): void
    {
        $pluginProperty = new ReflectionProperty(PragmaticWebToolkit::class, 'plugin');
        if ($this->hadPreviousPlugin && $this->previousPlugin !== null) {
            $pluginProperty->setValue(null, $this->previousPlugin);
        }
        Craft::$app = $this->hadPreviousApp ? $this->previousApp : null;
        if ($this->previousEnvironment === false) {
            putenv('CRAFT_ENVIRONMENT');
        } else {
            putenv('CRAFT_ENVIRONMENT=' . $this->previousEnvironment);
        }
        parent::tearDown();
    }

    /** @return void */
    public function testTrackingScriptRendersWithCsrfParamAndToken(): void
    {
        $service = new AnalyticsServiceRenderFixture();

        $html = $service->renderFrontendScripts();

        self::assertStringContainsString("fetch('https://example.test/pragmatic-toolkit/analytics/track'", $html);
        self::assertStringContainsString('body.set("_csrf", "csrf-token-value")', $html);
        self::assertStringContainsString("method: 'POST'", $html);
    }

    /** @return void */
    public function testExcludedBotDoesNotGenerateTrackingUrlOrCsrfToken(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCsrfToken', 'getUserAgent'])
            ->getMock();
        $request->expects(self::never())->method('getCsrfToken');
        $request->method('getUserAgent')->willReturn('Googlebot');
        Craft::$app = new AnalyticsServiceRenderApplication($request);
        $service = new AnalyticsServiceRenderFixture();

        self::assertSame('', $service->renderFrontendScripts());
        self::assertSame(0, $service->trackUrlCalls);
    }
}

/**
 * Supplies analytics settings through the plugin property used by the service.
 */
class AnalyticsServiceRenderPlugin extends PragmaticWebToolkit
{
    /** @var AnalyticsSettingsModel Analytics settings returned to the service. */
    public AnalyticsSettingsModel $analyticsSettingsModel;

    /**
     * Return the test analytics settings service.
     *
     * @param string $name Requested component property.
     * @return mixed Property value.
     */
    public function __get($name)
    {
        if ($name === 'analyticsSettings') {
            return new class($this->analyticsSettingsModel) {
                /** @var AnalyticsSettingsModel Analytics settings. */
                private AnalyticsSettingsModel $settings;

                /** @param AnalyticsSettingsModel $settings Analytics settings. */
                public function __construct(AnalyticsSettingsModel $settings)
                {
                    $this->settings = $settings;
                }

                /** @return AnalyticsSettingsModel Settings model. */
                public function get(): AnalyticsSettingsModel
                {
                    return $this->settings;
                }
            };
        }

        return parent::__get($name);
    }
}

/**
 * Minimal app fixture for analytics request and guest-user lookups.
 */
class AnalyticsServiceRenderApplication
{
    /** @var Request Craft request fixture. */
    private Request $request;

    /** @param Request $request Craft request fixture. */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /** @return Request Current request fixture. */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /** @return object User fixture reporting a guest session. */
    public function getUser(): object
    {
        return new class {
            /** @return bool Whether the current user is a guest. */
            public function getIsGuest(): bool
            {
                return true;
            }
        };
    }
}

/**
 * Replaces Craft URL generation so rendering can be exercised without a full Craft app.
 */
class AnalyticsServiceRenderFixture extends AnalyticsService
{
    /** @var int Number of attempts to generate the tracking endpoint URL. */
    public int $trackUrlCalls = 0;

    /** @return string Test tracking endpoint URL. */
    protected function getTrackUrl(): string
    {
        $this->trackUrlCalls++;
        return 'https://example.test/pragmatic-toolkit/analytics/track';
    }
}
