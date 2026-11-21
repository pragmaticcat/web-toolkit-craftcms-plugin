<?php

declare(strict_types=1);

namespace pragmatic\webtoolkit\domains\sync;

use RuntimeException;
use Symfony\Component\Process\Process;

final class SyncTransport
{
    private const SSH = ['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3'];

    public function __construct(
        private readonly string $localRoot,
        private readonly string $host,
        private readonly string $remoteRoot,
    ) {
    }

    public function run(bool $remote, array $arguments, string $action, bool $private = false): string
    {
        $workingDirectory = $this->localRoot;
        if ($remote) {
            $command = 'cd ' . self::quote($this->remoteRoot) . ' && exec ' . self::shell($arguments);
            $arguments = [...self::SSH, $this->host, $command];
        }

        return $this->process($arguments, $workingDirectory, $action, $private);
    }

    public function copyFile(bool $up, string $local, string $remote): void
    {
        $this->rsync($up, $local, $remote, false, false);
    }

    public function copyVolume(bool $up, string $local, string $remote, bool $dryRun = false, ?string $backup = null): string
    {
        return $this->rsync($up, rtrim($local, '/') . '/', rtrim($remote, '/') . '/', true, $dryRun, $backup);
    }

    private function rsync(bool $up, string $local, string $remote, bool $volume, bool $dryRun, ?string $backup = null): string
    {
        // The remote shell changes directory, so no remote path is parsed by rsync as a shell argument.
        $directory = $volume ? $remote : dirname($remote);
        $remotePath = $this->host . ':' . ($volume ? './' : basename($remote));
        $options = $volume ? ['-rtc'] : ['-t'];
        if ($dryRun) {
            $options[] = '-n';
            $options[] = '--itemize-changes';
        }
        // Some rsync clients split --rsync-path and remove its quotes before calling SSH.
        // Decode paths on the receiving shell with expansion splitting and globbing disabled.
        $remoteShell = 'set -f; IFS=; cd ' . self::decodePath($directory) . ' && exec rsync';
        if ($backup !== null) {
            $remoteShell .= ' --backup --backup-dir=' . self::decodePath($backup);
        }

        return $this->process([
            'rsync',
            ...$options,
            '-e', implode(' ', self::SSH),
            '--rsync-path=' . $remoteShell,
            '--',
            ...($up ? [$local, $remotePath] : [$remotePath, $local]),
        ], $this->localRoot, $volume ? 'Synchronize asset files' : 'Transfer database dump', !$volume);
    }

    private static function decodePath(string $path): string
    {
        return '$(printf %s ' . base64_encode($path) . ' | base64 -d)';
    }

    private function process(array $arguments, string $directory, string $action, bool $private): string
    {
        $process = new Process($arguments, $directory, ['GIT_TERMINAL_PROMPT' => '0'], null, null);
        $process->run();
        if (!$process->isSuccessful()) {
            $details = $private ? '' : trim($process->getErrorOutput());
            throw new RuntimeException($action . ' failed (exit ' . $process->getExitCode() . ').' . ($details === '' ? '' : "\n" . $details));
        }

        return rtrim($process->getOutput(), "\r\n");
    }

    private static function shell(array $arguments): string
    {
        return implode(' ', array_map(self::quote(...), $arguments));
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }
}
