<?php

namespace pragmatic\webtoolkit\domains\cookies\interfaces;

interface CookiesAppearanceProviderInterface
{
    public function getAppearanceSettings(): array;

    public function saveAppearanceSettings(array $input): bool;

    public function getAppearanceDefaults(): array;
}
