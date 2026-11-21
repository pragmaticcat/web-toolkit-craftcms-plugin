<?php

declare(strict_types=1);

namespace pragmatic\webtoolkit\domains\sync;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SyncService
{
    private const DATABASE_INFO = <<<'SCRIPT'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrapWithoutBootingProviders();
        $db = $app->make(\Illuminate\Database\Connection::class);
        if ($db->getDriverName() !== 'mysql' && $db->getDriverName() !== 'mariadb') {
            throw new \RuntimeException('Sync requires MySQL or MariaDB.');
        }
        $server = $db->getPdo()->query(
            'SELECT @@hostname AS host, @@port AS port, @@server_id AS server_id, VERSION() AS version, DATABASE() AS name'
        )->fetch(\PDO::FETCH_ASSOC);
        echo json_encode([...$server, 'prefix' => $db->getTablePrefix()], JSON_THROW_ON_ERROR);
        SCRIPT;

    private readonly SyncTransport $transport;

    public function __construct(
        private readonly string $root,
        private readonly string $name,
        private readonly array $profile,
    ) {
        SyncProfiles::validate($name, $profile);
        $this->transport = new SyncTransport($root, $profile['ssh'], $profile['path']);
    }

    public function checkSetup(): void
    {
        $this->checkTools(['ssh', 'rsync', 'php', 'git', 'composer'], ['rsync', 'php', 'git', 'composer', 'base64']);
        $this->checkCheckout(false);
        $this->checkCheckout(true);
        $this->checkRemoteBranch();
        $this->volumes();
        if ($this->profile['dedicatedDatabase']) {
            $this->checkDatabaseTools();
            $this->checkDatabases();
        }
    }

