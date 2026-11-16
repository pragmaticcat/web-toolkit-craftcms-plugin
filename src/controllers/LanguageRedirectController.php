<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Cp\RequestedSite;
use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;

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
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/language-redirect/general', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'settings' => PragmaticWebToolkit::$plugin->languageRedirectSettings->get(),
            'sites' => Sites::getAllSites()->all(),
        ]);
    }

    public function actionOptions(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/language-redirect/options', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'settings' => PragmaticWebToolkit::$plugin->languageRedirectSettings->get(),
        ]);
    }

    public function actionFloating(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/language-redirect/floating', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'settings' => PragmaticWebToolkit::$plugin->languageRedirectSettings->get(),
        ]);
    }

    public function actionExamples(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/language-redirect/examples', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'settings' => PragmaticWebToolkit::$plugin->languageRedirectSettings->get(),
        ]);
    }

    public function actionSaveGeneral(): Response
    {
        $this->requirePostRequest();

        $settings = (array)request()->input('settings', []);
        if (!PragmaticWebToolkit::$plugin->languageRedirectSettings->saveFromArray($settings)) {
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
        if (!PragmaticWebToolkit::$plugin->languageRedirectSettings->saveFromArray($settings)) {
            Flash::error($this->settingsErrorMessage());
            return $this->redirectToPostedUrl();
        }

        Flash::notice('Options saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveFloating(): Response
    {
        $this->requirePostRequest();

        $settings = (array)request()->input('settings', []);
        if (!PragmaticWebToolkit::$plugin->languageRedirectSettings->saveFromArray($settings)) {
            Flash::error($this->settingsErrorMessage());
            return $this->redirectToPostedUrl();
        }

        Flash::notice('Floating button settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionPreference(): Response
    {
        $this->setNoindexHeader();
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('languageRedirect')) {
            throw new NotFoundHttpException();
        }

        $request = request();
        $language = trim((string)$request->input('lang', ''));
        if ($language === '') {
            throw new BadRequestHttpException('Missing language.');
        }

        $returnUrl = $request->input('returnUrl');
        return PragmaticWebToolkit::$plugin->languageRedirect->persistPreferenceAndRedirect($language, is_string($returnUrl) ? $returnUrl : null);
    }

    /**
     * Mark the public preference endpoint as non-indexable.
     *
     * @return void
     */
    private function setNoindexHeader(): void
    {
        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');
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
