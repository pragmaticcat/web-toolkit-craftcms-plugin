<?php

namespace pragmatic\webtoolkit\domains\translations\variables;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class PragmaticTranslationsVariable
{
    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true, ?string $group = null): string
    {
        $service = PragmaticWebToolkit::$plugin->translations;
        $normalizedGroup = $group ?: 'site';
        $activeGroups = $service->getActiveGroups();
        if (!in_array($normalizedGroup, $activeGroups, true)) {
            return Craft::t($normalizedGroup, $key, $params);
        }

        return $service->t($key, $params, $siteId, $fallbackToPrimary, false, $group);
    }
}
