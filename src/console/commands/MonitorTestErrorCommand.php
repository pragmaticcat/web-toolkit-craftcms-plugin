<?php

namespace pragmatic\webtoolkit\console\commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use pragmatic\webtoolkit\errors\MonitorTestException;
use pragmatic\webtoolkit\PragmaticWebToolkit;

/**
 * Envia un error de prova a Pragmatic Web Monitor per comprovar que els errors de Craft
 * obren una incidència i arriben a les alertes.
 */
class MonitorTestErrorCommand extends Command
{
    use CraftCommand;

    protected $signature = 'pragmatic-web-toolkit:monitor:test-error';

    protected $description = 'Sends a test error to Pragmatic Web Monitor right away.';

    protected $aliases = ['pragmatic-web-toolkit/monitor/test-error'];

    public function handle(): int
    {
        $monitor = PragmaticWebToolkit::$plugin->monitor;

        if (!$monitor->isConfigured()) {
            $this->error('Pragmatic Web Monitor is not configured. Set PWT_MONITOR_SITE_ID, PWT_MONITOR_SECRET and PWT_MONITOR_EVENTS_URL.');
            return 78; // EX_CONFIG
        }

        $error = new MonitorTestException('Test error sent with `craft pragmatic-web-toolkit/monitor/test-error` at ' . gmdate('Y-m-d H:i:s') . ' UTC');

        try {
            $monitor->reportException($error, sendNow: true);
        } catch (\Throwable $e) {
            $this->error('The test error could not be sent: ' . $e->getMessage());
            return 69; // EX_UNAVAILABLE
        }

        $this->line('Test error sent. Pragmatic Web Monitor should open a "Craft errors" incident and send the alert within a minute.');
        $this->line('It resolves by itself once no new error arrives for 30 minutes.');

        return self::SUCCESS;
    }
}
