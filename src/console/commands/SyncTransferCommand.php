<?php

declare(strict_types=1);

namespace pragmatic\webtoolkit\console\commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use pragmatic\webtoolkit\domains\sync\SyncProfiles;
use pragmatic\webtoolkit\domains\sync\SyncService;
use Throwable;

final class SyncTransferCommand extends Command
{
    use CraftCommand;

    public function __construct(private readonly string $component, private readonly bool $up)
    {
        $direction = $up ? 'up' : 'down';
        $this->signature = "craft:sync/$component/$direction
            {--profile= : Sync profile to use when Laravel also has a matching .env file}
            {--dry-run : Preview the transfer without changing either destination}";

        if ($component === 'assets') {
            $this->signature .= ' {--volume= : Transfer only this volume handle}';
        }
        if ($up && ($component === 'db' || $component === 'all')) {
            $this->signature .= ' {--confirm-db-replace= : Confirm replacement by naming the destination profile}';
        }

        $this->description = 'Sync ' . $component . ($up ? ' from local to the SSH server.' : ' from the SSH server to local.');
        $this->help = 'Uses the default profile from sync/setup. Select another SSH profile with --env=<profile>.';
        parent::__construct();
    }

    /** @return list<self> */
    public static function commands(): array
    {
        $commands = [];
        foreach (['all', 'code', 'db', 'assets'] as $component) {
            $commands[] = new self($component, false);
            $commands[] = new self($component, true);
        }

        return $commands;
    }

    public function handle(): int
    {
        try {
            $env = $this->option('env');
            $profile = $this->option('profile');
            if ($env !== null && $profile !== null) {
                throw new \InvalidArgumentException('Choose either --env or --profile.');
            }
            // Laravel may already have loaded .env.<name> when it sees --env.
            if ($env !== null && str_ends_with($this->laravel->environmentFile(), '.' . $env)) {
                throw new \InvalidArgumentException('Laravel selected a matching .env file. Use --profile=' . $env . ' to select only the SSH destination.');
            }

            $config = (new SyncProfiles($this->laravel->configPath('craft/sync.php')))->read();
            $name = $profile ?? $env ?? $config['default'];
            if (!is_string($name) || !isset($config['profiles'][$name])) {
                throw new \InvalidArgumentException($name === null
                    ? 'No default Sync profile. Run php craft sync/setup or select one with --env.'
                    : 'Unknown Sync profile: ' . $name);
            }

            (new SyncService($this->laravel->basePath(), $name, $config['profiles'][$name]))->transfer(
                $this->component,
                $this->up,
                (bool)$this->option('dry-run'),
                $this->component === 'assets' ? $this->option('volume') : null,
                $this->line(...),
                fn (): bool => $this->confirmDatabaseReplacement($name),
            );
            $this->info($this->option('dry-run') ? 'Preview complete.' : 'Sync complete.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function confirmDatabaseReplacement(string $name): bool
    {
        $provided = $this->option('confirm-db-replace');
        if ($provided !== null) {
            return $provided === $name;
        }
        if (!$this->input->isInteractive()) {
            return false;
        }

        return $this->ask('Type ' . $name . ' to replace the remote database') === $name;
    }
}
