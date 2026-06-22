<?php

namespace twentysix\xray\console\controllers;

use twentysix\xray\controllers\ApiController;
use twentysix\xray\models\Settings;
use twentysix\xray\twig\XRayTwigLoader;
use twentysix\xray\twigextensions\Extension;
use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use Twig\Loader\LoaderInterface;
use Twig\Source;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Test suite for the X-Ray plugin.
 *
 * Runs in-process (real Craft app + DB) so it can exercise the Settings model,
 * the Twig loader's wrapping logic, the prop serializer, the seeded nested
 * content, and the API serializer.
 *
 *   php craft x-ray/test
 */
class TestController extends Controller
{
    private int $pass = 0;
    private int $fail = 0;
    /** @var string[] */
    private array $failures = [];

    public function actionIndex(): int
    {
        $this->stdout("\n🧪  X-Ray — Test Suite\n", Console::FG_CYAN);
        $this->stdout(str_repeat('═', 52) . "\n", Console::FG_GREY);

        $this->testSettingsDefaults();
        $this->testSettingsGetters();
        $this->testSettingsValidation();
        $this->testEnvironmentGating();
        $this->testTwigLoaderWrapping();
        $this->testTwigLoaderCacheKey();
        $this->testSerializerScalars();
        $this->testSerializerHtmlCap();
        $this->testSerializerElementsAndCollections();
        $this->testSerializerAsset();
        $this->testPropsContextFiltering();
        $this->testPropsTokenCache();
        $this->testPropsCompactJson();
        $this->testApiSerializer();
        $this->testBundledCpAssets();

        // ── Summary ──────────────────────────────────────────────────────
        $this->stdout("\n" . str_repeat('═', 52) . "\n", Console::FG_GREY);
        $total = $this->pass + $this->fail;
        if ($this->fail === 0) {
            $this->stdout("✅  ALL {$total} TESTS PASSED\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stdout("❌  {$this->fail} of {$total} FAILED\n", Console::FG_RED);
        foreach ($this->failures as $f) {
            $this->stdout("   • {$f}\n", Console::FG_RED);
        }
        $this->stdout("\n");
        return ExitCode::UNSPECIFIED_ERROR;
    }

    // ─── Settings: defaults ──────────────────────────────────────────────────

    private function testSettingsDefaults(): void
    {
        $this->group('Settings · defaults');
        $s = new Settings();
        $this->ok($s->startUrl === '', 'startUrl default empty');
        $this->ok($s->accentColor === '#7B61FF', 'accentColor default');
        $this->ok($s->highlightStyle === 'dotted', 'highlightStyle default dotted');
        $this->ok($s->activationParam === 'xray', 'activationParam default');
        $this->ok($s->activeMode === 'always', 'activeMode default always');
        $this->ok($s->wrapPrefix === '_', 'wrapPrefix default _');
        $this->ok($s->showEditLinks === true, 'showEditLinks default on');
        $this->ok($s->showTooltip === true, 'showTooltip default on');
        $this->ok($s->blockExternalNav === true, 'blockExternalNav default on');
        $this->ok($s->persistSelection === false, 'persistSelection default off');
        $this->ok($s->tooltipLabel === 'name', 'tooltipLabel default name');
    }

    // ─── Settings: normalised getters ────────────────────────────────────────

    private function testSettingsGetters(): void
    {
        $this->group('Settings · getters');
        $s = new Settings();

        $s->activationParam = 'My Param!@#';
        $this->ok($s->getActivationParam() === 'MyParam', 'activationParam strips unsafe chars');
        $s->activationParam = 'a-b_c';
        $this->ok($s->getActivationParam() === 'a-b_c', 'activationParam keeps -_ alnum');
        $s->activationParam = '   ';
        $this->ok($s->getActivationParam() === 'xray', 'activationParam falls back when empty');

        $s->accentColor = 'not-a-hex';
        $this->ok($s->getAccentColor() === '#7B61FF', 'accentColor invalid → fallback');
        $s->accentColor = '#1a2B3c';
        $this->ok($s->getAccentColor() === '#1a2B3c', 'accentColor valid kept');

        $s->highlightStyle = 'zigzag';
        $this->ok($s->getHighlightStyle() === 'dotted', 'highlightStyle invalid → dotted');
        $s->highlightStyle = 'solid';
        $this->ok($s->getHighlightStyle() === 'solid', 'highlightStyle valid kept');

        $s->tooltipLabel = 'bogus';
        $this->ok($s->getTooltipLabel() === 'name', 'tooltipLabel invalid → name');
        $s->tooltipLabel = 'path';
        $this->ok($s->getTooltipLabel() === 'path', 'tooltipLabel path kept');

        $s->includePatterns = "a\nb, c\n\n  d  ";
        $this->ok($s->getIncludePatterns() === ['a', 'b', 'c', 'd'], 'includePatterns split/trim/compact');
        $s->excludePatterns = '';
        $this->ok($s->getExcludePatterns() === [], 'excludePatterns empty → []');
        $s->environments = "dev , staging\nprod";
        $this->ok($s->getEnvironments() === ['dev', 'staging', 'prod'], 'environments split on comma+newline');
    }

    // ─── Settings: validation rules ──────────────────────────────────────────

    private function testSettingsValidation(): void
    {
        $this->group('Settings · validation');

        $valid = new Settings();
        $this->ok($valid->validate(), 'default settings validate');

        $bad = new Settings();
        $bad->accentColor = 'red';
        $this->ok(!$bad->validate(['accentColor']), 'non-hex accentColor rejected');

        $bad = new Settings();
        $bad->highlightStyle = 'wobbly';
        $this->ok(!$bad->validate(['highlightStyle']), 'bad highlightStyle rejected');

        $bad = new Settings();
        $bad->activeMode = 'sometimes';
        $this->ok(!$bad->validate(['activeMode']), 'bad activeMode rejected');

        $bad = new Settings();
        $bad->tooltipLabel = 'emoji';
        $this->ok(!$bad->validate(['tooltipLabel']), 'bad tooltipLabel rejected');

        $okColor = new Settings();
        $okColor->accentColor = '#00FF99';
        $this->ok($okColor->validate(['accentColor']), 'valid hex accepted');

        // Regression: Craft's colorField posts the hex WITHOUT a leading '#'.
        $noHash = new Settings();
        $noHash->accentColor = '7B61FF';
        $this->ok($noHash->validate(['accentColor']), 'hash-less hex (as colorField posts) accepted');
        $this->ok($noHash->accentColor === '#7B61FF', 'hash-less hex normalised to #7B61FF on validate');
        $this->ok((new Settings(['accentColor' => 'A1B2C3']))->getAccentColor() === '#A1B2C3', 'getAccentColor normalises missing #');
    }

    // ─── Settings: environment gating ────────────────────────────────────────

    private function testEnvironmentGating(): void
    {
        $this->group('Settings · environment gating');
        $env = (string)Craft::$app->env;
        $devMode = (bool)Craft::$app->getConfig()->getGeneral()->devMode;

        $s = new Settings();
        $s->activeMode = 'always';
        $this->ok($s->isEnabledForEnvironment() === true, 'always → enabled');

        $s->activeMode = 'devMode';
        $this->ok($s->isEnabledForEnvironment() === $devMode, 'devMode mirrors config devMode (' . ($devMode ? 'on' : 'off') . ')');

        $s->activeMode = 'environments';
        $s->environments = '';
        $this->ok($s->isEnabledForEnvironment() === true, 'environments empty → allow all');

        $s->environments = $env;
        $this->ok($s->isEnabledForEnvironment() === true, "environments contains current ({$env}) → enabled");

        $s->environments = '__nope__not__an__env__';
        $this->ok($s->isEnabledForEnvironment() === false, 'environments without current → disabled');
    }

    // ─── Twig loader: wrapping decisions ─────────────────────────────────────

    private function testTwigLoaderWrapping(): void
    {
        $this->group('Twig loader · wrapping');

        $partial = '<div class="x">hello</div>';
        $layout  = "{% extends '_base' %}\n{% block content %}hi{% endblock %}";
        $blocky  = "{% block content %}hi{% endblock %}";

        // Default settings: prefix "_"
        $def = new Settings();
        $this->ok($this->wraps($def, '_components/_card.twig', $partial), 'default: _-prefixed partial IS wrapped');
        $this->ok(!$this->wraps($def, 'games/index.twig', $partial), 'default: non-_ template is NOT wrapped');
        $this->ok(!$this->wraps($def, '_components/_layout.twig', $layout), 'extends template NOT wrapped');
        $this->ok(!$this->wraps($def, '_components/_blocky.twig', $blocky), 'block-defining template NOT wrapped');
        $this->ok(!$this->wraps($def, '_components/_icon.svg', '<svg></svg>'), 'svg ext NOT wrapped');
        $this->ok(!$this->wraps($def, '@app/_thing.twig', $partial), '@-namespaced NOT wrapped');

        // Empty prefix → wrap everything (non-layout)
        $all = new Settings();
        $all->wrapPrefix = '';
        $this->ok($this->wraps($all, 'games/index.twig', $partial), 'empty prefix wraps non-_ template');

        // Include patterns override the prefix rule
        $inc = new Settings();
        $inc->includePatterns = "blocks/*";
        $this->ok($this->wraps($inc, 'blocks/hero.twig', $partial), 'include glob wraps matching non-_ template');
        $this->ok(!$this->wraps($inc, '_components/_card.twig', $partial), 'include set → non-matching _ template NOT wrapped');

        // Exclude patterns win outright
        $exc = new Settings();
        $exc->excludePatterns = "_components/_secret*";
        $this->ok(!$this->wraps($exc, '_components/_secret-sauce.twig', $partial), 'exclude glob blocks an otherwise-wrapped template');
        $this->ok($this->wraps($exc, '_components/_card.twig', $partial), 'exclude does not affect non-matching templates');

        // Lazy props: the wrapper emits a token, not inline JSON.
        $src = $this->wrapSource($def, '_components/_card.twig', $partial);
        $this->ok(str_contains($src, 'data-craft-id'), 'wrapper emits lazy data-craft-id token attr');
        $this->ok(str_contains($src, 'xrayPropsToken'), 'wrapper calls the token function');
        $this->ok(!str_contains($src, 'data-craft-props'), 'wrapper no longer inlines data-craft-props');
    }

    // ─── Twig loader: compiled-cache key ─────────────────────────────────────

    private function testTwigLoaderCacheKey(): void
    {
        $this->group('Twig loader · compiled-cache key');

        $def = new Settings();

        $inner = $this->innerLoader();
        $loader = new XRayTwigLoader($inner, $def);

        $innerKey = $inner->getCacheKey('_components/_card.twig');
        $key      = $loader->getCacheKey('_components/_card.twig');

        $this->ok($key !== $innerKey, 'wrapped key differs from the inner key');
        $this->ok(str_contains($key, $innerKey), 'wrapped key still contains the inner key');
        $this->ok(str_contains($key, '|xray:'), 'wrapped key carries the xray namespace marker');

        // Same settings → identical (stable) key.
        $loader2 = new XRayTwigLoader($this->innerLoader(), new Settings());
        $this->ok($loader2->getCacheKey('_components/_card.twig') === $key, 'identical settings → identical key');

        // Changing a wrap-affecting setting must change the key so compiled
        // templates recompile instead of serving a stale wrapping.
        $other = new Settings();
        $other->wrapPrefix = 'block-';
        $loader3 = new XRayTwigLoader($this->innerLoader(), $other);
        $this->ok($loader3->getCacheKey('_components/_card.twig') !== $key, 'changing wrapPrefix changes the key');

        $inc = new Settings();
        $inc->includePatterns = "blocks/*";
        $loader4 = new XRayTwigLoader($this->innerLoader(), $inc);
        $this->ok($loader4->getCacheKey('_components/_card.twig') !== $key, 'changing includePatterns changes the key');

        // Delegation still works.
        $this->ok($loader->isFresh('_components/_card.twig', time()) === true, 'isFresh delegates to inner');
        $this->ok($loader->exists('_components/_card.twig') === true, 'exists delegates to inner');
    }

    // ─── Serializer: scalars ─────────────────────────────────────────────────

    private function testSerializerScalars(): void
    {
        $this->group('Serializer · scalars');
        $s = $this->sanitize(null);
        $this->ok($s === null, 'null passthrough');
        $this->ok($this->sanitize(true) === true, 'bool passthrough');
        $this->ok($this->sanitize(42) === 42, 'int passthrough');
        $this->ok($this->sanitize(3.5) === 3.5, 'float passthrough');
        $this->ok($this->sanitize('hi') === 'hi', 'short string passthrough');

        $long = str_repeat('x', 400);
        $out = $this->sanitize($long);
        $this->ok(mb_strlen($out) === 301 && str_ends_with($out, '…'), 'long string truncated to 300 + ellipsis');

        $arr = $this->sanitize([1, 2, 'three']);
        $this->ok($arr === [1, 2, 'three'], 'scalar array preserved');
    }

    // ─── Serializer: HTML / rich-text capping ────────────────────────────────

    private function testSerializerHtmlCap(): void
    {
        $this->group('Serializer · HTML field capping');

        // Short HTML is returned whole, with the stripped-text length.
        $short = $this->sanitize(new \Twig\Markup('<p>Hello <b>world</b></p>', 'UTF-8'));
        $this->ok(($short['__type'] ?? null) === 'Html', 'markup → __type Html');
        $this->ok(($short['truncated'] ?? null) === false, 'short markup not flagged truncated');
        $this->ok(($short['length'] ?? null) === mb_strlen('Hello world'), 'length is the stripped-text length');
        $this->ok(($short['html'] ?? '') === '<p>Hello <b>world</b></p>', 'short markup kept verbatim');

        // Oversized HTML is capped to HTML_PREVIEW_CAP (+ ellipsis) and flagged.
        $big = str_repeat('<p>x</p>', 5000); // 40k chars of markup
        $out = $this->sanitize(new \Twig\Markup($big, 'UTF-8'));
        $this->ok(($out['truncated'] ?? null) === true, 'oversized markup flagged truncated');
        $this->ok(mb_strlen($out['html']) === Extension::HTML_PREVIEW_CAP + 1, 'capped to HTML_PREVIEW_CAP + ellipsis');
        $this->ok(str_ends_with($out['html'], '…'), 'capped markup ends with ellipsis');
        $this->ok(($out['length'] ?? 0) === mb_strlen(strip_tags($big)), 'length still reflects the FULL text');
    }

    // ─── Serializer: elements + collections ──────────────────────────────────

    private function testSerializerElementsAndCollections(): void
    {
        $this->group('Serializer · elements & collections');
        $entry = Entry::find()->section('games')->slug('neon-void')->one();
        if (!$entry) {
            $this->ok(false, 'Neon Void entry exists (seed first)');
            return;
        }

        $s = $this->sanitize($entry);
        $this->ok(($s['__type'] ?? null) === 'Element', 'top-level entry → __type Element');
        $this->ok(($s['id'] ?? null) === $entry->id, 'entry id serialized');
        $this->ok(!empty($s['editUrl']), 'entry editUrl present');
        $this->ok(!empty($s['title']), 'entry title present');
        $this->ok(!array_key_exists('fields', $s), 'entry fields are not expanded');

        // Element collection summary
        $blocks = $entry->contentBlocks->all();
        $col = $this->sanitize($blocks);
        $this->ok(($col['__type'] ?? null) === 'ElementCollection', 'array of blocks → ElementCollection');
        $this->ok(($col['__count'] ?? 0) >= 1, 'ElementCollection count >= 1');
        $this->ok(!array_key_exists('__items', $col), 'collection items are not expanded');
    }

    // ─── Serializer: assets + thumbnails ─────────────────────────────────────

    private function testSerializerAsset(): void
    {
        $this->group('Serializer · assets & thumbnails');

        $asset = \craft\elements\Asset::find()->kind('image')->one()
            ?? \craft\elements\Asset::find()->one();

        if (!$asset) {
            // No assets seeded — nothing to exercise, but don't fail the suite.
            $this->ok(true, 'no assets present (asset serialization skipped)');
            return;
        }

        // Must serialize without throwing.
        $s = $this->sanitize($asset);
        $this->ok(($s['__type'] ?? null) === 'Element', 'asset → __type Element');
        $this->ok(($s['id'] ?? null) === $asset->id, 'asset id serialized');
        $this->ok(!empty($s['title']), 'asset title present');
        $this->ok(!array_key_exists('thumbnailUrl', $s), 'asset thumbnail is not serialized');
    }

    // ─── Serializer: context filtering (getProps) ────────────────────────────

    private function testPropsContextFiltering(): void
    {
        $this->group('Serializer · props context filtering');
        $ext = new Extension(new Settings());
        $context = [
            'craft'      => 'x',
            'view'       => 'x',
            'now'        => 'x',
            'currentUser' => 'x',
            'SORT_ASC'   => 1,
            '_self'      => 'x',
            'myVar'      => 'hello',
            'count'      => 7,
            'footerSettings' => new \craft\elements\GlobalSet(),
        ];
        $props = json_decode($ext->getProps($context), true);

        $this->ok(array_key_exists('myVar', $props), 'custom var kept');
        $this->ok(($props['myVar'] ?? null) === 'hello', 'custom var value kept');
        $this->ok(array_key_exists('count', $props), 'second custom var kept');
        $this->ok(!array_key_exists('craft', $props), 'craft global filtered');
        $this->ok(!array_key_exists('view', $props), 'view global filtered');
        $this->ok(!array_key_exists('now', $props), 'now global filtered');
        $this->ok(!array_key_exists('SORT_ASC', $props), 'ALL-CAPS constant filtered');
        $this->ok(!array_key_exists('_self', $props), 'underscore-prefixed filtered');
        $this->ok(!array_key_exists('footerSettings', $props), 'global sets filtered');
    }

    // ─── Serializer: lazy props token + cache ────────────────────────────────

    private function testPropsTokenCache(): void
    {
        $this->group('Serializer · lazy props token');
        $ext = new Extension(new Settings());
        $context = ['label' => 'Kitchen sink', 'count' => 7];

        $token = $ext->getPropsToken($context);
        $this->ok(preg_match('/^xr_[0-9a-f]{1,40}$/', $token) === 1, 'token has xr_<hex> shape');

        $cached = Craft::$app->getCache()->get(Extension::PROPS_CACHE_PREFIX . $token);
        $this->ok($cached !== false, 'props stored in cache under the token');

        $decoded = json_decode((string)$cached, true);
        $this->ok(($decoded['label'] ?? null) === 'Kitchen sink', 'cached JSON round-trips the props');

        // Same context → same token (content-hash dedupe).
        $token2 = $ext->getPropsToken($context);
        $this->ok($token === $token2, 'identical context yields identical token');

        // Different context → different token.
        $token3 = $ext->getPropsToken(['label' => 'Other']);
        $this->ok($token !== $token3, 'different context yields different token');
    }

    // ─── Serializer: compact JSON payload ────────────────────────────────────

    private function testPropsCompactJson(): void
    {
        $this->group('Serializer · compact JSON');
        $ext = new Extension(new Settings());
        $json = $ext->getProps(['label' => 'Hi', 'nested' => ['a' => 1, 'b' => 2]]);

        // Pretty-printing would insert newlines + run-on indentation; the payload
        // is machine-parsed, so it must stay compact.
        $this->ok(!str_contains($json, "\n"), 'props JSON has no newlines (not pretty-printed)');
        $this->ok(!str_contains($json, '    '), 'props JSON has no indentation runs');
        $decoded = json_decode($json, true);
        $this->ok(($decoded['label'] ?? null) === 'Hi', 'compact JSON still round-trips');
        $this->ok(($decoded['nested']['b'] ?? null) === 2, 'nested values survive compact encoding');
    }

    // ─── API serializer ──────────────────────────────────────────────────────

    private function testApiSerializer(): void
    {
        $this->group('API · serializeFieldValue');
        $api = (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($api, 'serializeFieldValue');
        $m->setAccessible(true);

        $this->ok($m->invoke($api, 'short') === 'short', 'short string passthrough');
        $long = $m->invoke($api, str_repeat('y', 500));
        $this->ok(mb_strlen($long) === 301 && str_ends_with($long, '…'), 'long string truncated');
        $this->ok($m->invoke($api, 99) === 99, 'int passthrough');

        $entry = Entry::find()->section('games')->one();
        if ($entry) {
            $el = $m->invoke($api, $entry);
            $this->ok(($el['__type'] ?? null) === 'Element', 'element serialized as summary');
            $this->ok(($el['id'] ?? null) === $entry->id, 'element serialized with id');
            $this->ok(array_key_exists('title', $el) && array_key_exists('editUrl', $el), 'element has title + editUrl');
            $arr = $m->invoke($api, [$entry]);
            $this->ok(is_array($arr) && ($arr['__type'] ?? null) === 'ElementCollection', 'array of elements → collection summary');
            $this->ok(($arr['__count'] ?? 0) === 1, 'collection count matches array length');
        } else {
            $this->ok(false, 'a games entry exists for API test');
        }
    }

    // ─── Packaging: locally-bundled CP assets ────────────────────────────────

    private function testBundledCpAssets(): void
    {
        $this->group('Packaging · bundled CP assets (no remote calls)');

        $dist = dirname((new \ReflectionClass(\twentysix\xray\web\assets\cp\XRayCpAsset::class))->getFileName()) . '/dist';
        $this->ok(is_dir($dist), 'XRayCpAsset dist directory exists');

        // Editor logos must all be present locally so the viewer makes no
        // external image requests.
        $icons = ['vscode.svg', 'cursor.png', 'windsurf.svg', 'phpstorm.svg', 'webstorm.svg', 'intellij.svg', 'zed.png', 'sublime.png', 'nova.svg'];
        foreach ($icons as $icon) {
            $this->ok(is_file("$dist/editors/$icon"), "editor icon bundled: $icon");
        }

        // Self-hosted fonts.
        $this->ok(is_file("$dist/fonts/fonts.css"), 'fonts.css bundled');
        $fonts = ['inter-400.woff2', 'inter-500.woff2', 'inter-600.woff2', 'inter-700.woff2', 'jetbrains-mono-400.woff2', 'jetbrains-mono-600.woff2'];
        foreach ($fonts as $font) {
            $this->ok(is_file("$dist/fonts/$font"), "font bundled: $font");
        }

        // Guard against re-introducing remote asset references in the viewer.
        $indexTwig = file_get_contents(dirname(__DIR__, 2) . '/templates/index.twig');
        $this->ok(!str_contains($indexTwig, 'fonts.googleapis.com'), 'viewer no longer references Google Fonts');
        $this->ok(!str_contains($indexTwig, 'cdn.jsdelivr.net'), 'viewer no longer references jsDelivr CDN');
        $this->ok(!str_contains($indexTwig, 'githubusercontent.com'), 'viewer no longer references githubusercontent');
    }

    /** Print a single resolved setting value (used by the authenticated CP test). */
    public function actionGet(string $attr): int
    {
        $settings = \twentysix\xray\Plugin::getInstance()->getSettings();
        $getter = 'get' . ucfirst($attr);
        $value = method_exists($settings, $getter) ? $settings->$getter() : ($settings->$attr ?? '');
        $this->stdout(is_bool($value) ? ($value ? '1' : '0') : (string)$value);
        $this->stdout("\n");
        return ExitCode::OK;
    }

    /** Debug: simulate saving plugin settings the way the CP form posts them. */
    public function actionSave(): int
    {
        $plugin = \twentysix\xray\Plugin::getInstance();

        // Mimic a real form POST: lightswitches that are OFF post '' (empty).
        $posted = [
            'startUrl'         => '',
            'highlightStyle'   => 'dotted',
            'activationParam'  => 'xray',
            'activeMode'       => 'always',
            'environments'     => '',
            'wrapPrefix'       => '_',
            'includePatterns'  => '',
            'excludePatterns'  => '',
            'showEditLinks'    => '1',
            'showTooltip'      => '1',
            'blockExternalNav' => '',   // ← off lightswitch
            'persistSelection' => '',   // ← off lightswitch
            'tooltipLabel'     => 'name',
            'accentColor'      => '7B61FF',   // ← exactly how colorField posts it
        ];

        try {
            $ok = Craft::$app->getPlugins()->savePluginSettings($plugin, $posted);
            if ($ok) {
                $this->stdout("✅  saved OK\n", Console::FG_GREEN);
            } else {
                $this->stdout("❌  save returned false. Errors:\n", Console::FG_RED);
                foreach ($plugin->getSettings()->getErrors() as $attr => $errs) {
                    $this->stdout("   {$attr}: " . implode('; ', $errs) . "\n", Console::FG_RED);
                }
            }
        } catch (\Throwable $e) {
            $this->stdout("💥  EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n", Console::FG_RED);
        }
        return ExitCode::OK;
    }

    /** Debug: render the settings template (catches Twig/macro errors). */
    public function actionRender(): int
    {
        $plugin = \twentysix\xray\Plugin::getInstance();
        $view = Craft::$app->getView();
        $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
        try {
            $html = $view->renderTemplate('x-ray/settings', [
                'settings'     => $plugin->getSettings(),
                'effectiveUrl' => $plugin->resolveStartUrl(),
                'sites'        => [['name' => 'Default', 'baseUrl' => 'http://localhost:8080', 'primary' => true]],
            ]);
            $this->stdout("✅  rendered OK (" . strlen($html) . " bytes)\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            $this->stdout("💥  " . get_class($e) . ": " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stdout("   at " . $e->getFile() . ':' . $e->getLine() . "\n", Console::FG_GREY);
        }
        return ExitCode::OK;
    }

    /** Debug: dump the serialized contentBlocks tree for a game. */
    public function actionDump(string $slug = 'neon-void'): int
    {
        $entry = Entry::find()->section('games')->slug($slug)->one();
        if (!$entry) { $this->stdout("no entry\n"); return ExitCode::OK; }

        // raw field value class for a nested matrix
        $raw = $entry->getFieldValue('contentBlocks');
        $this->stdout('contentBlocks raw class: ' . get_class($raw) . "\n", Console::FG_CYAN);

        foreach ($entry->contentBlocks->all() as $b) {
            $this->stdout("  • {$b->type->name} (#{$b->id})\n");
            foreach ($b->getFieldLayout()->getCustomFields() as $f) {
                $v = $b->getFieldValue($f->handle);
                $cls = is_object($v) ? get_class($v) : gettype($v);
                $this->stdout("      - {$f->handle}: {$cls}\n", Console::FG_GREY);
            }
        }

        $col = $this->sanitize($entry->contentBlocks->all());
        $this->stdout("\nSerialized JSON (truncated):\n");
        $this->stdout(substr(json_encode($col, JSON_PRETTY_PRINT), 0, 1400) . "\n");
        return ExitCode::OK;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Run the loader over a fake template and report whether it was wrapped. */
    private function wraps(Settings $settings, string $name, string $code): bool
    {
        return str_contains($this->wrapSource($settings, $name, $code), 'data-craft-component');
    }

    /** A minimal inner Twig loader with a deterministic cache key, for tests. */
    private function innerLoader(): LoaderInterface
    {
        return new class implements LoaderInterface {
            public function getSourceContext(string $name): Source { return new Source('<div>x</div>', $name); }
            public function getCacheKey(string $name): string { return 'INNER:' . $name; }
            public function isFresh(string $name, int $time): bool { return true; }
            public function exists(string $name): bool { return true; }
        };
    }

    /** Return the (possibly wrapped) source the loader produces for a template. */
    private function wrapSource(Settings $settings, string $name, string $code): string
    {
        $inner = new class($name, $code) implements LoaderInterface {
            public function __construct(private string $n, private string $c) {}
            public function getSourceContext(string $name): Source { return new Source($this->c, $this->n); }
            public function getCacheKey(string $name): string { return $this->n; }
            public function isFresh(string $name, int $time): bool { return true; }
            public function exists(string $name): bool { return true; }
        };
        return (new XRayTwigLoader($inner, $settings))->getSourceContext($name)->getCode();
    }

    /** Invoke Extension::sanitizeValue (private) on a value. */
    private function sanitize(mixed $value): mixed
    {
        $ext = new Extension(new Settings());
        $m = new \ReflectionMethod($ext, 'sanitizeValue');
        $m->setAccessible(true);
        return $m->invoke($ext, $value, 0);
    }

    private function group(string $name): void
    {
        $this->stdout("\n▸ {$name}\n", Console::FG_YELLOW);
    }

    private function ok(bool $cond, string $label): void
    {
        if ($cond) {
            $this->pass++;
            $this->stdout("  ✓ {$label}\n", Console::FG_GREEN);
        } else {
            $this->fail++;
            $this->failures[] = $label;
            $this->stdout("  ✗ {$label}\n", Console::FG_RED);
        }
    }
}
