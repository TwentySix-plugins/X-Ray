<?php

namespace twentysix\xray;

use twentysix\xray\models\Settings;
use twentysix\xray\twig\XRayTwigLoader;
use twentysix\xray\twigextensions\Extension;
use twentysix\xray\web\assets\XRayAsset;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Json;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }

    /**
     * Resolves the URL X-Ray should load first: the configured Start
     * URL if set, otherwise the current site's base URL.
     */
    public function resolveStartUrl(): string
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        $configured = trim($settings->startUrl);
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string)Craft::$app->getSites()->getCurrentSite()->getBaseUrl(), '/');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[] = [
                'name'    => $site->name,
                'baseUrl' => rtrim((string)$site->getBaseUrl(), '/'),
                'primary' => $site->primary,
            ];
        }

        return Craft::$app->getView()->renderTemplate('x-ray/settings', [
            'settings'     => $this->getSettings(),
            'effectiveUrl' => $this->resolveStartUrl(),
            'sites'        => $sites,
        ]);
    }

    public function init(): void
    {
        parent::init();

        // Register CP URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['x-ray'] = 'x-ray/viewer/index';
                $event->rules['x-ray/api/component-props'] = 'x-ray/api/component-props';
                $event->rules['x-ray/api/global-sets'] = 'x-ray/api/global-sets';
            }
        );

        // Register Twig extension + wrap the loader on site requests in
        // environments where X-Ray may activate. The wrapper always injects an
        // `{% if xrayActive() %}` guard, so the per-request activation (param +
        // admin) is decided at render time by the extension — no stale-cache
        // surprises when those change.
        if (Craft::$app->request->getIsSiteRequest()) {
            /** @var Settings $settings */
            $settings = $this->getSettings();

            // Skip all front-end instrumentation in environments where X-Ray can
            // never activate (e.g. production with activeMode = devMode /
            // environments). Public traffic then pays nothing: no Twig extension,
            // no loader wrapping, no guard checks. The environment is constant for
            // the lifetime of a deploy, so this never causes stale-cache flip-flops.
            if (!$settings->isEnabledForEnvironment()) {
                return;
            }

            Craft::$app->view->registerTwigExtension(new Extension($settings));

            $view = Craft::$app->view;
            $twig = $view->getTwig();
            $originalLoader = $twig->getLoader();
            $twig->setLoader(new XRayTwigLoader($originalLoader, $settings));

            // Inject the client-side X-Ray JS into the front-end page only
            // when X-Ray is actually active (inside the viewer iframe).
            $param = $settings->getActivationParam();
            if (Craft::$app->request->getParam($param) === '1') {
                $currentUser = Craft::$app->getUser()->getIdentity();
                if ($currentUser && $currentUser->admin) {
                    $config = Json::encode([
                        'accent'           => $settings->getAccentColor(),
                        'style'            => $settings->getHighlightStyle(),
                        'param'            => $param,
                        'showTooltip'      => $settings->showTooltip,
                        'tooltipLabel'     => $settings->getTooltipLabel(),
                        'persistSelection' => $settings->persistSelection,
                        'blockExternalNav' => $settings->blockExternalNav,
                    ]);
                    // Expose config before the client bundle runs.
                    Craft::$app->view->registerJs("window.xrayConfig = {$config};", View::POS_HEAD);
                    Craft::$app->view->registerAssetBundle(XRayAsset::class);
                }
            }
        }
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'X-Ray';
        $item['url'] = 'x-ray';
        // Dedicated monochrome nav icon (the plugin icon is icon.svg).
        $item['icon'] = __DIR__ . '/nav-icon.svg';
        return $item;
    }
}
