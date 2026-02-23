<?php

namespace pragmatic\webtoolkit\domains\favicon\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class FaviconTagService
{
    public function renderTags(?int $siteId = null): string
    {
        $targetSiteId = $this->resolveGlobalSettingsSiteId();
        $settings = PragmaticWebToolkit::$plugin->faviconSettings->getSiteSettings($targetSiteId);
        if (!$settings->enabled) {
            return '';
        }

        $hasExplicitConfiguration =
            $settings->autoGenerateManifest ||
            $settings->faviconIcoAssetId !== null ||
            $settings->faviconSvgAssetId !== null ||
            $settings->appleTouchIconAssetId !== null ||
            $settings->manifestAssetId !== null ||
            $settings->maskIconAssetId !== null ||
            !$this->isDefaultColor($settings->themeColor, '#ffffff') ||
            !$this->isDefaultColor($settings->msTileColor, '#ffffff');

        if (!$hasExplicitConfiguration) {
            return '';
        }

        $tags = [];

        $icoUrl = $this->assetUrl($settings->faviconIcoAssetId, $targetSiteId);
        if ($icoUrl !== null) {
            $tags[] = '<link rel="icon" href="' . $this->escape($icoUrl) . '">';
        }

        $svgUrl = $this->assetUrl($settings->faviconSvgAssetId, $targetSiteId);
        if ($svgUrl !== null) {
            $tags[] = '<link rel="icon" type="image/svg+xml" href="' . $this->escape($svgUrl) . '">';
        }

        $appleUrl = $this->assetUrl($settings->appleTouchIconAssetId, $targetSiteId);
        if ($appleUrl !== null) {
            $tags[] = '<link rel="apple-touch-icon" href="' . $this->escape($appleUrl) . '">';
        }

        $manifestUrl = $this->manifestUrl($settings->manifestAssetId, $settings->autoGenerateManifest, $targetSiteId);
        if ($manifestUrl !== null) {
            $tags[] = '<link rel="manifest" href="' . $this->escape($manifestUrl) . '">';
        }

        $maskUrl = $this->assetUrl($settings->maskIconAssetId, $targetSiteId);
        if ($maskUrl !== null) {
            $tags[] = '<link rel="mask-icon" href="' . $this->escape($maskUrl) . '" color="' . $this->escape($settings->maskIconColor) . '">';
        }

        $tags[] = '<meta name="theme-color" content="' . $this->escape($settings->themeColor) . '">';
        $tags[] = '<meta name="msapplication-TileColor" content="' . $this->escape($settings->msTileColor) . '">';

        if ($tags === []) {
            return '';
        }

        return "\n" . implode("\n", $tags) . "\n";
    }

    public function injectIntoHtml(string $html): string
    {
        $tags = $this->renderTags();
        if ($tags === '') {
            return $html;
        }

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $tags . '</head>', $html, 1) ?? ($html . $tags);
        }

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $tags . '</body>', $html, 1) ?? ($html . $tags);
        }

        return $html . $tags;
    }

    private function assetUrl(?int $assetId, int $siteId): ?string
    {
        if (!$assetId) {
            return null;
        }

        $asset = Craft::$app->getElements()->getElementById($assetId, Asset::class, $siteId);
        if (!$asset instanceof Asset) {
            $asset = Craft::$app->getElements()->getElementById($assetId, Asset::class);
        }

        if (!$asset instanceof Asset) {
            return null;
        }

        try {
            $url = $asset->getUrl();
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($url) || $url === '') {
            return null;
        }

        return $url;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isDefaultColor(string $value, string $default): bool
    {
        return strtolower(trim($value)) === strtolower($default);
    }

    private function manifestUrl(?int $manifestAssetId, bool $autoGenerateManifest, int $siteId): ?string
    {
        $assetUrl = $this->assetUrl($manifestAssetId, $siteId);
        if ($assetUrl !== null) {
            return $assetUrl;
        }

        if (!$autoGenerateManifest) {
            return null;
        }

        return UrlHelper::siteUrl('manifest.webmanifest', null, null, $siteId);
    }

    private function resolveSiteId(?int $siteId): int
    {
        return $this->resolveGlobalSettingsSiteId();
    }

    private function resolveGlobalSettingsSiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }
}
