<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Cp\RequestedSite;
use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\cookies\models\CookieCategoryModel;
use pragmatic\webtoolkit\domains\cookies\models\CookieModel;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;

use function CraftCms\Cms\t;

class CookiesController extends Controller
{
    protected array|int|bool $allowAnonymous = ['save-consent'];

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/cookies/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
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

        $request = request();
        $siteId = (int)$request->input('site', 0);
        if (!$siteId) {
            $siteId = (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
        }

        $ok = PragmaticWebToolkit::$plugin->cookiesSiteSettings->saveSiteSettings($siteId, [
            'popupTitle' => $request->input('popupTitle'),
            'popupDescription' => $request->input('popupDescription'),
            'acceptAllLabel' => $request->input('acceptAllLabel'),
            'rejectAllLabel' => $request->input('rejectAllLabel'),
            'savePreferencesLabel' => $request->input('savePreferencesLabel'),
            'cookiePolicyUrl' => $request->input('cookiePolicyUrl'),
        ]);

        if (!$ok) {
            Flash::error(t('controllers.cookies.save-settings-failed', [], 'pragmatic-web-toolkit'));
            return null;
        }

        Flash::notice(t('controllers.cookies.save-settings-success', [], 'pragmatic-web-toolkit'));
        return $this->redirectToPostedUrl();
    }

