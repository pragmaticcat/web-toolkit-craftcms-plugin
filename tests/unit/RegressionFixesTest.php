<?php

namespace pragmatic\webtoolkit\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests source-level contracts for the request and package regressions.
 */
class RegressionFixesTest extends TestCase
{
    /** @return void */
    public function test404HandlerUsesFullPathForMatchingAndLogging(): void
    {
        $root = dirname(__DIR__, 2);
        $plugin = file_get_contents($root . '/src/PragmaticWebToolkit.php');
        self::assertIsString($plugin);
        $start = strpos($plugin, 'private function registerRedirectHandling');
        $end = strpos($plugin, 'private function ensureSeoFieldsAreTranslatable');
        self::assertIsInt($start);
        self::assertIsInt($end);
        $handler = substr($plugin, $start, $end - $start);
        self::assertSame(1, substr_count($handler, '$request->getFullPath()'));
        self::assertStringContainsString('$this->redirects->match($path,', $handler);
        self::assertStringContainsString('$this->redirects->record404($path,', $handler);
        self::assertStringNotContainsString('$request->getPathInfo()', $handler);
    }

    /** @return void */
    public function testAutomaticRedirectIsCreatedAfterEachSiteSave(): void
    {
        $root = dirname(__DIR__, 2);
        $plugin = (string)file_get_contents($root . '/src/PragmaticWebToolkit.php');
        $service = (string)file_get_contents($root . '/src/domains/redirects/services/RedirectService.php');
        self::assertStringContainsString('Elements::EVENT_AFTER_SAVE_ELEMENT', $plugin);
        self::assertStringContainsString('captureEntryUriBeforeSave($event)', $plugin);
        self::assertStringContainsString('$this->entryUriTracker->capture((int)$entry->id, (int)$entry->siteId, $old)', $service);
        self::assertStringContainsString('$this->entryUriTracker->consume((int)$entry->id, (int)$entry->siteId)', $service);
        self::assertStringNotContainsString('$entry->propagating', $service);
    }

    /** @return void */
    public function testConsentFallbackMatchesRouteAndSaveRequiresPost(): void
    {
        $root = dirname(__DIR__, 2);
        $script = file_get_contents($root . '/src/domains/cookies/assets/dist/consent.js');
        $feature = file_get_contents($root . '/src/domains/cookies/CookiesFeature.php');
        $controller = file_get_contents($root . '/src/controllers/CookiesController.php');
        self::assertIsString($script);
        self::assertIsString($feature);
        self::assertIsString($controller);
        self::assertStringContainsString("'/pragmatic-toolkit/cookies/consent/save'", $script);
        self::assertStringContainsString("'pragmatic-toolkit/cookies/consent/save'", $feature);
        $start = strpos($controller, 'public function actionSaveConsent');
        self::assertIsInt($start);
        self::assertStringContainsString('$this->requirePostRequest();', substr($controller, $start, 500));
    }

    /** @return void */
    public function testTemporaryTranslationExportsAreExcludedFromPackages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'tmp-translations-entries-explicit-sites.json',
            'tmp-translations-entries-explicit-sites-handle-only.json',
            'tmp-translations-entries-terapeutaCA.json',
        ] as $filename) {
            self::assertFileDoesNotExist($root . '/' . $filename);
        }
        self::assertStringContainsString('tmp-*', (string)file_get_contents($root . '/.gitignore'));
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
        self::assertContains('/tmp-*', $composer['archive']['exclude'] ?? []);
    }
}
