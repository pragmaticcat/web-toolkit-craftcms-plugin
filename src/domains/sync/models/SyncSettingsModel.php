<?php

namespace pragmatic\webtoolkit\domains\sync\models;

use craft\base\Model;

class SyncSettingsModel extends Model
{
    public int $stagedUploadRetentionHours = 2;
    public int $historyRetentionDays = 30;
    public int $exportArtifactRetentionHours = 24;
    public int $insertBatchRowCount = 500;
    public int $selectChunkSize = 1000;

    public function rules(): array
    {
        return [
            [['stagedUploadRetentionHours', 'historyRetentionDays', 'exportArtifactRetentionHours', 'insertBatchRowCount', 'selectChunkSize'], 'integer'],
            ['stagedUploadRetentionHours', 'integer', 'min' => 1, 'max' => 72],
            ['historyRetentionDays', 'integer', 'min' => 1, 'max' => 365],
            ['exportArtifactRetentionHours', 'integer', 'min' => 1, 'max' => 168],
            ['insertBatchRowCount', 'integer', 'min' => 1, 'max' => 5000],
            ['selectChunkSize', 'integer', 'min' => 1, 'max' => 10000],
        ];
    }
}
