<?php

namespace pragmatic\webtoolkit\domains\cookies;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class CookiesFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'cookies'; }
    public static function navLabel(): string { return 'Cookies'; }
    public static function cpSubpath(): string { return 'cookies'; }
    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/cookies' => 'pragmatic-web-toolkit/domain/view?domain=cookies',
        ];
    }
    public function siteRoutes(): array
    {
        return [
            'pragmatic-toolkit/cookies/consent/save' => 'pragmatic-web-toolkit/domain/cookies-consent-save',
        ];
    }
    public function permissions(): array
    {
        return ['pragmatic-toolkit:cookies' => ['label' => 'Manage Cookies']];
    }
    public function injectFrontendHtml(string $html): string
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $domain = (array)($settings->cookies ?? []);
        if (($domain['autoShowPopup'] ?? true) !== true) {
            return $html;
        }

        $popup = '<div id="pwt-cookie-popup" style="position:fixed;bottom:16px;left:16px;right:16px;background:#fff;border:1px solid #ddd;padding:12px;z-index:9999">'
            . '<strong>Cookies</strong> This site uses cookies. '
            . '<button type="button" onclick="this.closest(\'#pwt-cookie-popup\').remove()">Accept</button></div>';

        return str_replace('</body>', $popup . '</body>', $html);
    }
}