    /**
     * @param Closure(string): void $report
     * @param Closure(): bool $confirmDatabaseUp
     */
    public function transfer(
        string $component,
        bool $up,
        bool $dryRun,
        ?string $volume,
        Closure $report,
        Closure $confirmDatabaseUp,
    ): void {
        $code = $component === 'code' || $component === 'all';
        $database = $component === 'db' || $component === 'all';
        $assets = $component === 'assets' || $component === 'all';
        $volumes = $assets ? $this->volumes($volume) : [];
        $local = $this->root;
        $remote = $this->profile['ssh'] . ':' . $this->profile['path'];
        $report(sprintf('%s%s [%s] %s → %s', $dryRun ? 'Preview: ' : '', $component, $this->name, $up ? $local : $remote, $up ? $remote : $local));

        if ($component === 'assets') {
            if ($volumes === []) {
                throw new InvalidArgumentException('This Sync profile has no asset volumes.');
            }
            $this->checkTools(['rsync'], ['rsync', 'base64']);
        }

        $databaseEndpoints = [];
        if ($database) {
            if (!$this->profile['dedicatedDatabase']) {
                throw new InvalidArgumentException('Confirm that both installations use dedicated databases in sync/setup.');
            }
            if ($up && !$this->profile['allowDatabaseUp']) {
                throw new InvalidArgumentException('Database uploads are disabled for this Sync profile.');
            }
            $this->checkDatabaseTools();
            $databaseEndpoints = $this->checkDatabases($up);
            [$source, $destination] = $databaseEndpoints;
            $report("Database: {$source['name']} → {$destination['name']} (replace destination after backup)");
        }

        $commit = null;
        if ($code) {
            $this->checkTools(['git', 'composer'], ['git', 'composer']);
            $commit = $this->checkCode($up, true);
            $report('Commit: ' . $commit);
        }

        if ($dryRun) {
            foreach ($volumes as $handle => $paths) {
                $report($handle . ': ' . ($up ? $paths['local'] . ' → ' . $paths['remote'] : $paths['remote'] . ' → ' . $paths['local']));
                $changes = $this->transport->copyVolume($up, $paths['local'], $paths['remote'], true);
                if ($changes !== '') {
                    $report($changes);
                }
            }
            return;
        }

        if ($database && $up && !$confirmDatabaseUp()) {
            throw new InvalidArgumentException('Database replacement was not confirmed for ' . $this->name . '.');
        }

        $this->withWorkspaces(function (string $localWork, string $remoteWork, string $recovery) use (
            $up, $code, $database, $volumes, $commit, $databaseEndpoints, $report,
        ): void {
            $lastStep = 'preflight';
            $maintenance = false;
            try {
                if ($code) {
                    if ($commit !== $this->checkCode($up, false)) {
                        throw new RuntimeException('The source checkout changed during preflight. Run Sync again.');
                    }
                    $this->writeFile($up, $recovery . '/previous-commit', $this->git($up, ['rev-parse', 'HEAD']));
                }

                if ($database) {
                    $this->assertDatabaseEndpoints($up, $databaseEndpoints);
                    $this->prepareDatabase($up, $localWork, $remoteWork);
                    $lastStep = 'captured and transferred source database';
                }

                if (!$up) {
                    foreach ($volumes as $handle => $paths) {
                        $staged = $localWork . '/assets-' . $handle;
                        $this->run(false, ['mkdir', $staged], 'Stage local asset volume');
                        $this->transport->copyVolume(false, $staged, $paths['remote']);
                    }
                    if ($code && $commit !== $this->git(true, ['rev-parse', 'HEAD'])) {
                        throw new RuntimeException('The deployed commit changed during capture. Run Sync again.');
                    }
                    $lastStep = 'captured remote files';
                } else {
                    $maintenance = true;
                    $this->hook('maintenanceOn');
                    $this->hook('workersStop');
                    $lastStep = 'entered maintenance and stopped workers';
                }

                $needsBackup = $database || ($up && $code);
                if ($needsBackup) {
                    if ($database) {
                        $this->assertDatabaseEndpoints($up, $databaseEndpoints);
                    }
                    $this->backup($up, $recovery . '/database.sql');
                    $lastStep = 'saved destination database backup';
                }

                if ($code) {
                    $this->applyCode($up, $commit);
                    $lastStep = 'updated code and dependencies';
                }

                foreach ($volumes as $handle => $paths) {
                    $report('Assets: ' . $handle);
                    $backup = $recovery . '/assets-' . $handle;
                    $this->run($up, ['mkdir', $backup], 'Create asset recovery directory');
                    if ($up) {
                        $this->transport->copyVolume(true, $paths['local'], $paths['remote'], backup: $backup);
                    } else {
                        $this->run(false, [
                            'rsync', '-rtc', '--backup', '--backup-dir=' . $backup, '--',
                            $localWork . '/assets-' . $handle . '/', $paths['local'] . '/',
                        ], 'Apply local asset volume');
                    }
                    $lastStep = 'copied asset volume ' . $handle;
                }

                if ($database) {
                    $this->assertDatabaseEndpoints($up, $databaseEndpoints);
                    $this->run($up, ['php', 'craft', 'db/restore', ($up ? $remoteWork : $localWork) . '/incoming.sql', '--drop-all-tables', '--no-interaction'], 'Restore destination database', true);
                    $lastStep = 'restored destination database';
                }

                if ($up && $code) {
                    $this->run(true, ['php', 'craft', 'up', '--no-interaction'], 'Apply migrations and project config');
                    $lastStep = 'applied migrations and project config';
                }
                if ($database) {
                    $this->run($up, ['php', 'craft', 'clear-caches', 'data', '--no-interaction'], 'Clear destination data caches');
                    $lastStep = 'cleared destination data caches';
                }

                if ($up) {
                    $this->hook('workersStart');
                    $this->hook('maintenanceOff');
                    $maintenance = false;
                }
            } catch (Throwable $error) {
                if ($maintenance) {
                    $this->keepMaintenance($report);
                }
                $location = $up ? $this->profile['ssh'] . ':' . $recovery : $recovery;
                throw new RuntimeException($error->getMessage() . "\nLast completed step: $lastStep. Recovery directory: $location", 0, $error);
            }

            $report('Recovery directory: ' . ($up ? $this->profile['ssh'] . ':' : '') . $recovery);
        }, $up, $report);
    }

