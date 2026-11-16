<?php

namespace pragmatic\webtoolkit\domains\analytics\services;

use CraftCms\Cms\Support\Url;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\db\Expression;
use yii\db\IntegrityException;
use yii\web\Cookie;
use yii\web\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use pragmatic\webtoolkit\db\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsService
{
    private const PATH_MAX_LENGTH = 191;
    private const PAGE_PATH_INDEX = 'pwt_analytics_page_daily_stats_path_idx';

    public const DAILY_STATS_TABLE = 'pragmatic_toolkit_analytics_daily_stats';
    public const PAGE_DAILY_STATS_TABLE = 'pragmatic_toolkit_analytics_page_daily_stats';
    public const DAILY_UNIQUE_VISITORS_TABLE = 'pragmatic_toolkit_analytics_daily_unique_visitors';

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
        $visitorId = $this->resolveVisitorId($request, $response);
        $visitorHash = hash('sha256', $visitorId);

        DB::beginTransaction();
        try {
            Command::make()->upsert(
                self::DAILY_STATS_TABLE,
                ['date' => $today, 'visits' => 1, 'uniqueVisitors' => 0],
                ['visits' => new Expression('[[visits]] + 1')]
            )->execute();

            Command::make()->upsert(
                self::PAGE_DAILY_STATS_TABLE,
                ['date' => $today, 'path' => $normalizedPath, 'visits' => 1],
                ['visits' => new Expression('[[visits]] + 1')]
            )->execute();

            if ($this->registerUniqueVisitor($today, $visitorHash)) {
                Command::make()->update(
                    self::DAILY_STATS_TABLE,
                    ['uniqueVisitors' => new Expression('[[uniqueVisitors]] + 1')],
                    ['date' => $today]
                )->execute();
            }

            DB::commit();
            return true;
        } catch (\Throwable $exception) {
            DB::rollBack();
            Log::error('Analytics tracking failed: ' . $exception->getMessage());
            return false;
        }
    }

    public function getOverview(int $days = 30): array
    {
        if (!$this->ensureStorageReady()) {
            return ['visits' => 0, 'uniqueVisitors' => 0];
        }

        $days = $this->clampDaysToEdition($days);

        $row = (new \pragmatic\webtoolkit\db\Query())
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

        return (new \pragmatic\webtoolkit\db\Query())
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

        return (new \pragmatic\webtoolkit\db\Query())
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
        return 30;
    }

    public function renderFrontendScripts(): string
    {
        $settings = PragmaticWebToolkit::$plugin->analyticsSettings->get();
        $scripts = '';

        if ($settings->enableTracking) {
            $request = request();
            if (!$this->shouldSkipTracking($request)) {
                $trackUrl = $this->getTrackUrl();
                $csrfTokenName = json_encode($request->csrfParam, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                $csrfToken = json_encode($request->getCsrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                $requireConsent = $settings->requireConsent ? 'true' : 'false';
                $scripts .= "<script>(() => {\n" .
                    "  if (" . $requireConsent . ") {\n" .
                    "    const hasConsent = window.PragmaticAnalyticsConsent === true || document.cookie.includes('pa_consent=1');\n" .
                    "    if (!hasConsent) return;\n" .
                    "  }\n" .
                    "  const path = window.location.pathname + window.location.search;\n" .
                    "  const body = new URLSearchParams({ p: path });\n" .
                    "  body.set(" . $csrfTokenName . ", " . $csrfToken . ");\n" .
                    "  fetch('" . addslashes($trackUrl) . "', { method: 'POST', body, credentials: 'same-origin', keepalive: true, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});\n" .
                    "})();</script>";
            }
        }

        if ($settings->injectGaScript && $settings->gaMeasurementId !== '') {
            $id = htmlspecialchars($settings->gaMeasurementId, ENT_QUOTES, 'UTF-8');
            $gaScripts = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($id) . '"></script>';
            $gaScripts .= "<script>(() => {\n" .
                "  if (!window.dataLayer) window.dataLayer = [];\n" .
                "  const gtag = function(){window.dataLayer.push(arguments);};\n" .
                "  window.gtag = window.gtag || gtag;\n" .
                "  gtag('js', new Date());\n" .
                "  gtag('config', " . json_encode($id) . ");\n" .
                "})();</script>";
            if ($settings->gaConsentCategory !== '') {
                if (PragmaticWebToolkit::$plugin->domains->isEnabled('cookies')) {
                    $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories();
                    if (in_array($settings->gaConsentCategory, array_map(static fn($category): string => $category->handle, $categories), true)) {
                        $scripts .= PragmaticWebToolkit::$plugin->externalCode->renderConditional($settings->gaConsentCategory, $gaScripts);
                    }
                }
            } else {
                $scripts .= $gaScripts;
            }
        }

        return $scripts;
    }

    /**
     * Return the front-end tracking endpoint URL.
     *
     * @return string Tracking endpoint URL.
     */
    protected function getTrackUrl(): string
    {
        return Url::siteUrl('pragmatic-toolkit/analytics/track');
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

        if ($settings->excludeBots && $this->isLikelyBot($request->userAgent() ?? '')) {
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
            'secure' => $request->secure(),
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]));

        return $visitorId;
    }

    private function registerUniqueVisitor(string $date, string $visitorHash): bool
    {
        try {
            Command::make()->insert(self::DAILY_UNIQUE_VISITORS_TABLE, [
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
        if (!$this->storageChecked) {
            $this->storageChecked = true;
            $this->storageReady = Schema::hasTable(self::DAILY_STATS_TABLE)
                && Schema::hasTable(self::PAGE_DAILY_STATS_TABLE)
                && Schema::hasTable(self::DAILY_UNIQUE_VISITORS_TABLE);
        }

        return $this->storageReady;
    }
}
