<?php

namespace pragmatic\webtoolkit\domains\cookies\records;

use craft\db\ActiveRecord;

class CookieCategoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pragmatic_toolkit_cookies_categories}}';
    }
}
