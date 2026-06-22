<?php

namespace twentysix\xray\controllers;

use twentysix\xray\Plugin;
use twentysix\xray\web\assets\cp\XRayCpAsset;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class ViewerController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser || !$currentUser->admin) {
            throw new ForbiddenHttpException('Admin access required.');
        }

        // Start from the configured Start URL (Settings), else the site base URL.
        $plugin   = Plugin::getInstance();
        $siteUrl  = $plugin->resolveStartUrl();
        $settings = $plugin->getSettings();

        $templatesPath = rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/');
        
        // Map Docker/VM path to local host path if configured
        if ($settings->localBasePath) {
            $basePath = rtrim(Craft::getAlias('@root') ?: Craft::$app->getBasePath(), '/');
            $localBase = rtrim($settings->localBasePath, '/');
            
            if (str_starts_with($templatesPath, $basePath)) {
                $templatesPath = $localBase . substr($templatesPath, strlen($basePath));
            } else {
                // Fallback if templates directory is outside the base path for some reason
                $templatesPath = $localBase . '/templates';
            }
        }

        // Bundle the viewer's fonts + editor logos locally (no external calls).
        // The published base URL lets the JS build the editor-icon image paths.
        $cpBundle = Craft::$app->getView()->registerAssetBundle(XRayCpAsset::class);
        $editorIconsBaseUrl = rtrim((string)$cpBundle->baseUrl, '/') . '/editors';

        // Render inside the Control Panel layout so the global admin nav and
        // header stay visible — you never leave the CP.
        return $this->renderTemplate('x-ray/index', [
            'siteUrl'          => rtrim($siteUrl, '/'),
            'editorIconsBaseUrl' => $editorIconsBaseUrl,
            'accentColor'      => $settings->getAccentColor(),
            'highlightStyle'   => $settings->getHighlightStyle(),
            'activationParam'  => $settings->getActivationParam(),
            'showEditLinks'    => $settings->showEditLinks,
            'blockExternalNav' => $settings->blockExternalNav,
            'componentPropsUrl' => UrlHelper::cpUrl('x-ray/api/component-props'),
            'editorMode'       => $settings->editorMode ?: 'default',
            'defaultEditor'    => $settings->defaultEditor ?: 'vscode',
            // Absolute filesystem path to the Craft templates folder.
            // Used by the JS editor deep-link builder to construct correct file:// paths.
            'templatesPath'    => $templatesPath,
        ]);
    }
}
