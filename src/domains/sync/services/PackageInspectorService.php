<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use craft\base\FsInterface;
use craft\helpers\FileHelper;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\sync\models\TransferManifestModel;
use yii\web\UploadedFile;
use ZipArchive;

class PackageInspectorService
{
    /**
     * @return array{stagingPath:string,packageName:string,manifest:TransferManifestModel,summary:array<string,mixed>,errors:array<int,string>,warnings:array<int,string>}
     */
    public function stageUpload(UploadedFile $file): array
    {
        $stagingPath = $this->createStagingDirectory();
        $packagePath = $stagingPath . DIRECTORY_SEPARATOR . 'package.zip';

        if (!copy($file->tempName, $packagePath)) {
            throw new \RuntimeException('Unable to copy uploaded package into staging.');
        }

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new \RuntimeException('Invalid ZIP package.');
        }

        $extractPath = $this->extractPath($stagingPath);
        FileHelper::createDirectory($extractPath);
        $zip->extractTo($extractPath);
        $zip->close();

        return $this->inspectStagingPath($stagingPath, $file->name ?: 'sync-package.zip');
    }

    /**
     * @return array{stagingPath:string,packageName:string,manifest:TransferManifestModel,summary:array<string,mixed>,errors:array<int,string>,warnings:array<int,string>}
     */
    public function inspectStagingPath(string $stagingPath, string $packageName = 'sync-package.zip'): array
    {
        $extractPath = $this->extractPath($stagingPath);
        $manifest = $this->loadManifest($extractPath);
        $checksums = $this->loadChecksums($extractPath);
        $includesDatabase = $manifest->includesDatabase();
        $includesAssets = $manifest->includesAssets();

        $errors = [];
        $warnings = [];

        if ($manifest->packageType !== 'pwt-sync') {
            $errors[] = 'Unsupported package type.';
        }
        if ($manifest->schemaVersion !== 1) {
            $errors[] = 'Unsupported package schema version.';
        }
        if (!$includesDatabase && !$includesAssets) {
            $errors[] = 'Package does not include database or assets.';
        }

        if ($includesDatabase) {
            if (($manifest->database['dumpFormat'] ?? '') !== 'pwt-mysql-tables-v1') {
                $errors[] = 'Unsupported database dump format.';
            }

            $databasePath = $extractPath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sql.gz';
            if (!is_file($databasePath)) {
                $errors[] = 'Package is missing database/database.sql.gz.';
            }

            $warnings[] = 'Database import replaces the target database.';
        }

        if ($includesAssets) {
            $warnings[] = 'Imported asset files will overwrite same-path local files. Existing local asset files that are not part of the package will remain on disk.';
        }

        $warnings = array_merge($warnings, array_values(array_map('strval', (array)$manifest->warnings)));
        $errors = array_merge($errors, $this->validateEnvironment($manifest));

        if (empty($errors) && !empty($checksums)) {
            $errors = array_merge($errors, $this->validateChecksums($extractPath, $checksums));
        }

        return [
            'stagingPath' => $stagingPath,
            'packageName' => $packageName,
            'manifest' => $manifest,
            'summary' => $this->buildSummary($manifest),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function pruneExpiredStagingDirectories(int $retentionHours): void
    {
        if ($retentionHours < 1) {
            return;
        }

        $basePath = $this->basePath();
        if (!is_dir($basePath)) {
            return;
        }

        $cutoff = time() - ($retentionHours * 3600);
        foreach (scandir($basePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $basePath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && filemtime($path) !== false && filemtime($path) < $cutoff) {
                FileHelper::removeDirectory($path);
            }
        }
    }

    public function cleanup(string $stagingPath): void
    {
        if (is_dir($stagingPath)) {
            FileHelper::removeDirectory($stagingPath);
        }
    }

    private function buildSummary(TransferManifestModel $manifest): array
    {
        $fileCount = 0;
        $totalBytes = 0;
        foreach ((array)$manifest->includedVolumes as $volume) {
            $fileCount += (int)($volume['fileCount'] ?? 0);
            $totalBytes += (int)($volume['totalBytes'] ?? 0);
        }

        return [
            'exportMode' => $manifest->normalizedExportMode(),
            'includesDatabase' => $manifest->includesDatabase(),
            'includesAssets' => $manifest->includesAssets(),
            'craftVersion' => $manifest->craftVersion,
            'pluginVersion' => $manifest->pluginVersion,
            'dbDriver' => $manifest->dbDriver,
            'dbEngine' => (string)($manifest->database['engine'] ?? ''),
            'serverVersion' => (string)($manifest->database['serverVersion'] ?? ''),
            'tablePrefix' => $manifest->tablePrefix,
            'createdAt' => $manifest->createdAt,
            'volumeCount' => count((array)$manifest->includedVolumes),
            'fileCount' => $fileCount,
            'tableCount' => (int)($manifest->database['tableCount'] ?? 0),
            'totalBytes' => $totalBytes + (int)($manifest->database['bytes'] ?? 0),
        ];
    }

    /**
     * @return string[]
     */
    private function validateEnvironment(TransferManifestModel $manifest): array
    {
        $errors = [];
        $pluginVersion = $this->pluginVersion();
        $db = Craft::$app->getDb();
        $includesDatabase = $manifest->includesDatabase();
        $includesAssets = $manifest->includesAssets();
        $targetInfo = $includesDatabase ? PragmaticWebToolkit::$plugin->syncDatabaseInspector->inspectCurrentDatabase() : ['engine' => ''];

        if (!isset(PragmaticWebToolkit::$plugin->domains->all()['sync'])) {
            $errors[] = 'The target environment does not have the Sync domain enabled in code.';
        }

        if ($this->majorMinor($manifest->craftVersion) !== $this->majorMinor(Craft::$app->getVersion())) {
            $errors[] = 'Craft version mismatch between package and target environment.';
        }

        if ($manifest->pluginVersion !== $pluginVersion) {
            $errors[] = 'Plugin version mismatch between package and target environment.';
        }

        if ($includesDatabase) {
            if ($manifest->dbDriver !== (string)$db->getDriverName()) {
                $errors[] = 'Database driver mismatch between package and target environment.';
            }

            if ($manifest->tablePrefix !== (string)$db->tablePrefix) {
                $errors[] = 'Database table prefix mismatch between package and target environment.';
            }

            if (!PragmaticWebToolkit::$plugin->syncDatabaseInspector->isMysqlCompatibleEngine((string)($manifest->database['engine'] ?? ''))) {
                $errors[] = 'Package database engine is not MySQL-compatible.';
            }

            if (!PragmaticWebToolkit::$plugin->syncDatabaseInspector->isMysqlCompatibleEngine((string)$targetInfo['engine'])) {
                $errors[] = 'Target database engine is not MySQL-compatible.';
            }
        }

        if ($includesAssets) {
            $currentVolumes = [];
            foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
                $rootPath = $this->resolveLocalRootPath(method_exists($volume, 'getFs') ? $volume->getFs() : null);
                if ($rootPath === null) {
                    $errors[] = sprintf('Target volume "%s" is not backed by a supported local filesystem.', $volume->name);
                    continue;
                }

                $currentVolumes[(string)$volume->handle] = $rootPath;
            }

            foreach ((array)$manifest->includedVolumes as $volume) {
                $handle = (string)($volume['handle'] ?? '');
                if ($handle === '' || !isset($currentVolumes[$handle])) {
                    $errors[] = sprintf('Target environment is missing local asset volume "%s".', $handle);
                    continue;
                }

                $rootPath = $currentVolumes[$handle];
                if (!is_dir($rootPath)) {
                    FileHelper::createDirectory($rootPath);
                }

                if (!is_dir($rootPath)) {
                    $errors[] = sprintf('Target volume "%s" root path could not be created.', $handle);
                    continue;
                }

                if (!is_writable($rootPath)) {
                    $errors[] = sprintf('Target volume "%s" root path is not writable.', $handle);
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string,string> $checksums
     * @return string[]
     */
    private function validateChecksums(string $extractPath, array $checksums): array
    {
        $errors = [];

        foreach ($checksums as $relativePath => $checksum) {
            $path = $extractPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($path)) {
                $errors[] = sprintf('Package checksum validation failed: missing file %s.', $relativePath);
                continue;
            }

            if (hash_file('sha256', $path) !== $checksum) {
                $errors[] = sprintf('Package checksum validation failed for %s.', $relativePath);
            }
        }

        return $errors;
    }

    private function loadManifest(string $extractPath): TransferManifestModel
    {
        $manifestPath = $extractPath . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestPath)) {
            throw new \RuntimeException('Package is missing manifest.json.');
        }

        $payload = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Package manifest.json is invalid.');
        }

        return new TransferManifestModel($payload);
    }

    /**
     * @return array<string,string>
     */
    private function loadChecksums(string $extractPath): array
    {
        $checksumsPath = $extractPath . DIRECTORY_SEPARATOR . 'checksums.json';
        if (!is_file($checksumsPath)) {
            return [];
        }

        $payload = json_decode((string)file_get_contents($checksumsPath), true);
        return is_array($payload) ? array_map('strval', $payload) : [];
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

    private function majorMinor(string $version): string
    {
        $parts = explode('.', $version);
        return implode('.', array_slice($parts, 0, 2));
    }

    private function createStagingDirectory(): string
    {
        $path = $this->basePath() . DIRECTORY_SEPARATOR . 'staged-' . uniqid('', true);
        FileHelper::createDirectory($path);
        if (!is_dir($path)) {
            throw new \RuntimeException('Unable to create a staging directory for the uploaded sync package.');
        }

        return $path;
    }

    private function basePath(): string
    {
        $path = rtrim(Craft::$app->getPath()->getTempPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pragmatic-sync';
        FileHelper::createDirectory($path);
        return $path;
    }

    private function extractPath(string $stagingPath): string
    {
        return $stagingPath . DIRECTORY_SEPARATOR . 'extracted';
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
}
