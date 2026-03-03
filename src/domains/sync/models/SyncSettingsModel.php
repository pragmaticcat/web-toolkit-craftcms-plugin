<?php

namespace pragmatic\webtoolkit\domains\sync\models;

use craft\base\Model;

class SyncSettingsModel extends Model
{
    public int $stagedUploadRetentionHours = 2;
    public int $historyRetentionDays = 30;

    public function rules(): array
    {
        return [
            [['stagedUploadRetentionHours', 'historyRetentionDays'], 'integer'],
            ['stagedUploadRetentionHours', 'integer', 'min' => 1, 'max' => 72],
            ['historyRetentionDays', 'integer', 'min' => 1, 'max' => 365],
        ];
    }
}
