<?php

namespace twentysix\xray\web\assets;

use craft\web\AssetBundle;
use craft\web\View;

class XRayAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['xray-client.js'];
        $this->css = ['xray-client.css'];
        $this->jsOptions = ['position' => View::POS_END];
        parent::init();
    }
}
