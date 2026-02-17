<?php

namespace pragmatic\webtoolkit\domains\translations\records;

use craft\db\ActiveRecord;

class TranslationGroupRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pragmatic_toolkit_translations_groups}}';
    }
}
