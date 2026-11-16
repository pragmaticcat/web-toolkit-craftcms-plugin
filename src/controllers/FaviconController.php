<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cp\RequestedSite;
use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use CraftCms\Cms\Support\Json;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Flash;

use function CraftCms\Cms\t;

class FaviconController extends Controller
{
    protected int|bool|array $allowAnonymous = ['manifest'];

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/favicon/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $settings = PragmaticWebToolkit::$plugin->faviconSettings->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/favicon/general', [
            'settings' => $settings,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'faviconIcoAsset' => $this->findAsset($settings->faviconIcoAssetId, $selectedSiteId),
            'faviconSvgAsset' => $this->findAsset($settings->faviconSvgAssetId, $selectedSiteId),
            'appleTouchIconAsset' => $this->findAsset($settings->appleTouchIconAssetId, $selectedSiteId),
            'maskIconAsset' => $this->findAsset($settings->maskIconAssetId, $selectedSiteId),
            'manifestAsset' => $this->findAsset($settings->manifestAssetId, $selectedSiteId),
        ]);
    }

    public function actionSaveGeneral(): Response
    {
        $this->requirePostRequest();

        $request = request();
        $siteId = (int)$request->input('siteId', 0);
        if ($siteId <= 0) {
            $siteId = (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
        }

        $rawSettings = (array)$request->input('settings', []);
        if (!PragmaticWebToolkit::$plugin->faviconSettings->saveSiteGroupSettings($siteId, $rawSettings)) {
            Flash::error(t('controllers.favicon.save-settings-failed', [], 'pragmatic-web-toolkit'));
            return $this->redirectToPostedUrl();
        }

        Flash::notice(t('controllers.favicon.save-settings-success', [], 'pragmatic-web-toolkit'));
        return $this->redirectToPostedUrl();
    }

    public function actionManifest(): Response
    {
        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('favicon')) {
            throw new NotFoundHttpException();
        }

        $site = Sites::getCurrentSite();
        $siteId = (int)$site->id;
        $settings = PragmaticWebToolkit::$plugin->faviconSettings->getSiteSettings($siteId);

        if (!$settings->enabled || !$settings->autoGenerateManifest) {
            throw new NotFoundHttpException('Manifest is not available.');
        }

        $payload = [
            'name' => (string)$site->name,
            'short_name' => (string)$site->name,
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => $settings->themeColor,
            'theme_color' => $settings->themeColor,
            'icons' => $this->manifestIcons($settings->faviconIcoAssetId, $settings->faviconSvgAssetId, $settings->appleTouchIconAssetId, $siteId),
        ];

        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('Content-Type', 'application/manifest+json; charset=UTF-8');

        return $this->asRaw(Json::encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function findAsset(?int $assetId, int $siteId): ?Asset
    {
        if (!$assetId) {
            return null;
        }

        $asset = Elements::getElementById($assetId, Asset::class, $siteId);
        if ($asset instanceof Asset) {
            return $asset;
        }

        $fallback = Elements::getElementById($assetId, Asset::class);
        return $fallback instanceof Asset ? $fallback : null;
    }

    /**
     * @return array<int, array{src:string,type?:string,sizes?:string}>
     */
    private function manifestIcons(?int $icoId, ?int $svgId, ?int $appleId, int $siteId): array
    {
        $icons = [];

        foreach ([$icoId, $svgId, $appleId] as $assetId) {
            $asset = $this->findAsset($assetId, $siteId);
            if (!$asset instanceof Asset) {
                continue;
            }

            $url = $asset->getUrl();
            if (!is_string($url) || $url === '') {
                continue;
            }

            $icon = ['src' => $url];

            $mime = $asset->getMimeType();
            if (is_string($mime) && $mime !== '') {
                $icon['type'] = $mime;
            }

            $isSvg = str_ends_with(strtolower((string)$asset->getFilename()), '.svg') || $mime === 'image/svg+xml';
            if ($isSvg) {
                $icon['sizes'] = 'any';
            } elseif ((int)$asset->width > 0 && (int)$asset->height > 0) {
                $icon['sizes'] = (int)$asset->width . 'x' . (int)$asset->height;
            }

            $icons[] = $icon;
        }

        return $icons;
    }
}
