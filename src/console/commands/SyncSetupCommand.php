<?php

declare(strict_types=1);

namespace pragmatic\webtoolkit\console\commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use pragmatic\webtoolkit\domains\sync\SyncProfiles;
use pragmatic\webtoolkit\domains\sync\SyncService;
use Throwable;

final class SyncSetupCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:sync/setup';
    protected $description = 'Create or edit an SSH profile for Sync.';

    public function handle(): int
    {
        if (!$this->input->isInteractive()) {
            $this->error('Sync setup requires an interactive terminal.');
            return self::FAILURE;
        }

        try {
            if ($this->option('env') !== null) {
                throw new \InvalidArgumentException('Select the profile at the prompt when running sync/setup.');
            }

            $path = $this->laravel->configPath('craft/sync.php');
            $store = new SyncProfiles($path);
            $config = $store->read();
            if ($config['profiles'] !== []) {
                $this->line('Existing profiles: ' . implode(', ', array_keys($config['profiles'])));
            }
            $name = (string)$this->ask('Profile name to create or edit', $config['default']);
            $current = $config['profiles'][$name] ?? [];

            $profile = [
                'ssh' => (string)$this->ask('SSH alias or user@host', $current['ssh'] ?? null),
                'path' => (string)$this->ask('Absolute path to the remote project', $current['path'] ?? null),
                'remote' => (string)$this->ask('Git remote name on both checkouts', $current['remote'] ?? 'origin'),
                'branch' => (string)$this->ask('Remote checkout branch', $current['branch'] ?? 'main'),
                'build' => $this->optionalCommand('Remote build command', $current['build'] ?? ''),
                'maintenanceOn' => (string)$this->ask('Enter remote maintenance', $current['maintenanceOn'] ?? 'php artisan down'),
                'maintenanceOff' => (string)$this->ask('Leave remote maintenance', $current['maintenanceOff'] ?? 'php artisan up'),
                'workersStop' => $this->optionalCommand('Stop and wait for remote workers', $current['workersStop'] ?? ''),
                'workersStart' => $this->optionalCommand('Start remote workers', $current['workersStart'] ?? ''),
            ];

            $profile['dedicatedDatabase'] = $this->confirm(
                'Are both databases dedicated to this Craft project? This enables database downloads.',
                $current['dedicatedDatabase'] ?? false,
            );
            $profile['allowDatabaseUp'] = $profile['dedicatedDatabase'] && $this->confirm(
                'Allow this profile to replace its remote database with the local copy?',
                $current['allowDatabaseUp'] ?? false,
            );
            $profile['volumes'] = $this->configureVolumes($current['volumes'] ?? []);

            $this->info('Checking the local checkout, SSH connection and configured tools.');
            (new SyncService($this->laravel->basePath(), $name, $profile))->checkSetup();

            $config['profiles'][$name] = $profile;
            if ($this->confirm('Use this profile by default?', $config['default'] === null || $config['default'] === $name)) {
                $config['default'] = $name;
            } elseif ($config['default'] === $name) {
                $config['default'] = null;
            }
            $this->table(['Setting', 'Value'], [
                ['Profile', $name],
                ['SSH checkout', $profile['ssh'] . ':' . $profile['path']],
                ['Git', $profile['remote'] . '/' . $profile['branch']],
                ['Volumes', implode(', ', array_keys($profile['volumes'])) ?: 'None'],
                ['Database download', $profile['dedicatedDatabase'] ? 'Enabled' : 'Disabled'],
                ['Database upload', $profile['allowDatabaseUp'] ? 'Enabled' : 'Disabled'],
                ['Default profile', $config['default'] ?? 'None'],
            ]);

            if ($this->confirm('Save this configuration?', true)) {
                $store->save($config);
                $this->info('Saved ' . $path);
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function optionalCommand(string $prompt, string $current): string
    {
        $command = trim((string)$this->ask($prompt . ' (use - for none)', $current ?: '-'));

        return $command === '-' ? '' : $command;
    }

    private function configureVolumes(array $volumes): array
    {
        while (true) {
            if ($volumes !== []) {
                $this->line('Configured volumes: ' . implode(', ', array_keys($volumes)));
            }
            $action = $this->choice('Asset volumes', ['Keep current paths', 'Add or edit a volume', 'Remove a volume'], 0);
            if ($action === 'Keep current paths') {
                return $volumes;
            }
            if ($action === 'Remove a volume') {
                if ($volumes !== []) {
                    $handle = (string)$this->choice('Volume to remove', array_keys($volumes));
                    unset($volumes[$handle]);
                }
                continue;
            }

            $handle = (string)$this->ask('Craft volume handle');
            $current = $volumes[$handle] ?? [];
            $volumes[$handle] = [
                'local' => (string)$this->ask('Absolute path to local originals', $current['local'] ?? null),
                'remote' => (string)$this->ask('Absolute path to remote originals', $current['remote'] ?? null),
            ];
        }
    }
}
