<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Cms;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\services\MonitorQueueRunner;
use pragmatic\webtoolkit\services\MonitorService;
use pragmatic\webtoolkit\services\MonitorUrlResolver;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Facades\Sites;

class MonitorController extends Controller
{
    protected array|bool|int $allowAnonymous = ['health', 'inventory', 'resolve', 'run-queue'];

    public function beforeAction(string $action): bool
    {
        $this->requireSignedRequest();
        $this->setResponseHeader('X-Robots-Tag', 'noindex, nofollow');

        return parent::beforeAction($action);
    }

    public function actionHealth(): Response
    {
        $monitor = PragmaticWebToolkit::$plugin->monitor;
        $facts = $monitor->healthFacts();
        $issues = $monitor::healthIssues($facts);
        $queueTable = $this->queueTable();

        return $this->asJson([
            'status' => $issues === [] ? 'healthy' : 'degraded',
            'issues' => $issues,
            'timestamp' => gmdate(DATE_ATOM),
            'capabilities' => MonitorService::CAPABILITIES,
            'finding_exceptions' => $monitor->findingExceptions(),
            'craft_version' => \CraftCms\Cms\Cms::version(),
            'php_version' => PHP_VERSION,
            'environment' => app()->environment(),
            'queue_size' => $queueTable !== null ? DB::table($queueTable)->count() : null,
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
        $request = request();
        $monitor = PragmaticWebToolkit::$plugin->monitor;
        $handle = trim((string)$request->query('site', ''));

        if ($handle === '') {
            return $this->asJson($monitor->inventorySummary());
        }

        $site = Sites::getSiteByHandle($handle, true);
        if ($site === null || !$site->hasUrls) {
            throw new NotFoundHttpException('Site not found.');
        }

        return $this->asJson($monitor->inventoryUrls($site, (int)$request->query('page', 1)));
    }

    /**
     * Resolves up to 200 URLs inside Craft, so PWM does not request each one.
     * Body: {"urls": ["https://..."]}. Each result has a `status`: ok, redirect,
     * disabled, not_found, unknown (a custom route PWT cannot check) or external.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $urls = request()->input('urls');
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
        $table = $this->queueTable();
        if ($table === null) {
            return $this->asJson(['status' => 'unsupported', 'ran' => 0]);
        }

        $lock = Cache::lock('pragmatic-web-toolkit:monitor-run-queue', MonitorQueueRunner::MAX_SECONDS + 10);
        if (!$lock->get()) {
            return $this->asJson(['status' => 'busy', 'ran' => 0] + $this->queueState($table));
        }

        try {
            $result = MonitorQueueRunner::run(
                function () use ($table): bool {
                    if (!$this->hasWaitingJob($table)) {
                        return false;
                    }

                    Artisan::call('queue:work', [
                        'connection' => config('queue.default'),
                        '--once' => true,
                        '--queue' => Cms::config()->queueName,
                    ]);

                    return true;
                },
                fn(): float => microtime(true),
                MonitorQueueRunner::seconds(request()->input('seconds')),
            );
        } finally {
            $lock->release();
        }

        return $this->asJson(['status' => $result['stopped'], 'ran' => $result['ran']] + $this->queueState($table));
    }

    /**
     * @return array{waiting: int, failed_jobs: ?int, oldest_waiting_job_seconds: ?int}
     */
    private function queueState(string $table): array
    {
        $facts = PragmaticWebToolkit::$plugin->monitor->healthFacts();

        return [
            'waiting' => DB::table($table)->where('queue', Cms::config()->queueName)->whereNull('reserved_at')->count(),
            'failed_jobs' => $facts['failed_jobs'],
            'oldest_waiting_job_seconds' => $facts['oldest_waiting_job_seconds'],
        ];
    }

    private function hasWaitingJob(string $table): bool
    {
        return DB::table($table)
            ->where('queue', Cms::config()->queueName)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', time())
            ->exists();
    }

    private function queueTable(): ?string
    {
        $connection = config('queue.default');
        if (config("queue.connections.$connection.driver") !== 'database') {
            return null;
        }

        $table = (string)config("queue.connections.$connection.table", 'jobs');
        return Schema::hasTable($table) ? $table : null;
    }

    private function requireSignedRequest(): void
    {
        $request = request();
        $valid = PragmaticWebToolkit::$plugin->monitor->isValidRequest(
            (string)$request->header('X-PWT-Site', ''),
            (string)$request->header('X-PWT-Timestamp', ''),
            (string)$request->header('X-PWT-Signature', ''),
            $request->method(),
            $request->getContent()
        );

        if (!$valid) {
            abort(401, 'Invalid monitoring signature.');
        }
    }
}
