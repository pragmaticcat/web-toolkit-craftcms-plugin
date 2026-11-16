<?php

namespace pragmatic\webtoolkit\console\commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use pragmatic\webtoolkit\PragmaticWebToolkit;

/**
 * Esborra les files caducades del registre de 404.
 */
class RedirectsPruneCommand extends Command
{
    use CraftCommand;

    protected $signature = 'pragmatic-web-toolkit:redirects:prune-404s';

    protected $description = 'Prunes expired 404 log rows.';

    protected $aliases = ['pragmatic-web-toolkit/redirects/prune-404s'];

    public function handle(): int
    {
        $deleted = PragmaticWebToolkit::$plugin->redirects->prune404s();
        $this->line("Pruned {$deleted} expired 404 log rows.");

        return self::SUCCESS;
    }
}
