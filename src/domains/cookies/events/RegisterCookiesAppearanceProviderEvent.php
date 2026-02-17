<?php

namespace pragmatic\webtoolkit\domains\cookies\events;

use yii\base\Event;

class RegisterCookiesAppearanceProviderEvent extends Event
{
    /** @var array<class-string> */
    public array $providers = [];
}
