<?php

namespace pragmatic\webtoolkit\domains\sync\models;

use craft\base\Model;

class TransferManifestModel extends Model
{
    public int $schemaVersion = 1;
    public string $packageType = 'pwt-sync';
    public string $exportMode = 'both';
    public string $createdAt = '';
    public string $sourceSiteName = '';
    public string $sourceCpUrl = '';
    public string $craftVersion = '';
    public string $pluginVersion = '';
    public string $phpVersion = '';
    public string $dbDriver = '';
    public string $tablePrefix = '';
    public array $includedVolumes = [];
    public array $database = [];
    public array $warnings = [];
    public int $packageChecksumVersion = 1;

    public function rules(): array
    {
        return [
            [['schemaVersion', 'packageChecksumVersion'], 'integer'],
            [['packageType', 'exportMode', 'createdAt', 'sourceSiteName', 'sourceCpUrl', 'craftVersion', 'pluginVersion', 'phpVersion', 'dbDriver', 'tablePrefix'], 'string'],
            [['includedVolumes', 'database', 'warnings'], 'safe'],
        ];
    }

    public function includesDatabase(): bool
    {
        return $this->normalizedExportMode() !== 'assets';
    }

    public function includesAssets(): bool
    {
        return $this->normalizedExportMode() !== 'db';
    }

    public function normalizedExportMode(): string
    {
        if (in_array($this->exportMode, ['db', 'assets', 'both'], true)) {
            return $this->exportMode;
        }

        $hasDatabase = !empty($this->database);
        $hasAssets = !empty($this->includedVolumes);

        if ($hasDatabase && $hasAssets) {
            return 'both';
        }

        if ($hasDatabase) {
            return 'db';
        }

        if ($hasAssets) {
            return 'assets';
        }

        return 'both';
    }
}
