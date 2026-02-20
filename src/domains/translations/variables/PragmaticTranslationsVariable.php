<?php

namespace pragmatic\webtoolkit\domains\translations\variables;

use pragmatic\webtoolkit\PragmaticWebToolkit;

class PragmaticTranslationsVariable
{
    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true, ?string $group = null): string
    {
        return PragmaticWebToolkit::$plugin->translations->t($key, $params, $siteId, $fallbackToPrimary, true, $group);
    }
}
