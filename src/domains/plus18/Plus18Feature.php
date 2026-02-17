<?php

namespace pragmatic\webtoolkit\domains\plus18;

use Craft;
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
            'pragmatic-toolkit/plus18' => 'pragmatic-web-toolkit/plus18/index',
            'pragmatic-toolkit/plus18/general' => 'pragmatic-web-toolkit/plus18/general',
            'pragmatic-toolkit/plus18/options' => 'pragmatic-web-toolkit/plus18/options',
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
        $settings = PragmaticWebToolkit::$plugin->plus18Settings->get();
        if (!$settings->enabled) {
            return $html;
        }

        try {
            $gate = Craft::$app->getView()->renderTemplate('pragmatic-web-toolkit/plus18/frontend/_age-gate', [
                'settings' => $settings,
                'language' => Craft::$app->language,
            ]);
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            return $html;
        }

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $gate . '</body>', $html, 1) ?? ($html . $gate);
        }

        return $html . $gate;
    }
}
