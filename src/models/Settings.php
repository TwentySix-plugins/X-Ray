<?php

namespace twentysix\xray\models;

use Craft;
use craft\base\Model;

/**
 * X-Ray plugin settings.
 */
class Settings extends Model
{
    // ── Viewer ──────────────────────────────────────────────────────────────

    /**
     * Optional override for the URL X-Ray loads first. When empty, the
     * X-Ray falls back to the current site's base URL.
     */
    public string $startUrl = '';

    // ── Appearance ──────────────────────────────────────────────────────────

    /** Accent colour for the highlight outline, tooltip and sidebar accent. */
    public string $accentColor = '#7B61FF';

    /** Outline style of the hover highlight: 'dotted', 'dashed' or 'solid'. */
    public string $highlightStyle = 'dotted';

    // ── Activation ──────────────────────────────────────────────────────────

    /** Query-string flag that turns X-Ray on for a front-end request. */
    public string $activationParam = 'xray';

    /**
     * When X-Ray may activate:
     *   'always'       — any environment
     *   'devMode'      — only when Craft devMode is on
     *   'environments' — only in the listed environments
     */
    public string $activeMode = 'always';

    /** Newline/comma-separated environment names (used when activeMode = environments). */
    public string $environments = '';

    // ── Template wrapping ───────────────────────────────────────────────────

    /** Filename prefix a partial must have to be annotated (default "_"). */
    public string $wrapPrefix = '_';

    /** Newline-separated glob patterns; if set, only matching templates wrap. */
    public string $includePatterns = '';

    /** Newline-separated glob patterns; matching templates are never wrapped. */
    public string $excludePatterns = '';

    // ── Behaviour ───────────────────────────────────────────────────────────

    /** Show "Edit in Admin" links/buttons in the sidebar. */
    public bool $showEditLinks = true;

    /** Show the floating component label beside the cursor in the page. */
    public bool $showTooltip = true;

    /** Keep external navigations confined to the project's own host. */
    public bool $blockExternalNav = true;

    /** Keep a persistent outline on the clicked (selected) component. */
    public bool $persistSelection = false;

    /** Tooltip label content: 'name' (component name) or 'path' (template path). */
    public string $tooltipLabel = 'name';

    // ── Editor deep-link ────────────────────────────────────────────────────

    /**
     * How the "Open in editor" icon behaves:
     *   'default'  — fires immediately in the configured defaultEditor
     *   'variable' — pops up an inline picker so the user can choose on the spot
     */
    public string $editorMode = 'default';

    /**
     * The editor key used when editorMode = 'default'.
     * Recognised keys: vscode, cursor, windsurf, phpstorm, webstorm,
     *                  idea, zed, sublime, nova
     */
    public string $defaultEditor = 'vscode';

    /**
     * Local project base path, useful when Craft is running in a VM/Docker container
     * but the IDE is running on the host machine.
     */
    public string $localBasePath = '';

    // ── Rules ───────────────────────────────────────────────────────────────

    public function rules(): array
    {
        return [
            [['startUrl', 'localBasePath'], 'trim'],
            [[
                'startUrl', 'accentColor', 'highlightStyle', 'activationParam',
                'activeMode', 'environments', 'wrapPrefix', 'includePatterns',
                'excludePatterns', 'tooltipLabel', 'editorMode', 'defaultEditor',
                'localBasePath',
            ], 'string'],
            [['showEditLinks', 'showTooltip', 'blockExternalNav', 'persistSelection'], 'boolean'],
            // Craft's colorField posts the hex WITHOUT the leading '#'; restore it
            // before the pattern check so values like "7B61FF" still validate.
            [['accentColor'], 'filter', 'filter' => [self::class, 'normalizeHex']],
            [['accentColor'], 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/', 'message' => 'Enter a 6-digit hex colour, e.g. #7B61FF.'],
            [['highlightStyle'], 'in', 'range' => ['dotted', 'dashed', 'solid']],
            [['activeMode'], 'in', 'range' => ['always', 'devMode', 'environments']],
            [['tooltipLabel'], 'in', 'range' => ['name', 'path']],
            [['editorMode'], 'in', 'range' => ['default', 'variable']],
            [['defaultEditor'], 'in', 'range' => [
                'vscode', 'cursor', 'windsurf', 'phpstorm', 'webstorm',
                'idea', 'zed', 'sublime', 'nova',
            ]],
        ];
    }

    // ── Normalised accessors ────────────────────────────────────────────────

    public function getAccentColor(): string
    {
        $hex = self::normalizeHex($this->accentColor);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : '#7B61FF';
    }

    /** Ensure a hex colour string carries a single leading '#'. */
    public static function normalizeHex(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        return '#' . ltrim($value, '#');
    }

    public function getHighlightStyle(): string
    {
        return in_array($this->highlightStyle, ['dotted', 'dashed', 'solid'], true) ? $this->highlightStyle : 'dotted';
    }

    /** Sanitised query-param name (URL-safe chars only), with a fallback. */
    public function getActivationParam(): string
    {
        $param = preg_replace('/[^A-Za-z0-9_\-]/', '', trim($this->activationParam));
        return $param !== '' ? $param : 'xray';
    }

    public function getTooltipLabel(): string
    {
        return $this->tooltipLabel === 'path' ? 'path' : 'name';
    }

    public function getWrapPrefix(): string
    {
        return $this->wrapPrefix;
    }

    /** @return string[] */
    public function getIncludePatterns(): array
    {
        return $this->splitList($this->includePatterns);
    }

    /** @return string[] */
    public function getExcludePatterns(): array
    {
        return $this->splitList($this->excludePatterns);
    }

    /** @return string[] */
    public function getEnvironments(): array
    {
        return $this->splitList($this->environments);
    }

    /**
     * Whether X-Ray is permitted to activate in the current environment.
     */
    public function isEnabledForEnvironment(): bool
    {
        switch ($this->activeMode) {
            case 'devMode':
                return (bool)Craft::$app->getConfig()->getGeneral()->devMode;
            case 'environments':
                $envs = $this->getEnvironments();
                // Empty list → don't lock anyone out; otherwise must match.
                return empty($envs) || in_array((string)Craft::$app->env, $envs, true);
            default:
                return true;
        }
    }

    /** Split a newline/comma separated string into a clean list. */
    private function splitList(string $value): array
    {
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
    }
}
