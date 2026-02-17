<?php

namespace pragmatic\webtoolkit\variables;

use pragmatic\webtoolkit\PragmaticWebToolkit;

class PragmaticToolkitVariable
{
    public function domain(string $key): array
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        return (array)($settings->{$key} ?? []);
    }

    public function hasFeature(string $domain): bool
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $flag = 'enable' . ucfirst($domain);
        return property_exists($settings, $flag) ? (bool)$settings->{$flag} : false;
    }

    public function cookiesHasConsent(string $categoryHandle): bool
    {
        return PragmaticWebToolkit::$plugin->cookiesConsent->hasConsent($categoryHandle);
    }

    public function cookiesCurrentConsent(): array
    {
        return PragmaticWebToolkit::$plugin->cookiesConsent->getCurrentConsent();
    }

    public function cookiesGroupedTable(): string
    {
        return PragmaticWebToolkit::$plugin->cookiesConsent->renderCookieTable();
    }
}
