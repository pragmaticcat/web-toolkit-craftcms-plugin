<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\Response;

class Plus18Controller extends Controller
{
    protected int|bool|array $allowAnonymous = false;

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/plus18/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/plus18/general', [
            'settings' => PragmaticWebToolkit::$plugin->plus18Settings->get(),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
        ]);
    }

    public function actionOptions(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/plus18/options', [
            'settings' => PragmaticWebToolkit::$plugin->plus18Settings->get(),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'canManageOptions' => PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE),
        ]);
    }

    public function actionSaveGeneral(): Response
    {
        $this->requirePostRequest();

        $settings = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!PragmaticWebToolkit::$plugin->plus18Settings->saveFromArray($settings)) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            Craft::$app->getSession()->setError('Per-language +18 configuration requires Lite edition or higher.');
            return $this->redirectToPostedUrl();
        }

        $settings = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!PragmaticWebToolkit::$plugin->plus18Settings->saveFromArray($settings)) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }
}
