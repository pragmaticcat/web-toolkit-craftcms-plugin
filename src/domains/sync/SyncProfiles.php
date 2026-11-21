<?php

declare(strict_types=1);

namespace pragmatic\webtoolkit\domains\sync;

use InvalidArgumentException;
use RuntimeException;

final class SyncProfiles
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return array{default: ?string, profiles: array<string, array<string, mixed>>} */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return ['default' => null, 'profiles' => []];
        }

        $config = require $this->path;
        if (!is_array($config) || !is_array($config['profiles'] ?? null)) {
            throw new InvalidArgumentException('Invalid Sync configuration: ' . $this->path);
        }

        if (isset($config['default']) && (!is_string($config['default']) || !isset($config['profiles'][$config['default']]))) {
            throw new InvalidArgumentException('The default Sync profile does not exist.');
        }

        return ['default' => $config['default'] ?? null, 'profiles' => $config['profiles']];
    }

    public function save(array $config): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the Sync configuration directory.');
        }

        $temporary = tempnam($directory, '.sync-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary Sync configuration file.');
        }

        try {
            $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($temporary, $contents) !== strlen($contents)
                || !chmod($temporary, 0666 & ~umask())
                || !rename($temporary, $this->path)) {
                throw new RuntimeException('Could not save the Sync configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function validate(string $name, array $profile): void
    {
        if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/', $name)) {
            throw new InvalidArgumentException('Use a profile name made of letters, digits, hyphens or underscores, starting with a letter.');
        }

        if (!preg_match('/\A(?:[a-zA-Z0-9_][a-zA-Z0-9_.-]*@)?[a-zA-Z0-9_][a-zA-Z0-9_.-]*\z/', $profile['ssh'] ?? '')) {
            throw new InvalidArgumentException('Enter an SSH alias or user@host. Configure ports and keys in ~/.ssh/config.');
        }

        self::absolutePath($profile['path'] ?? '');
        foreach (['remote', 'branch'] as $key) {
            if (!preg_match('~\A[a-zA-Z0-9_][a-zA-Z0-9_./-]*\z~', $profile[$key] ?? '')) {
                throw new InvalidArgumentException("Invalid Git $key.");
            }
        }

        if (!is_bool($profile['dedicatedDatabase'] ?? null) || !is_bool($profile['allowDatabaseUp'] ?? null)) {
            throw new InvalidArgumentException('Set dedicatedDatabase and allowDatabaseUp explicitly.');
        }

        if ($profile['allowDatabaseUp'] && !$profile['dedicatedDatabase']) {
            throw new InvalidArgumentException('Database upload requires a dedicated database at both ends.');
        }

        foreach (['build', 'maintenanceOn', 'maintenanceOff', 'workersStop', 'workersStart'] as $key) {
            if (!is_string($profile[$key] ?? null) || str_contains($profile[$key], "\0")) {
                throw new InvalidArgumentException("Invalid $key command.");
            }
        }

        if (trim($profile['maintenanceOn']) === '' || trim($profile['maintenanceOff']) === '') {
            throw new InvalidArgumentException('Configure the commands to enter and leave maintenance mode.');
        }

        if ((trim($profile['workersStop']) === '') !== (trim($profile['workersStart']) === '')) {
            throw new InvalidArgumentException('Configure both worker commands or leave both empty.');
        }

        if (!is_array($profile['volumes'] ?? null)) {
            throw new InvalidArgumentException('Invalid volume configuration.');
        }
        foreach ($profile['volumes'] as $handle => $volume) {
            if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/', (string)$handle) || !is_array($volume)) {
                throw new InvalidArgumentException('Invalid volume handle or configuration.');
            }
            self::absolutePath($volume['local'] ?? '');
            self::absolutePath($volume['remote'] ?? '');
        }
    }

    private static function absolutePath(string $path): void
    {
        if (!str_starts_with($path, '/') || trim($path, '/') === '' || preg_match('/[\x00-\x1f]/', $path)) {
            throw new InvalidArgumentException('Use an absolute directory path other than /.'.($path === '' ? '' : ' Invalid path: ' . $path));
        }
    }
}
