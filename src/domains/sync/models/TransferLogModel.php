<?php

namespace pragmatic\webtoolkit\domains\sync\models;

use craft\base\Model;

class TransferLogModel extends Model
{
    public int $id = 0;
    public int $jobId = 0;
    public string $direction = '';
    public string $status = '';
    public string $packageName = '';
    public string $summary = '';
    public string $errorMessage = '';
    public string $createdAt = '';
    public string $triggeredBy = '';
    public string $progressLabel = '';
    public string $artifactFilename = '';
    public bool $canDownload = false;
    public array $warnings = [];
}
