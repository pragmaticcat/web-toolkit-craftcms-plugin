<?php

namespace pragmatic\webtoolkit\domains\cookies\twig;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CookiesTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pragmaticCookieTable', [$this, 'renderCookieTable'], ['is_safe' => ['html']]),
            new TwigFunction('pragmaticHasConsent', [$this, 'hasConsent']),
        ];
    }

    public function renderCookieTable(): string
    {
        return PragmaticWebToolkit::$plugin->cookiesConsent->renderCookieTable();
    }

    public function hasConsent(string $categoryHandle): bool
    {
        return PragmaticWebToolkit::$plugin->cookiesConsent->hasConsent($categoryHandle);
    }
}