    public function actionOptions(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $settings = PragmaticWebToolkit::$plugin->cookiesSettings->get();
        $canCustomizeAppearance = true;

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/options', [
            'settings' => $settings,
            'canCustomizeAppearance' => $canCustomizeAppearance,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionSaveOptions(): ?Response
    {
        $this->requirePostRequest();
        $request = request();
        $isPro = true;

        $ok = PragmaticWebToolkit::$plugin->cookiesSettings->saveFromArray([
            'popupLayout' => $isPro
                ? (string)$request->input('popupLayout', 'bar')
                : 'bar',
            'popupPosition' => $isPro
                ? (string)$request->input('popupPosition', 'bottom')
                : 'bottom',
            'primaryColor' => $isPro
                ? (string)$request->input('primaryColor', '#2563eb')
                : '#2563eb',
            'backgroundColor' => $isPro
                ? (string)$request->input('backgroundColor', '#ffffff')
                : '#ffffff',
            'textColor' => $isPro
                ? (string)$request->input('textColor', '#1f2937')
                : '#1f2937',
            'overlayEnabled' => $request->input('overlayEnabled') ? 'true' : 'false',
            'autoShowPopup' => $request->input('autoShowPopup') ? 'true' : 'false',
            'consentExpiry' => (string)$request->input('consentExpiry', '365'),
            'logConsent' => $request->input('logConsent') ? 'true' : 'false',
            'showPreferencesButton' => $request->input('showPreferencesButton') ? 'true' : 'false',
            'preferencesButtonLabel' => (string)$request->input('preferencesButtonLabel', 'Cookie Settings'),
        ]);

        if (!$ok) {
            Flash::error(t('controllers.cookies.save-settings-failed', [], 'pragmatic-web-toolkit'));
            return null;
        }

        Flash::notice(t('controllers.cookies.save-settings-success', [], 'pragmatic-web-toolkit'));
        return $this->redirectToPostedUrl();
    }

    public function actionCategories(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/categories/index', [
            'categories' => $categories,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,        ]);
    }

    public function actionEditCategory(?int $categoryId = null): Response
    {

        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
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

        $request = request();
        $id = $request->input('categoryId');
        $siteId = (int)$request->input('site', 0);
        if (!$siteId) {
            $siteId = (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
        }

        if ($id) {
            $model = PragmaticWebToolkit::$plugin->cookiesCategories->getCategoryById((int)$id, $siteId);
            if (!$model) {
                throw new NotFoundHttpException('Category not found');
            }
        } else {
            $model = new CookieCategoryModel();
        }

        $model->name = (string)$request->input('name', '');
        $model->handle = (string)$request->input('handle', '');
        $model->description = (string)$request->input('description', '');
        $model->isRequired = (bool)$request->input('isRequired', false);

        if (!PragmaticWebToolkit::$plugin->cookiesCategories->saveCategory($model, $siteId)) {
            Flash::error(t('controllers.cookies.save-category-failed', [], 'pragmatic-web-toolkit'));
            $site = Sites::getSiteById($siteId);
            return $this->renderTemplate('pragmatic-web-toolkit/cookies/categories/_edit', [
                'category' => $model,
                'title' => $id ? 'Edit Category' : 'New Category',
                'selectedSite' => $site,
                'selectedSiteId' => $siteId,
            ]);
        }

        Flash::notice(t('controllers.cookies.save-category-success', [], 'pragmatic-web-toolkit'));
        return $this->redirectToPostedUrl($model);
    }

    public function actionDeleteCategory(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)request()->input('id');
        PragmaticWebToolkit::$plugin->cookiesCategories->deleteCategory($id);

        return $this->asJson(['success' => true]);
    }

    public function actionCookies(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        return $this->renderTemplate('pragmatic-web-toolkit/cookies/cookies', [
            'cookies' => PragmaticWebToolkit::$plugin->cookiesData->getAllCookies($selectedSiteId),
            'categories' => PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($selectedSiteId),            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionSaveCookie(): ?Response
    {
        $this->requirePostRequest();

        $request = request();
        $id = $request->input('cookieId');
        $siteId = (int)$request->input('site', 0);
        if (!$siteId) {
            $siteId = (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
        }

        if ($id) {
            $model = PragmaticWebToolkit::$plugin->cookiesData->getCookieById((int)$id, $siteId);
            if (!$model) {
                throw new NotFoundHttpException('Cookie not found');
            }
        } else {
            $model = new CookieModel();
        }

        $model->name = (string)$request->input('name', '');
        $model->categoryId = $request->input('categoryId') ? (int)$request->input('categoryId') : null;
        $model->provider = (string)$request->input('provider', '');
        $model->description = (string)$request->input('description', '');
        $model->duration = (string)$request->input('duration', '');
        $model->isRegex = (bool)$request->input('isRegex', false);

        if (!PragmaticWebToolkit::$plugin->cookiesData->saveCookie($model, $siteId)) {
            Flash::error(t('controllers.cookies.save-cookie-failed', [], 'pragmatic-web-toolkit'));
            return $this->redirect('pragmatic-toolkit/cookies/cookies?site='.$siteId);
        }

        Flash::notice(t('controllers.cookies.save-cookie-success', [], 'pragmatic-web-toolkit'));
        return $this->redirectToPostedUrl($model);
    }

    public function actionDeleteCookie(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)request()->input('id');
        PragmaticWebToolkit::$plugin->cookiesData->deleteCookie($id);

        return $this->asJson(['success' => true]);
    }

    public function actionScanCookies(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = request();
        $siteId = (int)$request->input('site', 0);
        if (!$siteId) {
            $siteId = (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
        }

        $names = (array)$request->input('names', []);
        $names = array_filter(array_map(static fn($name) => trim((string)$name), $names));
        $names = array_values(array_unique($names));

        $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($siteId);
        $categoriesByHandle = [];
        foreach ($categories as $category) {
            $categoriesByHandle[$category->handle] = $category;
        }

        $siteIds = array_map(static fn($site) => (int)$site->id, Sites::getAllSites()->all());
        $added = 0;
        foreach ($names as $name) {
            $existing = PragmaticWebToolkit::$plugin->cookiesData->getCookieByName($name, $siteId);
            $details = $this->guessCookieDetails($name, $categoriesByHandle);
            if ($existing) {
                PragmaticWebToolkit::$plugin->cookiesData->upsertSiteValues((int)$existing->id, [
                    'name' => $existing->name,
                    'provider' => $details['provider'],
                    'description' => $details['description'],
                    'duration' => $details['duration'],
                ], $siteIds);
                continue;
            }

            $model = new CookieModel();
            $model->name = $name;
            $model->categoryId = $details['categoryId'];
            $model->provider = $details['provider'];
            $model->description = $details['description'];
            $model->duration = $details['duration'];

            if (PragmaticWebToolkit::$plugin->cookiesData->saveCookie($model, $siteId)) {
                PragmaticWebToolkit::$plugin->cookiesData->upsertSiteValues((int)$model->id, [
                    'name' => $model->name,
                    'provider' => $model->provider,
                    'description' => $model->description,
                    'duration' => $model->duration,
                ], $siteIds);
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
        $this->setNoindexHeader();
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('cookies')) {
            throw new NotFoundHttpException();
        }

        $this->requirePostRequest();

        $request = request();
        $consentJson = (string)$request->input('consent', '{}');
        $visitorId = (string)$request->input('visitorId', '');

        $consent = json_decode($consentJson, true);
        if (!is_array($consent)) {
            $consent = [];
        }

        $settings = PragmaticWebToolkit::$plugin->cookiesSettings->get();
        if ($settings->logConsent === 'true' && true) {
            PragmaticWebToolkit::$plugin->cookiesConsent->logConsent(
                $visitorId,
                $consent,
                $request->ip(),
                $request->userAgent(),
            );
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Mark the public consent endpoint as non-indexable.
     *
     * @return void
     */
    private function setNoindexHeader(): void
    {
        $this->setResponseHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
