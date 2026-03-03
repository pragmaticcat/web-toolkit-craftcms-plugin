<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use craft\base\FsInterface;
use craft\helpers\FileHelper;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PackageImportService
{
    /**
     * @return array{importedFiles:int,importedVolumes:int,executedStatements:int}
     */
    public function importStagedPackage(string $stagingPath, ?callable $progress = null): array
    {
        $extractPath = $stagingPath . DIRECTORY_SEPARATOR . 'extracted';
        $databaseGzipPath = $extractPath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $databaseSqlPath = $stagingPath . DIRECTORY_SEPARATOR . 'database.sql';

        if (!is_file($databaseGzipPath)) {
            throw new \RuntimeException('Staged package is missing the database backup.');
        }

        $this->gunzipFile($databaseGzipPath, $databaseSqlPath);

        if ($progress) {
            $progress('Restoring database tables', 0.45);
        }
        $restore = PragmaticWebToolkit::$plugin->syncMysqlRestore->restoreFromFile(
            $databaseSqlPath,
            function(string $label) use ($progress): void {
                if ($progress) {
                    $progress($label, 0.7);
                }
            }
        );

        $importedFiles = 0;
        $importedVolumes = 0;
        $assetsBasePath = $extractPath . DIRECTORY_SEPARATOR . 'assets';

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $rootPath = $this->resolveLocalRootPath(method_exists($volume, 'getFs') ? $volume->getFs() : null);
            if ($rootPath === null) {
                throw new \RuntimeException(sprintf('Volume "%s" is not backed by a supported local filesystem.', $volume->name));
            }

            $sourcePath = $assetsBasePath . DIRECTORY_SEPARATOR . $volume->handle;
            if (!is_dir($sourcePath)) {
                continue;
            }

            if ($progress) {
                $progress('Merging asset files', 0.85);
            }
            FileHelper::createDirectory($rootPath);
            $importedFiles += $this->mergeDirectory($sourcePath, $rootPath);
            $importedVolumes++;
        }

        @unlink($databaseSqlPath);

        return [
            'importedFiles' => $importedFiles,
            'importedVolumes' => $importedVolumes,
            'executedStatements' => (int)$restore['executedStatements'],
        ];
    }

    private function gunzipFile(string $sourcePath, string $targetPath): void
    {
        $source = gzopen($sourcePath, 'rb');
        $target = fopen($targetPath, 'wb');

        if (!$source || !$target) {
            if (is_resource($source)) {
                gzclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw new \RuntimeException('Unable to prepare the staged database dump for restore.');
        }

        while (!gzeof($source)) {
            fwrite($target, (string)gzread($source, 1024 * 1024));
        }

        gzclose($source);
        fclose($target);
    }

    private function mergeDirectory(string $sourcePath, string $targetPath): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $sourceFilePath = $fileInfo->getPathname();
            $relativePath = ltrim(str_replace($sourcePath, '', $sourceFilePath), DIRECTORY_SEPARATOR);
            $targetFilePath = $targetPath . DIRECTORY_SEPARATOR . $relativePath;
            $targetDir = dirname($targetFilePath);

            FileHelper::createDirectory($targetDir);
            if (!copy($sourceFilePath, $targetFilePath)) {
                throw new \RuntimeException(sprintf('Unable to merge imported asset file: %s', $relativePath));
            }

            $count++;
        }

        return $count;
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
