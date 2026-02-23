<?php

namespace pragmatic\webtoolkit\domains\translations\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class AutotranslateFieldMenuAsset extends AssetBundle
{
    public function init(): void
    {
        parent::init();
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['autotranslate-field-menu.js'];
    }
}

