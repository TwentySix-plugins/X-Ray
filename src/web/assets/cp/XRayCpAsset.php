<?php

namespace twentysix\xray\web\assets\cp;

use craft\web\AssetBundle;

/**
 * Control-panel asset bundle for the X-Ray viewer.
 *
 * Bundles the self-hosted fonts (Inter + JetBrains Mono) and the editor logos
 * locally so the CP makes no external requests. The published base URL is read
 * by the viewer to build the editor-icon image paths.
 */
class XRayCpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->css = ['fonts/fonts.css'];
        parent::init();
    }
}
