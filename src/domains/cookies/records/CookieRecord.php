<?php

namespace pragmatic\webtoolkit\domains\cookies\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use pragmatic\webtoolkit\db\LegacyRecord;

class CookieRecord extends BaseModel
{
    use HasUid;
    use LegacyRecord;

    protected $table = 'pragmatic_toolkit_cookies_cookies';
}
