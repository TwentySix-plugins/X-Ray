<?php

namespace twentysix\xray\twigextensions;

use twentysix\xray\models\Settings;
use twentysix\xray\Plugin;
use Craft;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class Extension extends AbstractExtension
{
    public function __construct(private ?Settings $settings = null)
    {
    }

    /** Cache key prefix + TTL for lazily-fetched component props. */
    public const PROPS_CACHE_PREFIX = 'x-ray:props:';
    public const PROPS_CACHE_TTL = 3600;

    /** Max characters of raw markup kept for an HTML/rich-text field value. */
    public const HTML_PREVIEW_CAP = 10000;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('xrayActive', [$this, 'isActive']),
            new TwigFunction('xrayProps', [$this, 'getProps']),
            new TwigFunction('xrayPropsToken', [$this, 'getPropsToken']),
        ];
    }

    /**
     * Serializes the context, stashes it in the cache, and returns a short
     * token. The DOM only carries this token (data-craft-id) instead of the
     * full JSON — the viewer fetches the props on demand via the API. The
     * token is a content hash, so identical props dedupe to one cache entry.
     */
    public function getPropsToken(array $context): string
    {
        $json = $this->getProps($context);
        $token = 'xr_' . substr(sha1($json), 0, 24);
        $cache = Craft::$app->getCache();
        $key = self::PROPS_CACHE_PREFIX . $token;
        // Content-addressed: an identical context hashes to the same token, so an
        // existing entry is byte-for-byte identical — skip the redundant write
        // (the same template often renders many times on one page).
        if (!$cache->exists($key)) {
            $cache->set($key, $json, self::PROPS_CACHE_TTL);
        }
        return $token;
    }

    /**
     * Returns true when the current page request is running inside the
     * X-Ray iframe viewer (logged-in admin only), in an enabled
     * environment, with the configured activation flag present.
     */
    public function isActive(): bool
    {
        static $active = null;
        if ($active !== null) {
            return $active;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return $active = false;
        }

        $settings = $this->settings ?? Plugin::getInstance()->getSettings();

        if (!$settings->isEnabledForEnvironment()) {
            return $active = false;
        }

        if ($request->getParam($settings->getActivationParam()) !== '1') {
            return $active = false;
        }

        $user = Craft::$app->getUser()->getIdentity();
        return $active = ($user !== null && $user->admin);
    }

    /**
     * Extracts serializable props from the Twig context, filtering out
     * global/system objects that are not meaningful to developers.
     *
     * @param array $context The Twig _context array passed from the template
     * @return string JSON-encoded props
     */
    public function getProps(array $context): string
    {
        // Craft/Twig globals that are present in every template's context and
        // are never meaningful "props" — filtered so the panel shows only the
        // variables actually passed into the component.
        $skip = [
            'view', 'craft', 'currentSite', 'currentUser', 'now', 'today',
            'tomorrow', 'yesterday', 'loginUrl', 'logoutUrl', '_self', '_charset',
            'devMode', 'siteName', 'siteUrl', 'systemName', 'globalSets', 'loop',
            'isInstalled', 'setPasswordUrl', 'primarySite', 'CraftEdition',
            'CraftSolo', 'CraftPro', 'CraftTeam',
        ];

        $props = [];
        $types = [];   // parallel map: key → human-readable PHP type label
        foreach ($context as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            // Underscore-prefixed internals (_self, _context, _globals, __*).
            if (str_starts_with($key, '_')) {
                continue;
            }
            // ALL-CAPS constants Twig exposes as globals (SORT_*, POS_*, PHP_INT_MAX…).
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            // Global Sets are everywhere, we show them in the Globals tab instead.
            if ($value instanceof \craft\elements\GlobalSet) {
                continue;
            }
            $types[$key] = $this->resolveTypeLabel($value);
            $props[$key] = $this->sanitizeValue($value, 0);
        }

        // Embed type metadata as a reserved key so the viewer can render badges.
        // The JS skips this key when iterating over displayable props.
        $output = ['__propTypes' => $types] + $props;

        // Compact encoding — this payload is machine-parsed by the viewer, so
        // JSON_PRETTY_PRINT would only burn CPU and bytes.
        return json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns a short, human-readable type label for a raw Twig variable value.
     * This runs on the *original* value before sanitizeValue() flattens it.
     */
    private function resolveTypeLabel(mixed $value): string
    {
        if (is_null($value))   return 'null';
        if (is_bool($value))   return 'bool';
        if (is_int($value))    return 'int';
        if (is_float($value))  return 'float';
        if (is_string($value)) return 'string';

        if (is_array($value)) {
            // Peek at the first item to give a richer label for element arrays.
            $first = reset($value);
            if ($first instanceof \craft\base\ElementInterface) {
                $short = (new \ReflectionClass($first))->getShortName();
                return $short . '[]';
            }
            return 'array';
        }

        // Craft elements — use the short class name (Entry, Asset, Category…)
        if ($value instanceof \craft\base\ElementInterface) {
            return (new \ReflectionClass($value))->getShortName();
        }

        // ElementCollection (Craft 5 relational fields)
        if ($value instanceof \craft\elements\ElementCollection) {
            $first = $value->first();
            if ($first instanceof \craft\base\ElementInterface) {
                return (new \ReflectionClass($first))->getShortName() . '[]';
            }
            return 'Collection';
        }

        // Element queries
        if ($value instanceof \craft\elements\db\ElementQuery) {
            return 'Query';
        }

        // Craft field data objects
        if ($value instanceof \Twig\Markup || (is_object($value) && (str_contains(get_class($value), 'ckeditor') || str_contains(get_class($value), 'redactor') || str_contains(get_class($value), 'htmlfield')))) {
            return 'Html';
        }
        if ($value instanceof \craft\fields\data\MultiOptionsFieldData) return 'MultiSelect';
        if ($value instanceof \craft\fields\data\SingleOptionFieldData) return 'Option';
        if ($value instanceof \craft\fields\data\ColorData)             return 'Color';
        if ($value instanceof \craft\fields\data\LinkData)              return 'Link';

        // Any remaining object — use its short class name
        if (is_object($value)) {
            return (new \ReflectionClass($value))->getShortName();
        }

        return 'mixed';
    }

    /**
     * Converts a Twig variable value to a JSON-safe representation.
     */
    public function sanitizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 24) {
            return '...';
        }

        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return mb_strlen($value) > 300 ? mb_substr($value, 0, 300) . '…' : $value;
        }

        if ($value instanceof \craft\elements\ElementCollection) {
            return $this->sanitizeElementCollectionSummary($value);
        }

        if (is_array($value)) {
            $firstItem = reset($value);
            if ($firstItem instanceof \craft\base\ElementInterface && count($value) > 0) {
                return $this->sanitizeElementCollectionSummary(
                    \craft\elements\ElementCollection::make($value)
                );
            }

            $result = [];
            foreach (array_slice($value, 0, 20, true) as $k => $v) {
                $result[$k] = $this->sanitizeValue($v, $depth + 1);
            }
            return $result;
        }

        if ($value instanceof \craft\base\ElementInterface) {
            return $this->sanitizeElementSummary($value);
        }

        if ($value instanceof \craft\elements\db\ElementQuery) {
            return ['__type' => 'ElementQuery', 'class' => get_class($value)];
        }

        // ── Craft field data objects ───────────────────────────────────────────

        // Multi-select option field (Checkboxes, MultiSelect) — iterable ArrayObject
        if ($value instanceof \craft\fields\data\MultiOptionsFieldData) {
            $selected = [];
            foreach ($value as $option) {
                /** @var \craft\fields\data\OptionData $option */
                $selected[] = [
                    'value' => $option->value,
                    'label' => $option->label,
                ];
            }
            return [
                '__type'    => 'MultiSelect',
                'selected'  => $selected,
                'value'     => implode(', ', array_column($selected, 'value')),
            ];
        }

        // Single-select option field (Dropdown, RadioButtons, ButtonGroup)
        if ($value instanceof \craft\fields\data\SingleOptionFieldData) {
            return [
                '__type' => 'Option',
                'value'  => $value->value,
                'label'  => $value->label,
            ];
        }

        // Color field
        if ($value instanceof \craft\fields\data\ColorData) {
            return [
                '__type' => 'Color',
                'hex'    => (string)$value,
                'rgb'    => $value->getRgb(),
            ];
        }

        // Link field (Craft 5.3+)
        if ($value instanceof \craft\fields\data\LinkData) {
            return [
                '__type' => 'Link',
                'url'    => (string)$value,
                'label'  => $value->getLabel(),
                'type'   => $value->getType(),
            ];
        }
        // HTML / Rich Text / Markup field data (CKEditor, Redactor, Twig Markup)
        if ($value instanceof \Twig\Markup || (is_object($value) && (str_contains(get_class($value), 'ckeditor') || str_contains(get_class($value), 'redactor') || str_contains(get_class($value), 'htmlfield')))) {
            $html = (string)$value;
            // Cap the raw markup we cache + ship to the viewer. The summary still
            // reports the full text length; the toggle reveals the (capped) markup.
            $truncated = mb_strlen($html) > self::HTML_PREVIEW_CAP;
            return [
                '__type'    => 'Html',
                'html'      => $truncated ? mb_substr($html, 0, self::HTML_PREVIEW_CAP) . '…' : $html,
                'length'    => mb_strlen(strip_tags($html)),
                'truncated' => $truncated,
            ];
        }

        // Any other Craft field data objects that implement Serializable
        if ($value instanceof \craft\base\Serializable) {
            $serialized = $value->serialize();
            if (is_scalar($serialized) || is_null($serialized)) {
                return $serialized;
            }
            if (is_array($serialized)) {
                return $serialized;
            }
        }

        if (is_object($value)) {
            // Use __toString() if available — avoids showing raw class names
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return ['__type' => 'Object', 'class' => get_class($value)];
        }

        return null;
    }

    /**
     * Lightweight summary for a single Craft element (shown inline in Props).
     */
    private function sanitizeElementSummary(\craft\base\ElementInterface $value): array
    {
        return [
            '__type'    => 'Element',
            '__element' => get_class($value),
            'id'        => $value->id,
            'title'     => method_exists($value, '__toString') ? (string)$value : ('Element #' . $value->id),
            'editUrl'   => $value->getCpEditUrl(),
        ];
    }

    /**
     * Lightweight summary for an element collection (shown inline in Props).
     */
    private function sanitizeElementCollectionSummary(\craft\elements\ElementCollection $collection): array
    {
        return [
            '__type'  => 'ElementCollection',
            '__count' => $collection->count(),
        ];
    }
}
