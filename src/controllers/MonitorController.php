<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\helpers\App;
use craft\queue\Queue as CraftQueue;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\services\MonitorQueueRunner;
use pragmatic\webtoolkit\services\MonitorService;
use pragmatic\webtoolkit\services\MonitorUrlResolver;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class MonitorController extends Controller
{
    protected array|bool|int $allowAnonymous = ['health', 'inventory', 'resolve', 'run-queue'];

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
            'capabilities' => MonitorService::CAPABILITIES,
            'finding_exceptions' => $monitor->findingExceptions(),
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

    /**
     * Resolves up to 200 URLs inside Craft, so PWM does not request each one.
     * Body: {"urls": ["https://..."]}. Each result has a `status`: ok, redirect,
     * disabled, not_found, unknown (a custom route PWT cannot check) or external.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $urls = Craft::$app->getRequest()->getBodyParam('urls');
        if (!is_array($urls)) {
            throw new BadRequestHttpException('Send a JSON body with a "urls" list.');
        }

        return $this->asJson(['results' => (new MonitorUrlResolver())->resolveAll($urls)]);
    }

    /**
     * Runs waiting queue jobs for up to `seconds` (default 25, at most 50), so sites without
     * a cron or worker still run their queue. PWM calls it when the health check reports
     * waiting jobs. Body: {"seconds": 25}. `status` is `empty` (nothing left to run), `time`
     * (the budget ran out), `busy` (another run is in progress) or `unsupported`.
     */
    public function actionRunQueue(): Response
    {
        $this->requirePostRequest();
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof CraftQueue) {
            return $this->asJson(['status' => 'unsupported', 'ran' => 0]);
        }

        $mutex = Craft::$app->getMutex();
        $lock = 'pragmatic-web-toolkit:monitor-run-queue';
        if (!$mutex->acquire($lock)) {
            return $this->asJson(['status' => 'busy', 'ran' => 0] + $this->queueState($queue));
        }

        try {
            App::maxPowerCaptain();
            $result = MonitorQueueRunner::run(
                fn(): bool => $queue->executeJob(),
                fn(): float => microtime(true),
                MonitorQueueRunner::seconds(Craft::$app->getRequest()->getBodyParam('seconds')),
            );
        } finally {
            $mutex->release($lock);
        }

        return $this->asJson(['status' => $result['stopped'], 'ran' => $result['ran']] + $this->queueState($queue));
    }

    /**
     * @return array{waiting: int, failed_jobs: ?int, oldest_waiting_job_seconds: ?int}
     */
    private function queueState(CraftQueue $queue): array
    {
        $facts = PragmaticWebToolkit::$plugin->monitor->healthFacts();

        return [
            'waiting' => $queue->getTotalWaiting(),
            'failed_jobs' => $facts['failed_jobs'],
            'oldest_waiting_job_seconds' => $facts['oldest_waiting_job_seconds'],
        ];
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
