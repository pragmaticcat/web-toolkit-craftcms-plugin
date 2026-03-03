<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use craft\base\FsInterface;
use craft\helpers\FileHelper;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class PackageBuilderService
{
    /**
     * @return array{zipPath:string,downloadName:string,manifest:array<string,mixed>,summary:array<string,mixed>}
     */
    public function buildPackage(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is required to export sync packages.');
        }

        $this->assertDatabaseCommandSupport();

        $tempDir = $this->createTempDirectory('export-');
        $sqlPath = $tempDir . DIRECTORY_SEPARATOR . 'database.sql';
        $sqlGzPath = $tempDir . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $zipPath = $tempDir . DIRECTORY_SEPARATOR . 'sync-package.zip';
        $db = Craft::$app->getDb();

        if (!method_exists($db, 'backupTo')) {
            throw new RuntimeException('The current Craft database connection does not support backup exports.');
        }

        $db->backupTo($sqlPath);
        $this->gzipFile($sqlPath, $sqlGzPath);
        @unlink($sqlPath);

        $databaseRelativePath = 'database/database.sql.gz';
        $checksums = [
            $databaseRelativePath => hash_file('sha256', $sqlGzPath),
        ];

        $totalFileCount = 0;
        $totalBytes = filesize($sqlGzPath) ?: 0;
        $volumes = [];

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create sync package ZIP.');
        }

        $zip->addFile($sqlGzPath, $databaseRelativePath);

        foreach ($this->localVolumes() as $volumeInfo) {
            $fileCount = 0;
            $byteCount = 0;
            foreach ($this->iterateFiles($volumeInfo['rootPath']) as [$absolutePath, $relativePath, $size]) {
                $zipRelativePath = 'assets/' . $volumeInfo['handle'] . '/' . $relativePath;
                $zip->addFile($absolutePath, $zipRelativePath);
                $checksums[$zipRelativePath] = hash_file('sha256', $absolutePath);
                $fileCount++;
                $byteCount += $size;
                $totalFileCount++;
                $totalBytes += $size;
            }

            $volumes[] = [
                'handle' => $volumeInfo['handle'],
                'name' => $volumeInfo['name'],
                'uid' => $volumeInfo['uid'],
                'rootPath' => $volumeInfo['rootPath'],
                'fileCount' => $fileCount,
                'totalBytes' => $byteCount,
            ];
        }

        $manifest = [
            'schemaVersion' => 1,
            'packageType' => 'pwt-sync',
            'createdAt' => gmdate(DATE_ATOM),
            'sourceSiteName' => (string)Craft::$app->getSites()->getPrimarySite()->name,
            'sourceCpUrl' => $this->sourceCpUrl(),
            'craftVersion' => Craft::$app->getVersion(),
            'pluginVersion' => $this->pluginVersion(),
            'phpVersion' => PHP_VERSION,
            'dbDriver' => (string)$db->getDriverName(),
            'tablePrefix' => (string)$db->tablePrefix,
            'includedVolumes' => $volumes,
            'database' => [
                'filename' => 'database.sql.gz',
                'compression' => 'gzip',
                'checksum' => $checksums[$databaseRelativePath],
                'bytes' => filesize($sqlGzPath) ?: 0,
            ],
            'packageChecksumVersion' => 1,
        ];

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('checksums.json', json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return [
            'zipPath' => $zipPath,
            'downloadName' => sprintf('pwt-sync-%s.zip', gmdate('Ymd-His')),
            'manifest' => $manifest,
            'summary' => [
                'dbDriver' => (string)$db->getDriverName(),
                'volumeCount' => count($volumes),
                'fileCount' => $totalFileCount,
                'totalBytes' => $totalBytes,
            ],
        ];
    }

    public function hasDatabaseCommandSupport(): bool
    {
        return function_exists('proc_open');
    }

    public function databaseCommandRequirementMessage(): string
    {
        return 'Sync database export/import requires PHP proc_open() to be enabled because Craft runs database backup/restore through shell commands.';
    }

    private function sourceCpUrl(): string
    {
        $request = Craft::$app->getRequest();
        $hostInfo = rtrim((string)$request->getHostInfo(), '/');
        $cpTrigger = trim((string)Craft::$app->getConfig()->getGeneral()->cpTrigger, '/');

        return $cpTrigger === '' ? $hostInfo : $hostInfo . '/' . $cpTrigger;
    }

    private function assertDatabaseCommandSupport(): void
    {
        if (!$this->hasDatabaseCommandSupport()) {
            throw new RuntimeException($this->databaseCommandRequirementMessage());
        }
    }

    private function pluginVersion(): string
    {
        $plugin = PragmaticWebToolkit::$plugin;

        if (method_exists($plugin, 'getVersion')) {
            $version = (string)$plugin->getVersion();
            if ($version !== '') {
                return $version;
            }
        }

        return $plugin->schemaVersion;
    }

    /**
     * @return array<int, array{handle:string,name:string,uid:string,rootPath:string}>
     */
    private function localVolumes(): array
    {
        $volumes = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $rootPath = $this->resolveLocalRootPath(method_exists($volume, 'getFs') ? $volume->getFs() : null);
            if ($rootPath === null) {
                throw new RuntimeException(sprintf('Volume "%s" is not backed by a supported local filesystem.', $volume->name));
            }

            if (!is_dir($rootPath)) {
                FileHelper::createDirectory($rootPath);
            }

            if (!is_dir($rootPath)) {
                throw new RuntimeException(sprintf('Volume "%s" root path could not be prepared: %s', $volume->name, $rootPath));
            }

            $volumes[] = [
                'handle' => (string)$volume->handle,
                'name' => (string)$volume->name,
                'uid' => (string)$volume->uid,
                'rootPath' => $rootPath,
            ];
        }

        return $volumes;
    }

    /**
     * @return iterable<int, array{0:string,1:string,2:int}>
     */
    private function iterateFiles(string $rootPath): iterable
    {
        if (!is_dir($rootPath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            $relativePath = ltrim(str_replace($rootPath, '', $absolutePath), DIRECTORY_SEPARATOR);

            yield [$absolutePath, str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), (int)$fileInfo->getSize()];
        }
    }

    private function resolveLocalRootPath(?FsInterface $fs): ?string
    {
        if ($fs === null) {
            return null;
        }

        $rootPath = null;
        if (method_exists($fs, 'getRootPath')) {
            $rootPath = $fs->getRootPath();
        } elseif (property_exists($fs, 'rootPath')) {
            /** @phpstan-ignore-next-line */
            $rootPath = $fs->rootPath;
        }

        if (!is_string($rootPath) || $rootPath === '') {
            return null;
        }

        $resolvedPath = Craft::getAlias($rootPath, false);
        if (is_string($resolvedPath) && $resolvedPath !== '') {
            $rootPath = $resolvedPath;
        }

        $isAbsolute = str_starts_with($rootPath, DIRECTORY_SEPARATOR) || (bool)preg_match('/^[A-Za-z]:[\\\\\\/]/', $rootPath);
        $looksLocal = str_contains(strtolower($fs::class), 'local');

        return ($looksLocal || $isAbsolute) ? rtrim($rootPath, DIRECTORY_SEPARATOR) : null;
    }

    private function createTempDirectory(string $prefix): string
    {
        $base = rtrim(Craft::$app->getPath()->getTempPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pragmatic-sync';
        FileHelper::createDirectory($base);

        $path = $base . DIRECTORY_SEPARATOR . $prefix . uniqid('', true);
        FileHelper::createDirectory($path);
        if (!is_dir($path)) {
            throw new RuntimeException('Unable to create a temporary sync directory.');
        }

        return $path;
    }

    private function gzipFile(string $sourcePath, string $targetPath): void
    {
        $source = fopen($sourcePath, 'rb');
        $target = gzopen($targetPath, 'wb9');

        if (!$source || !$target) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                gzclose($target);
            }
            throw new RuntimeException('Unable to compress database backup.');
        }

        while (!feof($source)) {
            gzwrite($target, (string)fread($source, 1024 * 1024));
        }

        fclose($source);
        gzclose($target);
    }
}
