<?php

namespace pragmatic\webtoolkit\domains\cookies\assets;

use craft\web\AssetBundle;

class ConsentAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->css = ['consent.css'];
        $this->js = ['consent.js'];

        parent::init();
    }
}
