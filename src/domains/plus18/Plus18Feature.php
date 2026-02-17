<?php

namespace pragmatic\webtoolkit\domains\plus18;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class Plus18Feature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'plus18'; }
    public static function navLabel(): string { return '+18'; }
    public static function cpSubpath(): string { return 'plus18'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/plus18' => 'pragmatic-web-toolkit/domain/view?domain=plus18',
        ];
    }
    public function siteRoutes(): array
    {
        return [];
    }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:plus18' => ['label' => 'Manage +18']];
    }
    public function injectFrontendHtml(string $html): string
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $domain = (array)($settings->plus18 ?? []);
        if (($domain['enabled'] ?? true) !== true) {
            return $html;
        }

        $minimumAge = (int)($domain['minimumAge'] ?? 18);
        $gate = '<div id="pwt-age-gate" style="position:fixed;inset:0;background:rgba(0,0,0,.88);color:#fff;display:flex;align-items:center;justify-content:center;z-index:10000">'
            . '<div><h2>Age verification</h2><p>You must be at least ' . $minimumAge . ' years old.</p>'
            . '<button type="button" onclick="document.getElementById(\'pwt-age-gate\').remove()">I am ' . $minimumAge . '+</button></div></div>';

        return str_replace('</body>', $gate . '</body>', $html);
    }
}
