<?php

namespace pragmatic\webtoolkit\domains\cookies\records;

use craft\db\ActiveRecord;

class CookieRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pragmatic_toolkit_cookies_cookies}}';
    }
}
