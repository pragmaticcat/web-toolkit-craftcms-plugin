<?php

namespace pragmatic\webtoolkit\tests\unit\domains\analytics;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\analytics\models\AnalyticsSettingsModel;
use pragmatic\webtoolkit\domains\analytics\services\AnalyticsService;
use ReflectionClass;
use ReflectionProperty;

class AnalyticsServiceRenderTest extends TestCase
{
    private Container $previousContainer;
    private ?PragmaticWebToolkit $previousPlugin = null;
    private bool $hadPreviousPlugin = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('session', new class {
            public function token(): string
            {
                return 'csrf-token-value';
            }
        });
        Container::setInstance($container);

        $pluginProperty = new ReflectionProperty(PragmaticWebToolkit::class, 'plugin');
        $this->hadPreviousPlugin = $pluginProperty->isInitialized();
        $this->previousPlugin = $this->hadPreviousPlugin ? $pluginProperty->getValue() : null;
        $plugin = (new ReflectionClass(AnalyticsServiceRenderPlugin::class))->newInstanceWithoutConstructor();
        $plugin->analyticsSettingsModel = new AnalyticsSettingsModel([
            'enableTracking' => true,
            'excludeEnvironments' => '',
            'excludeLoggedInUsers' => false,
            'excludeBots' => true,
        ]);
        PragmaticWebToolkit::$plugin = $plugin;
    }

    protected function tearDown(): void
    {
        if ($this->hadPreviousPlugin && $this->previousPlugin !== null) {
            PragmaticWebToolkit::$plugin = $this->previousPlugin;
        }
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testTrackingScriptRendersWithLaravelCsrfToken(): void
    {
        app()->instance('request', Request::create('https://example.test/', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0']));

        $html = (new AnalyticsServiceRenderFixture())->renderFrontendScripts();

        self::assertStringContainsString("fetch('https://example.test/pragmatic-toolkit/analytics/track'", $html);
        self::assertStringContainsString('body.set("_token", "csrf-token-value")', $html);
        self::assertStringContainsString("method: 'POST'", $html);
    }

    public function testExcludedBotDoesNotGenerateTrackingUrl(): void
    {
        app()->instance('request', Request::create('https://example.test/', 'GET', server: ['HTTP_USER_AGENT' => 'Googlebot']));
        $service = new AnalyticsServiceRenderFixture();

        self::assertSame('', $service->renderFrontendScripts());
        self::assertSame(0, $service->trackUrlCalls);
    }
}

class AnalyticsServiceRenderPlugin extends PragmaticWebToolkit
{
    public AnalyticsSettingsModel $analyticsSettingsModel;

    public function __get(string $name): mixed
    {
        if ($name === 'analyticsSettings') {
            return new class($this->analyticsSettingsModel) {
                public function __construct(private AnalyticsSettingsModel $settings) {}

                public function get(): AnalyticsSettingsModel
                {
                    return $this->settings;
                }
            };
        }

        return parent::__get($name);
    }
}

class AnalyticsServiceRenderFixture extends AnalyticsService
{
    public int $trackUrlCalls = 0;

    protected function getTrackUrl(): string
    {
        $this->trackUrlCalls++;
        return 'https://example.test/pragmatic-toolkit/analytics/track';
    }
}
