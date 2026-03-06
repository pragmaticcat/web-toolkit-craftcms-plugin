<?php

namespace pragmatic\webtoolkit\domains\translations\variables;

use pragmatic\webtoolkit\PragmaticWebToolkit;

class PragmaticTranslationsVariable
{
    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true, ?string $group = null): string
    {
        $service = PragmaticWebToolkit::$plugin->translations;
        $normalizedGroup = $group ?: 'site';
        $activeGroups = $service->getActiveGroups();
        if (!in_array($normalizedGroup, $activeGroups, true)) {
            $value = $key;
            foreach ($params as $paramKey => $paramValue) {
                $value = str_replace('{' . $paramKey . '}', (string)$paramValue, $value);
            }
            return $value;
        }

        return $service->t($key, $params, $siteId, $fallbackToPrimary, false, $group);
    }
}
