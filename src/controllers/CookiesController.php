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
            'showPreferencesButton' => $request->getBodyParam('showPreferencesButton') ? 'true' : 'false',
            'preferencesButtonLabel' => (string)$request->getBodyParam('preferencesButtonLabel', 'Cookie Settings'),
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
            'canManageCategories' => PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO),
        ]);
    }

    public function actionEditCategory(?int $categoryId = null): Response
    {
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('Custom cookie categories require Pro edition.');
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

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('Custom cookie categories require Pro edition.');
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

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('Custom cookie categories require Pro edition.');
        }

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        PragmaticWebToolkit::$plugin->cookiesCategories->deleteCategory($id);

        return $this->asJson(['success' => true]);
    }

    public function actionCookies(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/cookies/cookies', [
            'cookies' => PragmaticWebToolkit::$plugin->cookiesData->getAllCookies(),
            'categories' => PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories(),
            'canManageCookies' => PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO),
        ]);
    }

    public function actionSaveCookie(): ?Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('Cookie inventory management requires Pro edition.');
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

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('Cookie inventory management requires Pro edition.');
        }

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        PragmaticWebToolkit::$plugin->cookiesData->deleteCookie($id);

        return $this->asJson(['success' => true]);
    }

    public function actionScanCookies(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('Cookie inventory management requires Pro edition.');
        }

        $names = (array)Craft::$app->getRequest()->getBodyParam('names', []);
        $names = array_filter(array_map(static fn($name) => trim((string)$name), $names));
        $names = array_values(array_unique($names));

        $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories();
        $categoriesByHandle = [];
        foreach ($categories as $category) {
            $categoriesByHandle[$category->handle] = $category;
        }

        $added = 0;
        foreach ($names as $name) {
            if (PragmaticWebToolkit::$plugin->cookiesData->getCookieByName($name)) {
                continue;
            }

            $details = $this->guessCookieDetails($name, $categoriesByHandle);
            $model = new CookieModel();
            $model->name = $name;
            $model->categoryId = $details['categoryId'];
            $model->provider = $details['provider'];
            $model->description = $details['description'];
            $model->duration = $details['duration'];

            if (PragmaticWebToolkit::$plugin->cookiesData->saveCookie($model)) {
                $added++;
            }
        }

        return $this->asJson([
            'success' => true,
            'added' => $added,
            'total' => count($names),
        ]);
    }

    private function guessCookieDetails(string $name, array $categoriesByHandle): array
    {
        $lower = strtolower($name);

        $pickCategoryId = function(array $handles) use ($categoriesByHandle): ?int {
            foreach ($handles as $handle) {
                if (isset($categoriesByHandle[$handle])) {
                    return (int)$categoriesByHandle[$handle]->id;
                }
            }
            return isset($categoriesByHandle['necessary'])
                ? (int)$categoriesByHandle['necessary']->id
                : (isset($categoriesByHandle['preferences']) ? (int)$categoriesByHandle['preferences']->id : null);
        };

        $rules = [
            [
                'patterns' => ['/^_gid$/'],
                'category' => 'analytics',
                'duration' => '24 hours',
                'description' => 'Google Analytics cookie used to store and count pageviews.',
                'provider' => 'Google Analytics',
            ],
            [
                'patterns' => ['/^_gat/'],
                'category' => 'analytics',
                'duration' => '1 minute',
                'description' => 'Google Analytics cookie used to throttle request rate.',
                'provider' => 'Google Analytics',
            ],
            [
                'patterns' => ['/^_ga($|_)/'],
                'category' => 'analytics',
                'duration' => '2 years',
                'description' => 'Google Analytics cookie used to distinguish users and sessions.',
                'provider' => 'Google Analytics',
            ],
            [
                'patterns' => ['/^_gcl_au$/', '/^_gcl_aw/'],
                'category' => 'marketing',
                'duration' => '3 months',
                'description' => 'Google Ads conversion tracking cookie.',
                'provider' => 'Google Ads',
            ],
            [
                'patterns' => ['/^_gac_/'],
                'category' => 'marketing',
                'duration' => '3 months',
                'description' => 'Google Ads campaign tracking cookie.',
                'provider' => 'Google Ads',
            ],
            [
                'patterns' => ['/^_fbp$/', '/^_fbc$/', '/^fr$/'],
                'category' => 'marketing',
                'duration' => '3 months',
                'description' => 'Facebook advertising and tracking cookie.',
                'provider' => 'Meta',
            ],
            [
                'patterns' => ['/^_uetsid$/'],
                'category' => 'marketing',
                'duration' => '1 day',
                'description' => 'Microsoft Ads session tracking cookie.',
                'provider' => 'Microsoft Ads',
            ],
            [
                'patterns' => ['/^_uetvid$/'],
                'category' => 'marketing',
                'duration' => '13 months',
                'description' => 'Microsoft Ads visitor tracking cookie.',
                'provider' => 'Microsoft Ads',
            ],
            [
                'patterns' => ['/^_hjSessionUser/'],
                'category' => 'analytics',
                'duration' => '1 year',
                'description' => 'Hotjar cookie used to store a unique user ID.',
                'provider' => 'Hotjar',
            ],
            [
                'patterns' => ['/^_hjSession/'],
                'category' => 'analytics',
                'duration' => '30 minutes',
                'description' => 'Hotjar cookie used to track the current session.',
                'provider' => 'Hotjar',
            ],
            [
                'patterns' => ['/^_clsk$/'],
                'category' => 'analytics',
                'duration' => '1 day',
                'description' => 'Microsoft Clarity cookie used to store session state.',
                'provider' => 'Microsoft Clarity',
            ],
            [
                'patterns' => ['/^_clck$/'],
                'category' => 'analytics',
                'duration' => '1 year',
                'description' => 'Microsoft Clarity cookie used for user/session tracking.',
                'provider' => 'Microsoft Clarity',
            ],
            [
                'patterns' => ['/^_tt/'],
                'category' => 'marketing',
                'duration' => '13 months',
                'description' => 'TikTok advertising and tracking cookie.',
                'provider' => 'TikTok',
            ],
            [
                'patterns' => ['/^_pin_unauth$/', '/^_pinterest_sess$/'],
                'category' => 'marketing',
                'duration' => '1 year',
                'description' => 'Pinterest advertising and tracking cookie.',
                'provider' => 'Pinterest',
            ],
            [
                'patterns' => ['/^_rdt_uuid$/'],
                'category' => 'marketing',
                'duration' => '3 months',
                'description' => 'Reddit advertising and tracking cookie.',
                'provider' => 'Reddit',
            ],
            [
                'patterns' => ['/(session|phpsessid|craftsessionid|csrf|xsrf|__cf_)/'],
                'category' => 'necessary',
                'duration' => 'Session',
                'description' => 'Essential cookie used for security and session management.',
                'provider' => 'Site',
            ],
            [
                'patterns' => ['/(consent|cookie|pragmatic)/'],
                'category' => 'necessary',
                'duration' => '1 year',
                'description' => 'Stores cookie consent preferences.',
                'provider' => 'Site',
            ],
            [
                'patterns' => ['/(lang|locale|currency|theme)/'],
                'category' => 'preferences',
                'duration' => '1 year',
                'description' => 'Stores user interface preferences.',
                'provider' => 'Site',
            ],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (preg_match($pattern, $lower)) {
                    return [
                        'categoryId' => $pickCategoryId([$rule['category']]),
                        'duration' => $rule['duration'],
                        'description' => $rule['description'],
                        'provider' => $rule['provider'],
                    ];
                }
            }
        }

        return [
            'categoryId' => $pickCategoryId(['preferences', 'necessary']),
            'duration' => '1 year',
            'description' => 'Stores user preferences and settings.',
            'provider' => 'Site',
        ];
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
