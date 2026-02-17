<?php

namespace pragmatic\webtoolkit\events;

use yii\base\Event;

class RegisterToolkitFeaturesEvent extends Event
{
    /**
     * @var array<class-string>
     */
    public array $providers = [];
}
