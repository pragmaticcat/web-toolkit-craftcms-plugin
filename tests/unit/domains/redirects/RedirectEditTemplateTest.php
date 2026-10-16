<?php

namespace pragmatic\webtoolkit\tests\unit\domains\redirects;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders the new redirect form with the controller's default rule data.
 */
class RedirectEditTemplateTest extends TestCase
{
    /** @return void */
    public function testNewRedirectEditTemplateRendersWithoutAnId(): void
    {
        $templatePath = dirname(__DIR__, 4) . '/src/templates/redirects/edit.twig';
        $template = (string)file_get_contents($templatePath);
        $loader = new ArrayLoader([
            'pragmatic-web-toolkit/redirects/edit' => $template,
            'pragmatic-web-toolkit/redirects/_layout' => '{{ title }}{% block content %}{% endblock %}',
        ]);
        $twig = new Environment($loader, ['strict_variables' => true]);
        foreach (['csrfInput', 'actionInput', 'redirectInput', 'hiddenInput', 'url'] as $functionName) {
            $twig->addFunction(new TwigFunction($functionName, static fn(mixed ...$arguments): string => ''));
        }
        $twig->addFilter(new TwigFilter('t', static fn(string $value, mixed ...$arguments): string => $value));
        $craft = (object)[
            'app' => (object)[
                'request' => new class {
                    /** @return null Query parameter fixture value. */
                    public function getQueryParam(string $name): null
                    {
                        return null;
                    }
                },
            ],
        ];

        $html = $twig->render('pragmatic-web-toolkit/redirects/edit', [
            'rule' => [
                'enabled' => true,
                'statusCode' => 301,
                'matchType' => 'exact',
                'preserveQueryString' => true,
                'sourceUri' => '',
                'siteId' => null,
            ],
            'sites' => [],
            'craft' => $craft,
        ]);

        self::assertStringContainsString('New redirect', $html);
        self::assertStringContainsString('name="rule[id]"', $html);
        self::assertStringContainsString('name="rule[sourceUri]"', $html);
    }
}
