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
     * @return array{zipPath:string,downloadName:string,manifest:array<string,mixed>,summary:array<string,mixed>,warnings:array<int,string>}
     */
    public function buildPackage(string $exportMode = 'both', ?callable $progress = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is required to export sync packages.');
        }

        $exportMode = $this->normalizeExportMode($exportMode);
        $settings = PragmaticWebToolkit::$plugin->syncSettings->get();
        $tempDir = $this->createTempDirectory('export-');
        $db = Craft::$app->getDb();
        $includesDatabase = $exportMode !== 'assets';
        $includesAssets = $exportMode !== 'db';
        $sqlGzPath = null;
        $dumpMetadata = [
            'engine' => '',
            'serverVersion' => '',
            'charset' => '',
            'collation' => '',
            'tableCount' => 0,
            'rowCountEstimate' => 0,
            'unsupportedObjects' => [
                'views' => [],
                'triggers' => [],
                'routines' => [],
                'events' => [],
            ],
            'dumpFormat' => '',
            'tables' => [],
            'warnings' => [],
        ];

        if ($includesDatabase) {
            $sqlPath = $tempDir . DIRECTORY_SEPARATOR . 'database.sql';
            $sqlGzPath = $tempDir . DIRECTORY_SEPARATOR . 'database.sql.gz';

            if ($progress) {
                $progress('Inspecting database', 0.05);
            }
            $dumpMetadata = PragmaticWebToolkit::$plugin->syncMysqlDump->dumpToFile(
                $sqlPath,
                $settings->insertBatchRowCount,
                $settings->selectChunkSize,
                function(string $label) use ($progress): void {
                    if ($progress) {
                        $progress($label, 0.25);
                    }
                }
            );

            $this->gzipFile($sqlPath, $sqlGzPath);
            @unlink($sqlPath);
        }

        if ($progress && $includesAssets) {
            $progress('Packaging assets', $includesDatabase ? 0.45 : 0.2);
        }

        $checksums = [];
        $totalFileCount = 0;
        $totalBytes = 0;
        $volumes = [];
        $localVolumes = $includesAssets ? $this->localVolumes() : [];

        $downloadName = sprintf('pwt-sync-%s.zip', gmdate('Ymd-His'));
        $zipPath = PragmaticWebToolkit::$plugin->syncExportArtifacts->artifactPathForFilename($downloadName);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create sync package ZIP.');
        }

        if ($includesDatabase && $sqlGzPath !== null) {
            $zip->addFile($sqlGzPath, 'database/database.sql.gz');
            $checksums['database/database.sql.gz'] = hash_file('sha256', $sqlGzPath);
            $totalBytes += (int)(filesize($sqlGzPath) ?: 0);
        }

        foreach ($localVolumes as $volumeIndex => $volumeInfo) {
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

            if ($progress) {
                $start = $includesDatabase ? 0.45 : 0.2;
                $end = $includesDatabase ? 0.8 : 0.7;
                $progress('Packaging assets', min($end, $start + (($volumeIndex + 1) / max(1, count($localVolumes))) * ($end - $start)));
            }
        }

        $warnings = $dumpMetadata['warnings'];
        $manifest = [
            'schemaVersion' => 1,
            'packageType' => 'pwt-sync',
            'exportMode' => $exportMode,
            'createdAt' => gmdate(DATE_ATOM),
            'sourceSiteName' => (string)Craft::$app->getSites()->getPrimarySite()->name,
            'sourceCpUrl' => $this->sourceCpUrl(),
            'craftVersion' => Craft::$app->getVersion(),
            'pluginVersion' => $this->pluginVersion(),
            'phpVersion' => PHP_VERSION,
            'dbDriver' => (string)$db->getDriverName(),
            'tablePrefix' => (string)$db->tablePrefix,
            'includedVolumes' => $volumes,
            'database' => $includesDatabase ? [
                'filename' => 'database.sql.gz',
                'compression' => 'gzip',
                'checksum' => $checksums['database/database.sql.gz'],
                'bytes' => (int)(filesize($sqlGzPath) ?: 0),
                'engine' => $dumpMetadata['engine'],
                'serverVersion' => $dumpMetadata['serverVersion'],
                'charset' => $dumpMetadata['charset'],
                'collation' => $dumpMetadata['collation'],
                'tableCount' => $dumpMetadata['tableCount'],
                'rowCountEstimate' => $dumpMetadata['rowCountEstimate'],
                'unsupportedObjects' => $dumpMetadata['unsupportedObjects'],
                'dumpFormat' => $dumpMetadata['dumpFormat'],
                'tables' => $dumpMetadata['tables'],
            ] : [],
            'warnings' => $warnings,
            'packageChecksumVersion' => 1,
        ];

        if ($progress) {
            $progress('Writing manifest', 0.9);
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('checksums.json', json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($progress) {
            $progress('Finalizing archive', 0.98);
        }
        $zip->close();
        if ($sqlGzPath !== null) {
            @unlink($sqlGzPath);
        }

        return [
            'zipPath' => $zipPath,
            'downloadName' => $downloadName,
            'manifest' => $manifest,
            'summary' => [
                'exportMode' => $exportMode,
                'dbEngine' => $dumpMetadata['engine'],
                'tableCount' => $dumpMetadata['tableCount'],
                'volumeCount' => count($volumes),
                'fileCount' => $totalFileCount,
                'totalBytes' => $totalBytes,
            ],
            'warnings' => $warnings,
        ];
    }

    private function sourceCpUrl(): string
    {
        $request = Craft::$app->getRequest();
        $hostInfo = rtrim((string)$request->getHostInfo(), '/');
        $cpTrigger = trim((string)Craft::$app->getConfig()->getGeneral()->cpTrigger, '/');

        return $cpTrigger === '' ? $hostInfo : $hostInfo . '/' . $cpTrigger;
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
            throw new RuntimeException('Unable to compress database dump.');
        }

        while (!feof($source)) {
            gzwrite($target, (string)fread($source, 1024 * 1024));
        }

        fclose($source);
        gzclose($target);
    }

    private function normalizeExportMode(string $exportMode): string
    {
        return match ($exportMode) {
            'db', 'assets', 'both' => $exportMode,
            default => throw new RuntimeException('Unsupported export mode.'),
        };
    }
}
