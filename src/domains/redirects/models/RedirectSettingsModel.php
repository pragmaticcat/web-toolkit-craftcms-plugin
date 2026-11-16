<?php

namespace pragmatic\webtoolkit\domains\redirects\models;

use CraftCms\Cms\Component\Component;

/**
 * Settings for redirect matching and 404 logging.
 */
class RedirectSettingsModel extends Component
{
    /** @var bool Whether URI changes create automatic redirects. */
    public bool $autoRedirects = true;
    /** @var bool Whether unhandled 404s are recorded. */
    public bool $log404s = true;
    /** @var int Number of days to keep 404 log rows. */
    public int $retentionDays = 90;
    /** @var string[] Glob-like ignored path fragments. */
    public array $ignorePatterns = ['wp-*', 'xmlrpc', '.env', '*.php', 'cpresources', 'actions/*'];

    /**
     * @return array<int, array<mixed>> Validation rules.
     */
    public function getRules(): array
    {
        return [
            'autoRedirects' => ['boolean'],
            'log404s' => ['boolean'],
            'retentionDays' => ['integer', 'min:1'],
        ];
    }
}
