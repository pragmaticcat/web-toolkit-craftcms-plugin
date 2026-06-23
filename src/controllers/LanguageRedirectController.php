<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class LanguageRedirectController extends Controller
{
    protected int|bool|array $allowAnonymous = ['preference'];

    public function beforeAction($action): bool
    {
        if ($action->id === 'preference') {
            return parent::beforeAction($action);
        }

        $this->requireCpRequest();
        $this->requirePermission('pragmatic-toolkit:language-redirect');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/language-redirect/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/language-redirect/general', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'settings' => PragmaticWebToolkit::$plugin->languageRedirectSettings->get(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    public function actionOptions(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/language-redirect/options', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'settings' => PragmaticWebToolkit::$plugin->languageRedirectSettings->get(),
        ]);
    }

    public function actionSaveGeneral(): Response
    {
        $this->requirePostRequest();

        $settings = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!PragmaticWebToolkit::$plugin->languageRedirectSettings->saveFromArray($settings)) {
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
        if (!PragmaticWebToolkit::$plugin->languageRedirectSettings->saveFromArray($settings)) {
            Craft::$app->getSession()->setError($this->settingsErrorMessage());
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Options saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionPreference(): Response
    {
        $request = Craft::$app->getRequest();
        $language = trim((string)$request->getParam('lang', ''));
        if ($language === '') {
            throw new BadRequestHttpException('Missing language.');
        }

        $returnUrl = $request->getParam('returnUrl');
        return PragmaticWebToolkit::$plugin->languageRedirect->persistPreferenceAndRedirect($language, is_string($returnUrl) ? $returnUrl : null);
    }

    private function settingsErrorMessage(): string
    {
        $errors = PragmaticWebToolkit::$plugin->languageRedirectSettings->getLastErrors();
        if ($errors === []) {
            return 'Could not save settings.';
        }

        $messages = [];
        foreach ($errors as $fieldErrors) {
            foreach ($fieldErrors as $message) {
                $messages[] = (string)$message;
            }
        }

        return $messages === [] ? 'Could not save settings.' : implode(' ', array_unique($messages));
    }
}
