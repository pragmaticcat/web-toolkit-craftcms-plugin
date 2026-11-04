<?php

namespace pragmatic\webtoolkit\console\controllers;

use craft\console\Controller;
use pragmatic\webtoolkit\errors\MonitorTestException;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\console\ExitCode;

/**
 * Console commands for the Pragmatic Web Monitor integration.
 */
class MonitorController extends Controller
{
    /**
     * Sends a test error to Pragmatic Web Monitor right away, so you can check that
     * Craft errors open an incident and reach your alerts.
     *
     * @return int Console exit code.
     */
    public function actionTestError(): int
    {
        $monitor = PragmaticWebToolkit::$plugin->monitor;

        if (!$monitor->isConfigured()) {
            $this->stderr("Pragmatic Web Monitor is not configured. Set PWT_MONITOR_SITE_ID, PWT_MONITOR_SECRET and PWT_MONITOR_EVENTS_URL.\n");
            return ExitCode::CONFIG;
        }

        $error = new MonitorTestException('Test error sent with `craft pragmatic-web-toolkit/monitor/test-error` at '.gmdate('Y-m-d H:i:s').' UTC');

        try {
            $monitor->reportException($error, sendNow: true);
        } catch (\Throwable $e) {
            $this->stderr('The test error could not be sent: '.$e->getMessage()."\n");
            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("Test error sent. Pragmatic Web Monitor should open a \"Craft errors\" incident and send the alert within a minute.\n");
        $this->stdout("It resolves by itself once no new error arrives for 30 minutes.\n");

        return ExitCode::OK;
    }
}
