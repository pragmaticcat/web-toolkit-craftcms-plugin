<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Cp;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\Response;

class FaviconController extends Controller
{
    protected int|bool|array $allowAnonymous = false;

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/favicon/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
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

    public function actionOptions(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $settings = PragmaticWebToolkit::$plugin->faviconSettings->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/favicon/options', [
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

        $request = Craft::$app->getRequest();
        $siteId = (int)$request->getBodyParam('site', 0);
        if (!$siteId) {
            $siteId = (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
        }

        $rawSettings = (array)$request->getBodyParam('settings', []);
        if (!PragmaticWebToolkit::$plugin->faviconSettings->saveSiteSettings($siteId, $rawSettings)) {
            Craft::$app->getSession()->setError('Could not save favicon settings.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Favicon settings saved.');
        return $this->redirectToPostedUrl();
    }

    private function findAsset(?int $assetId, int $siteId): ?Asset
    {
        if (!$assetId) {
            return null;
        }

        $asset = Craft::$app->getElements()->getElementById($assetId, Asset::class, $siteId);
        if ($asset instanceof Asset) {
            return $asset;
        }

        $fallback = Craft::$app->getElements()->getElementById($assetId, Asset::class);
        return $fallback instanceof Asset ? $fallback : null;
    }
}