    private function checkCheckout(bool $remote): void
    {
        $this->run($remote, ['test', '-f', 'craft'], 'Find the Craft executable');
        $this->run($remote, ['test', '-f', 'composer.lock'], 'Find composer.lock');
        if ($this->git($remote, ['rev-parse', '--show-prefix']) !== '') {
            throw new RuntimeException('The project path must be the root of its Git checkout.');
        }
        $this->git($remote, ['remote', 'get-url', $this->profile['remote']]);
        $this->git($remote, ['check-ref-format', '--branch', $this->profile['branch']]);
    }

    private function checkTools(array $local, array $remote): void
    {
        $check = <<<'SCRIPT'
            for tool do
                if ! command -v "$tool" >/dev/null; then
                    printf 'Missing required tool: %s\n' "$tool" >&2
                    exit 1
                fi
            done
            SCRIPT;
        $this->run(false, ['sh', '-c', $check, 'sync', ...$local], 'Check local tools');
        $this->run(true, ['sh', '-c', $check, 'sync', ...$remote], 'Check remote tools');
    }

    private function volumes(?string $only = null): array
    {
        $configured = $this->profile['volumes'];
        if ($only !== null && !isset($configured[$only])) {
            throw new InvalidArgumentException('Unknown volume: ' . $only);
        }

        $paths = [];
        foreach ($configured as $handle => $volume) {
            foreach (['local' => false, 'remote' => true] as $side => $remote) {
                $paths[$handle][$side] = $this->run($remote, [
                    'php', '-r', <<<'SCRIPT'
                        $path = realpath($argv[1]);
                        $root = getcwd();
                        if ($path === false || !is_dir($path) || $path === '/' || $path === $root || preg_match('/[\x00-\x1f]/', $path)
                            || str_starts_with($root, $path . '/')) {
                            exit(1);
                        }
                        $storage = realpath('storage') ?: $root . '/storage';
                        foreach ([$root . '/.git', $root . '/config', $root . '/vendor', $storage . '/pwt-sync'] as $reserved) {
                            if ($path === $reserved || str_starts_with($path, $reserved . '/')
                                || str_starts_with($reserved, $path . '/')) {
                                exit(1);
                            }
                        }
                        echo $path;
                        SCRIPT,
                    '--', $volume[$side],
                ], "Validate $side path for volume $handle");
            }

            foreach ($paths as $other => $existing) {
                if ($other === $handle) {
                    continue;
                }
                foreach (['local', 'remote'] as $side) {
                    $first = $existing[$side] . '/';
                    $second = $paths[$handle][$side] . '/';
                    if (str_starts_with($first, $second) || str_starts_with($second, $first)) {
                        throw new InvalidArgumentException("The $side paths for $other and $handle overlap.");
                    }
                }
            }
        }

        return $only === null ? $paths : [$only => $paths[$only]];
    }

    private function checkCode(bool $up, bool $readOnly): string
    {
        foreach ([false, true] as $remote) {
            $this->checkCheckout($remote);
            $status = explode("\0", $this->git($remote, ['--no-optional-locks', 'status', '--porcelain', '--untracked-files=all', '-z']));
            $changes = array_diff($status, ['', '?? config/craft/sync.php']);
            if ($changes !== []) {
                throw new RuntimeException(($remote ? 'Remote' : 'Local') . ' checkout has uncommitted changes.');
            }
        }

        $this->checkRemoteBranch();
        $branch = $this->profile['branch'];
        $source = $this->git(!$up, ['rev-parse', 'HEAD']);
        if ($up) {
            $published = $this->git(false, ['ls-remote', '--exit-code', '--refs', $this->profile['remote'], "refs/heads/$branch"]);
            $published = explode("\t", $published)[0];
            $this->requireCommit($published);
        }

        if (!$readOnly) {
            $this->git($up, ['fetch', '--no-tags', $this->profile['remote'], "refs/heads/$branch"]);
            if ($up) {
                $published = $this->git(true, ['rev-parse', 'FETCH_HEAD']);
            }
        }

        $this->requireCommit($source);
        if ($up) {
            $this->checkAncestry($readOnly ? false : true, $source, $published, $readOnly, 'Check that the source commit has been published');
        }
        $destination = $this->git($up, ['rev-parse', 'HEAD']);
        $this->checkAncestry($up, $destination, $source, $readOnly, 'Check fast-forward to the selected commit');

        if (!$readOnly) {
            foreach ([$destination, $source] as $revision) {
                if ($this->git($up, ['ls-tree', '--name-only', $revision, '--', '.env']) !== '') {
                    throw new RuntimeException('A checkout tracks .env. Keep environment credentials outside Git.');
                }
            }
        }

        return $source;
    }

