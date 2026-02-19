<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\cookies\models\CookieCategoryModel;
use pragmatic\webtoolkit\domains\cookies\models\CookieModel;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CookiesController extends Controller
{
    protected array|int|bool $allowAnonymous = ['save-consent'];

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/cookies/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $settings = PragmaticWebToolkit::$plugin->cookiesSiteSettings->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/general', [
            'settings' => $settings,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionSaveGeneral(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $siteId = (int)$request->getBodyParam('site', 0);
        if (!$siteId) {
            $siteId = (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
        }

        $ok = PragmaticWebToolkit::$plugin->cookiesSiteSettings->saveSiteSettings($siteId, [
            'popupTitle' => $request->getBodyParam('popupTitle'),
            'popupDescription' => $request->getBodyParam('popupDescription'),
            'acceptAllLabel' => $request->getBodyParam('acceptAllLabel'),
            'rejectAllLabel' => $request->getBodyParam('rejectAllLabel'),
            'savePreferencesLabel' => $request->getBodyParam('savePreferencesLabel'),
            'cookiePolicyUrl' => $request->getBodyParam('cookiePolicyUrl'),
        ]);

        if (!$ok) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionOptions(): Response
    {
        $settings = PragmaticWebToolkit::$plugin->cookiesSettings->get();
        $canCustomizeAppearance = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO);

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/options', [
            'settings' => $settings,
            'canCustomizeAppearance' => $canCustomizeAppearance,
        ]);
    }

    public function actionSaveOptions(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $currentSettings = PragmaticWebToolkit::$plugin->cookiesSettings->get();
        $isPro = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO);

        $ok = PragmaticWebToolkit::$plugin->cookiesSettings->saveFromArray([
            'popupLayout' => $isPro
                ? (string)$request->getBodyParam('popupLayout', 'bar')
                : $currentSettings->popupLayout,
            'popupPosition' => $isPro
                ? (string)$request->getBodyParam('popupPosition', 'bottom')
                : $currentSettings->popupPosition,
            'primaryColor' => $isPro
                ? (string)$request->getBodyParam('primaryColor', '#2563eb')
                : $currentSettings->primaryColor,
            'backgroundColor' => $isPro
                ? (string)$request->getBodyParam('backgroundColor', '#ffffff')
                : $currentSettings->backgroundColor,
            'textColor' => $isPro
                ? (string)$request->getBodyParam('textColor', '#1f2937')
                : $currentSettings->textColor,
            'overlayEnabled' => $request->getBodyParam('overlayEnabled') ? 'true' : 'false',
            'autoShowPopup' => $request->getBodyParam('autoShowPopup') ? 'true' : 'false',
            'consentExpiry' => (string)$request->getBodyParam('consentExpiry', '365'),
            'logConsent' => $request->getBodyParam('logConsent') ? 'true' : 'false',
        ]);

        if (!$ok) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionCategories(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/categories/index', [
            'categories' => $categories,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionEditCategory(?int $categoryId = null): Response
    {
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Custom cookie categories require Lite edition or higher.');
        }

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        if ($categoryId) {
            $category = PragmaticWebToolkit::$plugin->cookiesCategories->getCategoryById($categoryId, $selectedSiteId);
            if (!$category) {
                throw new NotFoundHttpException('Category not found');
            }
            $title = 'Edit Category';
        } else {
            $category = new CookieCategoryModel();
            $title = 'New Category';
        }

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/categories/_edit', [
            'category' => $category,
            'title' => $title,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionSaveCategory(): ?Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Custom cookie categories require Lite edition or higher.');
        }

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('categoryId');
        $siteId = (int)$request->getBodyParam('site', 0);
        if (!$siteId) {
            $siteId = (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
        }

        if ($id) {
            $model = PragmaticWebToolkit::$plugin->cookiesCategories->getCategoryById((int)$id, $siteId);
            if (!$model) {
                throw new NotFoundHttpException('Category not found');
            }
        } else {
            $model = new CookieCategoryModel();
        }

        $model->name = (string)$request->getBodyParam('name', '');
        $model->handle = (string)$request->getBodyParam('handle', '');
        $model->description = (string)$request->getBodyParam('description', '');
        $model->isRequired = (bool)$request->getBodyParam('isRequired', false);

        if (!PragmaticWebToolkit::$plugin->cookiesCategories->saveCategory($model, $siteId)) {
            Craft::$app->getSession()->setError('Could not save category.');
            Craft::$app->getUrlManager()->setRouteParams(['category' => $model]);
            return null;
        }

        Craft::$app->getSession()->setNotice('Category saved.');
        return $this->redirectToPostedUrl($model);
    }

    public function actionDeleteCategory(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Custom cookie categories require Lite edition or higher.');
        }

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        PragmaticWebToolkit::$plugin->cookiesCategories->deleteCategory($id);

        return $this->asJson(['success' => true]);
    }

    public function actionCookies(): Response
    {
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Cookie inventory management requires Lite edition or higher.');
        }

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/cookies', [
            'cookies' => PragmaticWebToolkit::$plugin->cookiesData->getAllCookies(),
            'categories' => PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories(),
        ]);
    }

    public function actionSaveCookie(): ?Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Cookie inventory management requires Lite edition or higher.');
        }

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('cookieId');

        if ($id) {
            $model = PragmaticWebToolkit::$plugin->cookiesData->getCookieById((int)$id);
            if (!$model) {
                throw new NotFoundHttpException('Cookie not found');
            }
        } else {
            $model = new CookieModel();
        }

        $model->name = (string)$request->getBodyParam('name', '');
        $model->categoryId = $request->getBodyParam('categoryId') ? (int)$request->getBodyParam('categoryId') : null;
        $model->provider = (string)$request->getBodyParam('provider', '');
        $model->description = (string)$request->getBodyParam('description', '');
        $model->duration = (string)$request->getBodyParam('duration', '');
        $model->isRegex = (bool)$request->getBodyParam('isRegex', false);

        if (!PragmaticWebToolkit::$plugin->cookiesData->saveCookie($model)) {
            Craft::$app->getSession()->setError('Could not save cookie.');
            Craft::$app->getUrlManager()->setRouteParams(['cookie' => $model]);
            return null;
        }

        Craft::$app->getSession()->setNotice('Cookie saved.');
        return $this->redirectToPostedUrl($model);
    }

    public function actionDeleteCookie(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Cookie inventory management requires Lite edition or higher.');
        }

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        PragmaticWebToolkit::$plugin->cookiesData->deleteCookie($id);

        return $this->asJson(['success' => true]);
    }

    public function actionSaveConsent(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $consentJson = (string)$request->getBodyParam('consent', '{}');
        $visitorId = (string)$request->getBodyParam('visitorId', '');

        $consent = json_decode($consentJson, true);
        if (!is_array($consent)) {
            $consent = [];
        }

        $settings = PragmaticWebToolkit::$plugin->cookiesSettings->get();
        if ($settings->logConsent === 'true' && PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            PragmaticWebToolkit::$plugin->cookiesConsent->logConsent(
                $visitorId,
                $consent,
                $request->getUserIP(),
                $request->getUserAgent(),
            );
        }

        return $this->asJson(['success' => true]);
    }
}
