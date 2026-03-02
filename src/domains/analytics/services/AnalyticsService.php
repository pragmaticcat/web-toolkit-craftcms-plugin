<?php

namespace pragmatic\webtoolkit\domains\analytics\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\db\Expression;
use yii\db\IntegrityException;
use yii\web\Cookie;
use yii\web\Request;
use yii\web\Response;

class AnalyticsService extends Component
{
    private const PATH_MAX_LENGTH = 191;
    private const PAGE_PATH_INDEX = 'pwt_analytics_page_daily_stats_path_idx';

    public const DAILY_STATS_TABLE = '{{%pragmatic_toolkit_analytics_daily_stats}}';
    public const PAGE_DAILY_STATS_TABLE = '{{%pragmatic_toolkit_analytics_page_daily_stats}}';
    public const DAILY_UNIQUE_VISITORS_TABLE = '{{%pragmatic_toolkit_analytics_daily_unique_visitors}}';

    private bool $storageChecked = false;
    private bool $storageReady = false;

    public function trackHit(string $path, Request $request, Response $response): bool
    {
        $settings = PragmaticWebToolkit::$plugin->analyticsSettings->get();
        if (!$settings->enableTracking) {
            return false;
        }

        if (!$this->ensureStorageReady() || $this->shouldSkipTracking($request)) {
            return false;
        }

        $normalizedPath = $this->normalizePath($path);
        $today = gmdate('Y-m-d');

        $db = Craft::$app->getDb();
        $visitorId = $this->resolveVisitorId($request, $response);
        $visitorHash = hash('sha256', $visitorId);

        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()->upsert(
                self::DAILY_STATS_TABLE,
                ['date' => $today, 'visits' => 1, 'uniqueVisitors' => 0],
                ['visits' => new Expression('[[visits]] + 1')]
            )->execute();

            $db->createCommand()->upsert(
                self::PAGE_DAILY_STATS_TABLE,
                ['date' => $today, 'path' => $normalizedPath, 'visits' => 1],
                ['visits' => new Expression('[[visits]] + 1')]
            )->execute();

            if ($this->registerUniqueVisitor($today, $visitorHash)) {
                $db->createCommand()->update(
                    self::DAILY_STATS_TABLE,
                    ['uniqueVisitors' => new Expression('[[uniqueVisitors]] + 1')],
                    ['date' => $today]
                )->execute();
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            Craft::error('Analytics tracking failed: ' . $exception->getMessage(), __METHOD__);
            return false;
        }
    }

    public function getOverview(int $days = 30): array
    {
        if (!$this->ensureStorageReady()) {
            return ['visits' => 0, 'uniqueVisitors' => 0];
        }

        $days = $this->clampDaysToEdition($days);

        $row = (new \craft\db\Query())
            ->from(self::DAILY_STATS_TABLE)
            ->where(['>=', 'date', $this->rangeStart($days)])
            ->select([
                'visits' => new Expression('COALESCE(SUM([[visits]]), 0)'),
                'uniqueVisitors' => new Expression('COALESCE(SUM([[uniqueVisitors]]), 0)'),
            ])
            ->one();

        return [
            'visits' => (int)($row['visits'] ?? 0),
            'uniqueVisitors' => (int)($row['uniqueVisitors'] ?? 0),
        ];
    }

    public function getDailyStats(int $days = 30): array
    {
        if (!$this->ensureStorageReady()) {
            return [];
        }

        $days = $this->clampDaysToEdition($days);

        return (new \craft\db\Query())
            ->from(self::DAILY_STATS_TABLE)
            ->where(['>=', 'date', $this->rangeStart($days)])
            ->orderBy(['date' => SORT_ASC])
            ->all();
    }

