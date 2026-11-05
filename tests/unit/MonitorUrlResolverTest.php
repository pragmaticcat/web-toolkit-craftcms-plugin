<?php

namespace pragmatic\webtoolkit\tests\unit;

use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\services\MonitorService;
use pragmatic\webtoolkit\services\MonitorUrlResolver;

/**
 * Covers how PWM's URLs are matched to a Craft site before they are resolved.
 */
class MonitorUrlResolverTest extends TestCase
{
    private const SITES = [
        ['id' => 1, 'handle' => 'ca', 'base_url' => 'https://www.example.cat/'],
        ['id' => 2, 'handle' => 'es', 'base_url' => 'https://www.example.cat/es/'],
        ['id' => 3, 'handle' => 'en', 'base_url' => 'https://www.example.com'],
    ];

    public function testTheHomepageHasAnEmptyUri(): void
    {
        $match = MonitorUrlResolver::matchSite('https://www.example.cat/', self::SITES);

        self::assertSame('ca', $match['site']['handle']);
        self::assertSame('', $match['uri']);
    }

    public function testTheLongestBasePathWins(): void
    {
        self::assertSame('es', MonitorUrlResolver::matchSite('https://www.example.cat/es/proyectos', self::SITES)['site']['handle']);
        self::assertSame('proyectos', MonitorUrlResolver::matchSite('https://www.example.cat/es/proyectos', self::SITES)['uri']);
        self::assertSame('', MonitorUrlResolver::matchSite('https://www.example.cat/es', self::SITES)['uri']);
        // "/estudi" starts with "/es" but is not inside the Spanish site.
        self::assertSame('ca', MonitorUrlResolver::matchSite('https://www.example.cat/estudi', self::SITES)['site']['handle']);
    }

    public function testAccentsAreDecodedAndHostAndSchemeAreCaseInsensitive(): void
    {
        $match = MonitorUrlResolver::matchSite('HTTP://WWW.Example.cat/pol%C3%ADtica-de-cookies/?a=1#top', self::SITES);

        self::assertSame('ca', $match['site']['handle']);
        self::assertSame('política-de-cookies', $match['uri']);
        self::assertSame('política-de-cookies', MonitorUrlResolver::matchSite('https://www.example.cat/política-de-cookies', self::SITES)['uri']);
    }

    public function testOtherHostsAndSchemesAreExternal(): void
    {
        self::assertNull(MonitorUrlResolver::matchSite('https://example.cat/', self::SITES));
        self::assertNull(MonitorUrlResolver::matchSite('https://www.other.com/', self::SITES));
        self::assertNull(MonitorUrlResolver::matchSite('mailto:hola@example.cat', self::SITES));
        self::assertNull(MonitorUrlResolver::matchSite('/relative', self::SITES));
    }

    public function testRedirectDestinationsBecomeAbsolute(): void
    {
        self::assertSame('https://www.example.cat/nou', MonitorUrlResolver::absoluteUrl('/nou', 'https://www.example.cat/es/'));
        self::assertSame('https://other.com/x', MonitorUrlResolver::absoluteUrl('https://other.com/x', 'https://www.example.cat/'));
    }

    public function testTheTemplatesFingerprintChangesWhenATemplateChanges(): void
    {
        $dir = sys_get_temp_dir().'/pwt-templates-'.uniqid();
        mkdir($dir.'/_partials', 0777, true);
        file_put_contents($dir.'/index.twig', 'a');
        $before = MonitorService::templatesFingerprint($dir);

        self::assertSame($before, MonitorService::templatesFingerprint($dir));
        file_put_contents($dir.'/_partials/footer.twig', 'b');
        self::assertNotSame($before, MonitorService::templatesFingerprint($dir));
        self::assertNull(MonitorService::templatesFingerprint($dir.'/missing'));

        array_map('unlink', [$dir.'/index.twig', $dir.'/_partials/footer.twig']);
        rmdir($dir.'/_partials');
        rmdir($dir);
    }
}
