<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

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

        return $this->renderTemplate('pragmatic-web-toolkit/analytics/options', [
            'settings' => PragmaticWebToolkit::$plugin->analyticsSettings->get(),
        ]);
    }

    public function actionSaveOptions(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $rawSettings = (array)$this->request->getBodyParam('settings', []);

        if (!PragmaticWebToolkit::$plugin->analyticsSettings->saveFromArray($rawSettings)) {
            Craft::$app->getSession()->setError('Could not save settings.');
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => PragmaticWebToolkit::$plugin->analyticsSettings->get(),
            ]);
            return null;
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionTrack(): Response
    {
        $this->setNoindexHeader();
        if (!Craft::$app->getRequest()->getIsPost()) {
            throw new MethodNotAllowedHttpException('POST is required.');
        }
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('analytics')) {
            throw new NotFoundHttpException();
        }

        $path = (string)$this->request->getBodyParam('p', '/');
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
