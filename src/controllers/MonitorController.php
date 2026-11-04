<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class MonitorController extends Controller
{
    protected array|bool|int $allowAnonymous = ['health', 'inventory'];

    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        $this->requireSignedRequest();
        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');

        return parent::beforeAction($action);
    }

    public function actionHealth(): Response
    {
        Craft::$app->getDb()->open();
        $monitor = PragmaticWebToolkit::$plugin->monitor;
        $facts = $monitor->healthFacts();
        $issues = $monitor::healthIssues($facts);

        return $this->asJson([
            'status' => $issues === [] ? 'healthy' : 'degraded',
            'issues' => $issues,
            'timestamp' => gmdate(DATE_ATOM),
            'craft_version' => Craft::$app->getVersion(),
            'php_version' => PHP_VERSION,
            'environment' => Craft::$app->env,
            'queue_size' => Craft::$app->getQueue()->getTotalJobs(),
            'failed_jobs' => $facts['failed_jobs'],
            'oldest_waiting_job_seconds' => $facts['oldest_waiting_job_seconds'],
            'fingerprints' => [
                'project_config' => PragmaticWebToolkit::$plugin->monitor->projectConfigFingerprint(),
            ],
        ]);
    }

    /**
     * Without parameters, returns the indexing settings and a summary per site.
     * With `site` (a site handle) and optional `page`, returns that page of the site's live URLs.
     */
    public function actionInventory(): Response
    {
        $request = Craft::$app->getRequest();
        $monitor = PragmaticWebToolkit::$plugin->monitor;
        $handle = trim((string)$request->getQueryParam('site', ''));

        if ($handle === '') {
            return $this->asJson($monitor->inventorySummary());
        }

        $site = Craft::$app->getSites()->getSiteByHandle($handle, true);
        if ($site === null || !$site->hasUrls) {
            throw new NotFoundHttpException('Site not found.');
        }

        return $this->asJson($monitor->inventoryUrls($site, (int)$request->getQueryParam('page', 1)));
    }

    private function requireSignedRequest(): void
    {
        $request = Craft::$app->getRequest();
        $headers = $request->getHeaders();

        $valid = PragmaticWebToolkit::$plugin->monitor->isValidRequest(
            (string)$headers->get('X-PWT-Site', ''),
            (string)$headers->get('X-PWT-Timestamp', ''),
            (string)$headers->get('X-PWT-Signature', ''),
            $request->getMethod(),
            $request->getRawBody()
        );

        if (!$valid) {
            throw new UnauthorizedHttpException('Invalid monitoring signature.');
        }
    }
}
