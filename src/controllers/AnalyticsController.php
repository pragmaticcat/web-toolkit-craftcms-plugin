<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
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
        $path = (string)$this->request->getQueryParam('p', '/');
        PragmaticWebToolkit::$plugin->analytics->trackHit($path, $this->request, $this->response);

        return $this->asRaw('');
    }
}
