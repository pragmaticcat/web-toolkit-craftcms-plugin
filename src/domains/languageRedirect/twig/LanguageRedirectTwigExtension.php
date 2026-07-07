<?php

namespace pragmatic\webtoolkit\domains\languageRedirect\twig;

use craft\base\ElementInterface;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class LanguageRedirectTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('siteSwitcher', [$this, 'siteSwitcher']),
        ];
    }

    public function siteSwitcher(int|string $site, ?ElementInterface $element = null): string
    {
        return PragmaticWebToolkit::$plugin->languageRedirect->switcherUrlForSite($site, $element);
    }
}
