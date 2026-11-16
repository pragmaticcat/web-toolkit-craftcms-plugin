<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cp\RequestedSite;
use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;

class Plus18Controller extends Controller
{
    protected int|bool|array $allowAnonymous = false;

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/plus18/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
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
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/plus18/options', [
            'settings' => PragmaticWebToolkit::$plugin->plus18Settings->get(),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,        ]);
    }

    public function actionSaveGeneral(): Response
    {
        $this->requirePostRequest();

        $request = request();
        $settings = (array)$request->input('settings', []);
        $topLevelLogoAssetId = $request->input('logoAssetId');
        if ($topLevelLogoAssetId !== null) {
            $settings['logoAssetId'] = $topLevelLogoAssetId;
        }

        $topLevelPrimaryButtonColor = $request->input('primaryButtonColor');
        if ($topLevelPrimaryButtonColor !== null && $topLevelPrimaryButtonColor !== '') {
            $settings['primaryButtonColor'] = $topLevelPrimaryButtonColor;
        }

        if (!array_key_exists('primaryButtonColor', $settings)) {
            $settings['primaryButtonColor'] = $topLevelPrimaryButtonColor;
        }

        $topLevelFontFamily = $request->input('fontFamily');
        if ($topLevelFontFamily !== null && trim((string)$topLevelFontFamily) !== '') {
            $settings['fontFamily'] = $topLevelFontFamily;
        }

        if (!array_key_exists('fontFamily', $settings)) {
            $settings['fontFamily'] = $request->input('fontFamily');
        }

        if (!PragmaticWebToolkit::$plugin->plus18Settings->saveFromArray($settings)) {
            Flash::error($this->settingsErrorMessage());
            return $this->redirectToPostedUrl();
        }

        Flash::notice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();

        $settings = (array)request()->input('settings', []);
        if (!PragmaticWebToolkit::$plugin->plus18Settings->saveFromArray($settings)) {
            Flash::error($this->settingsErrorMessage());
            return $this->redirectToPostedUrl();
        }

        Flash::notice('Settings saved.');
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
