<?php

namespace pragmatic\webtoolkit\domains\cookies\records;

use craft\db\ActiveRecord;

class ConsentLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pragmatic_toolkit_cookies_consent_logs}}';
    }
}