    public function getTopPages(int $days = 30, int $limit = 10): array
    {
        if (!$this->ensureStorageReady()) {
            return [];
        }

        $days = $this->clampDaysToEdition($days);

        return (new \craft\db\Query())
            ->from(self::PAGE_DAILY_STATS_TABLE)
            ->where(['>=', 'date', $this->rangeStart($days)])
            ->groupBy(['path'])
            ->select([
                'path',
                'visits' => new Expression('SUM([[visits]])'),
            ])
            ->orderBy(['visits' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getMaxDays(): int
    {
        if (PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            return 30;
        }

        return 7;
    }

    public function renderFrontendScripts(): string
    {
        $settings = PragmaticWebToolkit::$plugin->analyticsSettings->get();
        $scripts = '';

        if ($settings->enableTracking) {
            $trackUrl = UrlHelper::siteUrl('pragmatic-toolkit/analytics/track');
            $requireConsent = $settings->requireConsent ? 'true' : 'false';
            $scripts .= "<script>(() => {\n" .
                "  if (" . $requireConsent . ") {\n" .
                "    const hasConsent = window.PragmaticAnalyticsConsent === true || document.cookie.includes('pa_consent=1');\n" .
                "    if (!hasConsent) return;\n" .
                "  }\n" .
                "  const path = window.location.pathname + window.location.search;\n" .
                "  const url = '" . addslashes($trackUrl) . "?p=' + encodeURIComponent(path);\n" .
                "  fetch(url, { method: 'GET', credentials: 'same-origin', keepalive: true, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});\n" .
                "})();</script>";
        }

        if (PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO) && $settings->injectGaScript && $settings->gaMeasurementId !== '') {
            $id = htmlspecialchars($settings->gaMeasurementId, ENT_QUOTES, 'UTF-8');
            $scripts .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($id) . '"></script>';
            $scripts .= "<script>(() => {\n" .
                "  if (!window.dataLayer) window.dataLayer = [];\n" .
                "  const gtag = function(){window.dataLayer.push(arguments);};\n" .
                "  window.gtag = window.gtag || gtag;\n" .
                "  gtag('js', new Date());\n" .
                "  gtag('config', " . json_encode($id) . ");\n" .
                "})();</script>";
        }

        return $scripts;
    }

    private function shouldSkipTracking(Request $request): bool
    {
        $settings = PragmaticWebToolkit::$plugin->analyticsSettings->get();
        $environment = strtolower((string)getenv('CRAFT_ENVIRONMENT'));
        $excluded = array_values(array_filter(array_map('trim', explode(',', $settings->excludeEnvironments))));
        $excluded = array_map('strtolower', $excluded);

        if ($environment !== '' && in_array($environment, $excluded, true)) {
            return true;
        }

        if ($settings->excludeLoggedInUsers && !Craft::$app->getUser()->getIsGuest()) {
            return true;
        }

        if ($settings->excludeBots && $this->isLikelyBot($request->getUserAgent() ?? '')) {
            return true;
        }

        return false;
    }

    private function isLikelyBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        return (bool)preg_match('/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|preview|headless|pingdom|uptime/i', $userAgent);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return mb_substr($path, 0, self::PATH_MAX_LENGTH);
    }

    private function resolveVisitorId(Request $request, Response $response): string
    {
        $cookieName = 'pa_vid';
        $existing = $request->getCookies()->getValue($cookieName);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $visitorId = bin2hex(random_bytes(16));
        $response->getCookies()->add(new Cookie([
            'name' => $cookieName,
            'value' => $visitorId,
            'expire' => time() + (400 * 24 * 60 * 60),
            'httpOnly' => true,
            'secure' => $request->getIsSecureConnection(),
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]));

        return $visitorId;
    }

    private function registerUniqueVisitor(string $date, string $visitorHash): bool
    {
        try {
            Craft::$app->getDb()->createCommand()->insert(self::DAILY_UNIQUE_VISITORS_TABLE, [
                'date' => $date,
                'visitorHash' => $visitorHash,
            ])->execute();

            return true;
        } catch (IntegrityException) {
            return false;
        }
    }

    private function clampDaysToEdition(int $days): int
    {
        $max = $this->getMaxDays();
        return min($days, $max);
    }

    private function rangeStart(int $days): string
    {
        $days = max($days, 1);
        return gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    }

    private function ensureStorageReady(): bool
    {
        if ($this->storageChecked) {
            return $this->storageReady;
        }

        $this->storageChecked = true;

        try {
            $db = Craft::$app->getDb();
            $schema = $db->getSchema();

            $missingDailyStats = $schema->getTableSchema(self::DAILY_STATS_TABLE, true) === null;
            $missingPageDailyStats = $schema->getTableSchema(self::PAGE_DAILY_STATS_TABLE, true) === null;
            $missingDailyUniqueVisitors = $schema->getTableSchema(self::DAILY_UNIQUE_VISITORS_TABLE, true) === null;

            if (!$missingDailyStats && !$missingPageDailyStats && !$missingDailyUniqueVisitors) {
                $this->storageReady = true;
                return true;
            }

            $transaction = $db->beginTransaction();

            if ($missingDailyStats) {
                $db->createCommand()->createTable(self::DAILY_STATS_TABLE, [
                    'date' => 'date NOT NULL',
                    'visits' => 'integer NOT NULL DEFAULT 0',
                    'uniqueVisitors' => 'integer NOT NULL DEFAULT 0',
                    'PRIMARY KEY([[date]])',
                ])->execute();
            }

            if ($missingPageDailyStats) {
                $db->createCommand()->createTable(self::PAGE_DAILY_STATS_TABLE, [
                    'date' => 'date NOT NULL',
                    'path' => 'varchar(191) NOT NULL',
                    'visits' => 'integer NOT NULL DEFAULT 0',
                    'PRIMARY KEY([[date]], [[path]])',
                ])->execute();
            }

            if ($missingDailyUniqueVisitors) {
                $db->createCommand()->createTable(self::DAILY_UNIQUE_VISITORS_TABLE, [
                    'date' => 'date NOT NULL',
                    'visitorHash' => 'char(64) NOT NULL',
                    'PRIMARY KEY([[date]], [[visitorHash]])',
                ])->execute();
            }

            if ($missingPageDailyStats && $schema->getTableSchema(self::PAGE_DAILY_STATS_TABLE, true) !== null) {
                $db->createCommand()->createIndex(
                    self::PAGE_PATH_INDEX,
                    self::PAGE_DAILY_STATS_TABLE,
                    ['path'],
                    false
                )->execute();
            }

            $transaction->commit();
            $this->storageReady = true;
            return true;
        } catch (\Throwable $exception) {
            if (isset($transaction) && $transaction->isActive) {
                $transaction->rollBack();
            }
            Craft::error('Analytics storage bootstrap failed: ' . $exception->getMessage(), __METHOD__);
            $this->storageReady = false;
            return false;
        }
    }
}
