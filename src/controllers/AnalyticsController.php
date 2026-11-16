<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Cp\RequestedSite;
use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;

class AnalyticsController extends Controller
{
    protected int|bool|array $allowAnonymous = ['track'];

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/analytics/general');
    }

    public function actionGeneral(): Response
    {
        $this->requireCpRequest();

        $days = PragmaticWebToolkit::$plugin->analytics->getMaxDays();

        return $this->renderTemplate('pragmatic-web-toolkit/analytics/general', [
            'overview' => PragmaticWebToolkit::$plugin->analytics->getOverview($days),
            'dailyStats' => PragmaticWebToolkit::$plugin->analytics->getDailyStats($days),
            'topPages' => PragmaticWebToolkit::$plugin->analytics->getTopPages($days, 10),
            'days' => $days,
        ]);
    }

    public function actionOptions(): Response
    {
        $this->requireCpRequest();

        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();

        return $this->renderTemplate('pragmatic-web-toolkit/analytics/options', [
            'settings' => PragmaticWebToolkit::$plugin->analyticsSettings->get((int)$selectedSite->id),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => (int)$selectedSite->id,
            'consentCategories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories((int)$selectedSite->id) : [],
        ]);
    }

    public function actionSaveOptions(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $rawSettings = (array)$this->request->input('settings', []);
        $siteId = (int)$this->request->input('site', 0);
        if ($siteId <= 0) {
            $siteId = (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
        }
        $selectedSite = Sites::getSiteById($siteId);
        if ($selectedSite === null) {
            throw new NotFoundHttpException('Site not found.');
        }

        if (!PragmaticWebToolkit::$plugin->analyticsSettings->saveFromArray($rawSettings, $siteId)) {
            Flash::error('Could not save settings.');
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => PragmaticWebToolkit::$plugin->analyticsSettings->get($siteId),
                'selectedSite' => $selectedSite,
                'selectedSiteId' => $siteId,
                'consentCategories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($siteId) : [],
            ]);
            return null;
        }

        Flash::notice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionTrack(): Response
    {
        $this->setNoindexHeader();
        if (!request()->isMethod('POST')) {
            throw new MethodNotAllowedHttpException('POST is required.');
        }
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('analytics')) {
            throw new NotFoundHttpException();
        }

        $path = (string)$this->request->input('p', '/');
        PragmaticWebToolkit::$plugin->analytics->trackHit($path, $this->request, $this->response);

        $response = Craft::$app->getResponse();
        $response->setStatusCode(204);
        $response->format = Response::FORMAT_RAW;
        $response->content = '';
        return $response;
    }

    /**
     * Mark the public tracking route as non-indexable.
     *
     * @return void
     */
    private function setNoindexHeader(): void
    {
        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
