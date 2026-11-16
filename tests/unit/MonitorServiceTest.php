<?php

namespace pragmatic\webtoolkit\tests\unit;

use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\services\MonitorService;

/**
 * Covers the request signature, the entry save filter and deploy detection used with Pragmatic Web Monitor.
 */
class MonitorServiceTest extends TestCase
{
    private const SITE = 'site-uuid';
    private const SECRET = 'shared-secret';
    private const NOW = 1_800_000_000;

    public function testValidGetSignatureIsAccepted(): void
    {
        $timestamp = (string)self::NOW;
        $signature = hash_hmac('sha256', $timestamp."\nGET\n", self::SECRET);

        self::assertTrue($this->verify(self::SITE, $timestamp, $signature, 'GET'));
        self::assertTrue($this->verify(self::SITE, $timestamp, $signature, 'get'));
    }

    public function testSignatureCoversTheBody(): void
    {
        $timestamp = (string)self::NOW;
        $signature = hash_hmac('sha256', $timestamp."\nPOST\n{\"a\":1}", self::SECRET);

        self::assertTrue($this->verify(self::SITE, $timestamp, $signature, 'POST', '{"a":1}'));
        self::assertFalse($this->verify(self::SITE, $timestamp, $signature, 'POST', '{"a":2}'));
    }

    public function testInvalidRequestsAreRejected(): void
    {
        $timestamp = (string)self::NOW;
        $signature = hash_hmac('sha256', $timestamp."\nGET\n", self::SECRET);
        $stale = (string)(self::NOW - 301);

        self::assertFalse($this->verify('other-site', $timestamp, $signature, 'GET'));
        self::assertFalse($this->verify(self::SITE, $timestamp, 'bad', 'GET'));
        self::assertFalse($this->verify(self::SITE, $timestamp, $signature, 'POST'));
        self::assertFalse($this->verify(self::SITE, $stale, hash_hmac('sha256', $stale."\nGET\n", self::SECRET), 'GET'));
        self::assertFalse($this->verify(self::SITE, 'abc', $signature, 'GET'));
        self::assertFalse(MonitorService::verifySignature(self::SITE, '', self::SITE, $timestamp, hash_hmac('sha256', $timestamp."\nGET\n", ''), 'GET', '', self::NOW));
        self::assertFalse(MonitorService::verifySignature('', self::SECRET, '', $timestamp, $signature, 'GET', '', self::NOW));
    }

    public function testIntendedRedirectsBecomePwmFindingExceptions(): void
    {
        $rule = ['enabled' => 1, 'monitorExpected' => 1, 'statusCode' => 302, 'notes' => ''];

        $exceptions = MonitorService::redirectFindingExceptions([
            ['id' => 12, 'sourceUri' => 'es/horari', 'matchType' => 'exact', 'notes' => 'The timetable is a PDF'] + $rule,
            ['id' => 13, 'sourceUri' => 'news/<slug>', 'matchType' => 'pattern'] + $rule,
            ['id' => 14, 'sourceUri' => '^(es/)?horari$', 'matchType' => 'regex'] + $rule,
            ['id' => 15, 'sourceUri' => 'old', 'matchType' => 'exact', 'monitorExpected' => 0] + $rule,
            ['id' => 16, 'sourceUri' => 'off', 'matchType' => 'exact', 'enabled' => 0] + $rule,
        ]);

        self::assertSame([
            ['id' => 'redirect-12', 'monitor' => 'seo', 'code' => 'redirect', 'match' => 'exact', 'pattern' => '/es/horari', 'reason' => 'The timetable is a PDF', 'expires_at' => null],
            ['id' => 'redirect-13', 'monitor' => 'seo', 'code' => 'redirect', 'match' => 'glob', 'pattern' => '/news/*', 'reason' => 'Redirect rule marked as intended in Craft', 'expires_at' => null],
            ['id' => 'redirect-14', 'monitor' => 'seo', 'code' => 'redirect', 'match' => 'regex', 'pattern' => '^/(es/)?horari$', 'reason' => 'Redirect rule marked as intended in Craft', 'expires_at' => null],
        ], $exceptions);
    }

    public function testAnIntendedGoneRuleIsExpectedByIndexabilityAndBrokenLinks(): void
    {
        $exceptions = MonitorService::redirectFindingExceptions([
            ['id' => 7, 'sourceUri' => 'arxiu-antic', 'matchType' => 'exact', 'statusCode' => 410, 'enabled' => true, 'monitorExpected' => true, 'notes' => null],
        ]);

        self::assertSame(['redirect-7', 'redirect-7-links'], array_column($exceptions, 'id'));
        self::assertSame(['seo', 'broken_links'], array_column($exceptions, 'monitor'));
        self::assertSame(['http_error', 'broken_link'], array_column($exceptions, 'code'));
    }

    public function testFindingExceptionsAreAnnouncedAsACapability(): void
    {
        self::assertContains('finding_exceptions', MonitorService::CAPABILITIES);
    }

