<?php

namespace pragmatic\webtoolkit\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests source-level contracts for the request and package regressions.
 */
class RegressionFixesTest extends TestCase
{
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
