<?php

namespace pragmatic\webtoolkit\domains\translations\variables;

use pragmatic\webtoolkit\PragmaticWebToolkit;

use function CraftCms\Cms\t;

class PragmaticTranslationsVariable
{
    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true, ?string $group = null): string
    {
        if (
            request()->isCpRequest()
            || !PragmaticWebToolkit::$plugin->domains->isEnabled('translations')
        ) {
            $normalizedGroup = $group ?: 'site';
            return t($key, $params, $normalizedGroup);
        }

        $service = PragmaticWebToolkit::$plugin->translations;
        $normalizedGroup = $group ?: 'site';
        $activeGroups = $service->getActiveGroups();
        if (!in_array($normalizedGroup, $activeGroups, true)) {
            return t($key, $params, $normalizedGroup);
        }

        return $service->t($key, $params, $siteId, $fallbackToPrimary, false, $group);
    }
}