    public function testOnlyPublicEntrySavesAreReported(): void
    {
        self::assertTrue(MonitorService::shouldReportEntrySave(false, false, false, 'blog/post', false, true));
        self::assertTrue(MonitorService::shouldReportEntrySave(false, false, false, 'blog/post', true, true));
        // Disabling a published entry removes a public URL.
        self::assertTrue(MonitorService::shouldReportEntrySave(false, false, false, 'blog/post', false, false));

        self::assertFalse(MonitorService::shouldReportEntrySave(true, false, false, 'blog/post', false, true), 'draft or revision');
        self::assertFalse(MonitorService::shouldReportEntrySave(false, true, false, 'blog/post', false, true), 'propagation');
        self::assertFalse(MonitorService::shouldReportEntrySave(false, false, true, 'blog/post', false, true), 'bulk resave');
        self::assertFalse(MonitorService::shouldReportEntrySave(false, false, false, null, false, true), 'no URL');
        self::assertFalse(MonitorService::shouldReportEntrySave(false, false, false, '', false, true), 'no URL');
        self::assertFalse(MonitorService::shouldReportEntrySave(false, false, false, 'blog/post', true, false), 'new and not live');
    }

    public function testVersionChangesListUpdatedAddedAndRemovedPackages(): void
    {
        $changes = MonitorService::versionChanges(
            ['craft' => '5.8.0', 'seo' => '1.0.0', 'old' => '2.0.0'],
            ['craft' => '5.8.1', 'seo' => '1.0.0', 'new' => '0.1.0']
        );

        self::assertSame([
            'craft' => ['from' => '5.8.0', 'to' => '5.8.1'],
            'new' => ['from' => null, 'to' => '0.1.0'],
            'old' => ['from' => '2.0.0', 'to' => null],
        ], $changes);
        self::assertSame([], MonitorService::versionChanges(['craft' => '5.8.0'], ['craft' => '5.8.0']));
    }

    public function testRobotsNoindexDetection(): void
    {
        self::assertTrue(MonitorService::robotsBlocksIndexing('noindex,follow'));
        self::assertTrue(MonitorService::robotsBlocksIndexing('NOINDEX, nofollow'));
        self::assertTrue(MonitorService::robotsBlocksIndexing('none'));
        self::assertFalse(MonitorService::robotsBlocksIndexing('index,follow'));
        self::assertFalse(MonitorService::robotsBlocksIndexing('index, nofollow'));
    }

    public function testSitemapAndInventoryShareTheSitemapRules(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root.'/src/controllers/SeoController.php');
        $monitor = (string)file_get_contents($root.'/src/services/MonitorService.php');

        self::assertStringContainsString('->seoSitemap->sectionEntries(', $controller);
        self::assertStringContainsString('->seoSitemap->robotsTxt(', $controller);
        self::assertStringContainsString('$sitemap->isEntryInSitemap($entry, $typeRows)', $monitor);
        // Reading the inventory must not write sitemap settings.
        self::assertStringContainsString('$sitemap->entryTypeRows($siteId, false)', $monitor);
    }

    public function testAHealthyInstallHasNoIssues(): void
    {
        self::assertSame([], MonitorService::healthIssues($this->facts()));
        self::assertSame([], MonitorService::healthIssues($this->facts(['dev_mode' => true, 'environment' => 'dev'])));
        self::assertSame([], MonitorService::healthIssues($this->facts(['oldest_waiting_job_seconds' => 1800])));
    }

    public function testPendingDeployStepsAreCriticalAndQueueProblemsAreWarnings(): void
    {
        $issues = MonitorService::healthIssues($this->facts([
            'project_config_pending' => true,
            'updates_pending' => true,
            'dev_mode' => true,
            'failed_jobs' => 2,
            'oldest_waiting_job_seconds' => 3700,
        ]));

        self::assertSame(
            ['project_config_pending' => 'critical', 'updates_pending' => 'critical', 'dev_mode' => 'warning', 'failed_jobs' => 'warning', 'queue_stuck' => 'warning'],
            array_column($issues, 'severity', 'code')
        );
        self::assertSame('2 failed queue jobs', $issues[3]['message']);
        self::assertSame('The queue has not run for 61 minutes', $issues[4]['message']);
    }

    public function testClientErrorsAndUnknownConsoleCommandsAreNotReported(): void
    {
        self::assertTrue(MonitorService::isIgnoredException(new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException()));
        self::assertTrue(MonitorService::isIgnoredException(new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException()));
        self::assertTrue(MonitorService::isIgnoredException(new \Symfony\Component\Console\Exception\CommandNotFoundException('Unknown command')));

        self::assertFalse(MonitorService::isIgnoredException(new \Symfony\Component\HttpKernel\Exception\HttpException(500)));
        self::assertFalse(MonitorService::isIgnoredException(new \RuntimeException('Broken')));
        self::assertFalse(MonitorService::isIgnoredException(new \pragmatic\webtoolkit\errors\MonitorTestException('Test')));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{project_config_pending: bool, updates_pending: bool, dev_mode: bool, environment: string, failed_jobs: ?int, oldest_waiting_job_seconds: ?int}
     */
    private function facts(array $overrides = []): array
    {
        return [
            'project_config_pending' => false,
            'updates_pending' => false,
            'dev_mode' => false,
            'environment' => 'production',
            'failed_jobs' => 0,
            'oldest_waiting_job_seconds' => null,
            ...$overrides,
        ];
    }

    private function verify(string $site, string $timestamp, string $signature, string $method, string $body = ''): bool
    {
        return MonitorService::verifySignature(self::SITE, self::SECRET, $site, $timestamp, $signature, $method, $body, self::NOW);
    }
}
