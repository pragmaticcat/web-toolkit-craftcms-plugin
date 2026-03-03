<?php

namespace pragmatic\webtoolkit\domains\sync\models;

use craft\base\Model;

class TransferManifestModel extends Model
{
    public int $schemaVersion = 1;
    public string $packageType = 'pwt-sync';
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
    public int $packageChecksumVersion = 1;

    public function rules(): array
    {
        return [
            [['schemaVersion', 'packageChecksumVersion'], 'integer'],
            [['packageType', 'createdAt', 'sourceSiteName', 'sourceCpUrl', 'craftVersion', 'pluginVersion', 'phpVersion', 'dbDriver', 'tablePrefix'], 'string'],
            [['includedVolumes', 'database'], 'safe'],
        ];
    }
}