    private function checkRemoteBranch(): void
    {
        $branch = $this->profile['branch'];
        if ($this->git(true, ['symbolic-ref', '--short', 'HEAD']) !== $branch) {
            throw new RuntimeException("The remote checkout is not on $branch.");
        }
    }

    private function checkAncestry(bool $remote, string $ancestor, string $commit, bool $readOnly, string $action): void
    {
        if ($ancestor === $commit) {
            return;
        }
        if ($readOnly) {
            try {
                $this->git($remote, ['cat-file', '-e', $ancestor . '^{commit}']);
                $this->git($remote, ['cat-file', '-e', $commit . '^{commit}']);
            } catch (RuntimeException) {
                // A preview cannot fetch new objects. Execution repeats this check after fetching.
                return;
            }
        }
        $this->run($remote, ['git', 'merge-base', '--is-ancestor', $ancestor, $commit], $action);
    }

    private function requireCommit(string $commit): void
    {
        if (!preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $commit)) {
            throw new RuntimeException('Git did not return a valid commit.');
        }
    }

    private function applyCode(bool $remote, string $commit): void
    {
        $this->run($remote, ['git', 'merge', '--ff-only', '--no-edit', $commit], 'Update Git checkout');
        if ($this->git($remote, ['rev-parse', 'HEAD']) !== $commit) {
            throw new RuntimeException('The checkout did not reach the selected commit.');
        }
        $this->run($remote, ['composer', 'install', '--no-interaction', ...($remote ? ['--no-dev', '--optimize-autoloader'] : [])], 'Install Composer dependencies');
        if ($remote) {
            $this->hook('build');
        }
    }

    private function checkDatabaseTools(): void
    {
        $this->checkTools(['rsync', 'mysql', 'mysqldump'], ['rsync', 'mysql', 'mysqldump', 'base64']);
    }

    private function checkDatabases(bool $up = false): array
    {
        $local = $this->databaseInfo(false);
        $remote = $this->databaseInfo(true);

        if ($local['name'] === $remote['name'] && $local['host'] === $remote['host']
            && $local['port'] === $remote['port'] && $local['server_id'] === $remote['server_id']) {
            throw new RuntimeException('Both profiles appear to point to the same database.');
        }
        if ($local['prefix'] !== $remote['prefix']) {
            throw new RuntimeException('The Craft database table prefixes must match.');
        }
        if (stripos($local['version'], 'mariadb') !== false xor stripos($remote['version'], 'mariadb') !== false) {
            throw new RuntimeException('Use the same database engine at both ends (MySQL or MariaDB).');
        }

        return $up ? [$local, $remote] : [$remote, $local];
    }

    private function assertDatabaseEndpoints(bool $up, array $expected): void
    {
        if ($this->checkDatabases($up) !== $expected) {
            throw new RuntimeException('The Craft database connection changed after preflight. Check both environments before retrying.');
        }
    }

    private function databaseInfo(bool $remote): array
    {
        $info = json_decode($this->run($remote, ['php', '-r', self::DATABASE_INFO], 'Inspect the ' . ($remote ? 'remote' : 'local') . ' Craft database', true), true);
        if (!is_array($info) || empty($info['name']) || !isset($info['host'], $info['port'], $info['server_id'], $info['prefix'], $info['version'])) {
            throw new RuntimeException('Could not identify the Craft database.');
        }
        return $info;
    }

    private function prepareDatabase(bool $up, string $local, string $remote): void
    {
        $this->backup(!$up, ($up ? $local : $remote) . '/incoming.sql');
        $this->transport->copyFile($up, $local . '/incoming.sql', $remote . '/incoming.sql');
        $this->run($up, ['test', '-s', ($up ? $remote : $local) . '/incoming.sql'], 'Check that the transferred database dump is not empty');
        $hash = ['php', '-r', 'echo hash_file("sha256", $argv[1]);', '--'];
        if ($this->run(false, [...$hash, $local . '/incoming.sql'], 'Check local dump checksum')
            !== $this->run(true, [...$hash, $remote . '/incoming.sql'], 'Check remote dump checksum')) {
            throw new RuntimeException('The transferred database dump checksum does not match.');
        }
    }

    private function backup(bool $remote, string $path): void
    {
        $this->run($remote, ['php', 'craft', 'db/backup', $path, '--no-interaction'], 'Back up Craft database', true);
        $this->run($remote, ['test', '-s', $path], 'Check that the database dump is not empty');
    }

    private function withWorkspaces(Closure $operation, bool $up, Closure $report): void
    {
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $locks = [];
        $workspaces = [];
        try {
            foreach ([false, true] as $remote) {
                $base = $this->run($remote, ['php', '-r', <<<'SCRIPT'
                    umask(0077);
                    $storage = realpath('storage');
                    if ($storage === false || !is_dir($storage) || $storage === '/') {
                        exit(1);
                    }
                    $base = $storage . '/pwt-sync';
                    if (is_link($base) || (!is_dir($base) && !@mkdir($base, 0700) && !is_dir($base))
                        || (fileperms($base) & 0077) !== 0) {
                        exit(1);
                    }
                    echo $base;
                    SCRIPT,
                ], 'Open the private Sync storage directory');
                $lock = $base . '/lock';
                $this->run($remote, ['mkdir', $lock], 'Acquire Sync lock at ' . $lock);
                $locks[] = [$remote, $lock];
                $work = $base . '/work-' . $id;
                $this->run($remote, ['mkdir', '-m', '700', $work], 'Create private temporary directory');
                $workspaces[] = [$remote, $work];
            }

            $recovery = dirname($workspaces[(int)$up][1]) . '/recovery-' . $id;
            $this->run($up, ['mkdir', '-m', '700', $recovery], 'Create private recovery directory');
            $operation($workspaces[0][1], $workspaces[1][1], $recovery);
        } finally {
            foreach (array_reverse($workspaces) as [$remote, $path]) {
                $this->cleanup($remote, ['rm', '-rf', '--', $path], 'Remove temporary files at ' . $path, $report);
            }
            foreach (array_reverse($locks) as [$remote, $path]) {
                $this->cleanup($remote, ['rmdir', $path], 'Release Sync lock at ' . $path, $report);
            }
        }
    }

    private function keepMaintenance(Closure $report): void
    {
        foreach (['maintenanceOn', 'workersStop'] as $hook) {
            try {
                $this->hook($hook);
            } catch (RuntimeException $error) {
                $report($error->getMessage() . ' Check the server maintenance and worker status.');
            }
        }
    }

    private function cleanup(bool $remote, array $arguments, string $action, Closure $report): void
    {
        try {
            $this->run($remote, $arguments, $action);
        } catch (RuntimeException $error) {
            $report($error->getMessage());
        }
    }

    private function writeFile(bool $remote, string $path, string $contents): void
    {
        $this->run($remote, ['php', '-r', 'exit(file_put_contents($argv[1], $argv[2] . PHP_EOL) === false ? 1 : 0);', '--', $path, $contents], 'Record the previous commit');
    }

    private function hook(string $key): void
    {
        if (trim($this->profile[$key]) !== '') {
            $this->run(true, ['sh', '-c', $this->profile[$key]], 'Run ' . $key);
        }
    }

    private function git(bool $remote, array $arguments): string
    {
        return $this->run($remote, ['git', ...$arguments], ($remote ? 'Remote' : 'Local') . ' Git ' . implode(' ', $arguments));
    }

    private function run(bool $remote, array $arguments, string $action, bool $private = false): string
    {
        return $this->transport->run($remote, $arguments, $action, $private);
    }
}
