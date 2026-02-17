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
}
