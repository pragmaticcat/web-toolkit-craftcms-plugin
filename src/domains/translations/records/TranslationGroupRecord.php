<?php

namespace pragmatic\webtoolkit\domains\translations\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use pragmatic\webtoolkit\db\LegacyRecord;

class TranslationGroupRecord extends BaseModel
{
    use HasUid;
    use LegacyRecord;

    protected $table = 'pragmatic_toolkit_translations_groups';
}
