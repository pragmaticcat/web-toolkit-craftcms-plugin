<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\elements\Asset;
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
        $logoAsset = PragmaticWebToolkit::$plugin->plus18Settings->resolveLogoAsset((int)$selectedSite->id);

        return $this->renderTemplate('pragmatic-web-toolkit/plus18/general', [
            'settings' => PragmaticWebToolkit::$plugin->plus18Settings->get(),
            'logoAsset' => $logoAsset instanceof Asset ? $logoAsset : null,
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
            'selectedSiteId' => (int)$selectedSite->id,        ]);
    }

    public function actionSaveGeneral(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $settings = (array)$request->getBodyParam('settings', []);
        $topLevelLogoAssetId = $request->getBodyParam('logoAssetId');
        if ($topLevelLogoAssetId !== null) {
            $settings['logoAssetId'] = $topLevelLogoAssetId;
        }

        $topLevelPrimaryButtonColor = $request->getBodyParam('primaryButtonColor');
        if ($topLevelPrimaryButtonColor !== null && $topLevelPrimaryButtonColor !== '') {
            $settings['primaryButtonColor'] = $topLevelPrimaryButtonColor;
        }

        if (!array_key_exists('primaryButtonColor', $settings)) {
            $settings['primaryButtonColor'] = $topLevelPrimaryButtonColor;
        }

        $topLevelFontFamily = $request->getBodyParam('fontFamily');
        if ($topLevelFontFamily !== null && trim((string)$topLevelFontFamily) !== '') {
            $settings['fontFamily'] = $topLevelFontFamily;
        }

        if (!array_key_exists('fontFamily', $settings)) {
            $settings['fontFamily'] = $request->getBodyParam('fontFamily');
        }

        if (!PragmaticWebToolkit::$plugin->plus18Settings->saveFromArray($settings)) {
            Craft::$app->getSession()->setError($this->settingsErrorMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();

        $settings = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!PragmaticWebToolkit::$plugin->plus18Settings->saveFromArray($settings)) {
            Craft::$app->getSession()->setError($this->settingsErrorMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    private function settingsErrorMessage(): string
    {
        $errors = PragmaticWebToolkit::$plugin->plus18Settings->getLastErrors();
        if ($errors === []) {
            return 'Could not save settings.';
        }

        $messages = [];
        foreach ($errors as $fieldErrors) {
            foreach ($fieldErrors as $message) {
                $messages[] = (string)$message;
            }
        }

        if ($messages === []) {
            return 'Could not save settings.';
        }

        return implode(' ', array_unique($messages));
    }
}
