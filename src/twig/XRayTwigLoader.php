<?php

namespace twentysix\xray\twig;

use Craft;
use twentysix\xray\models\Settings;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Wraps the default Craft Twig loader and injects data-craft-* wrapper tags
 * around component template output when X-Ray is active.
 *
 * This lets the client-side JS identify which Twig template rendered each
 * DOM region on hover, along with its passed props.
 */
class XRayTwigLoader implements LoaderInterface
{
    /** Memoised cache-key suffix for this request (depends only on settings). */
    private ?string $suffix = null;

    public function __construct(private LoaderInterface $inner, private Settings $settings)
    {
    }

    public function getSourceContext(string $name): Source
    {
        $source = $this->inner->getSourceContext($name);
        $code   = $source->getCode();

        if ($this->shouldWrap($name, $code)) {
            $escaped = json_encode($name, JSON_UNESCAPED_SLASHES);
            // Emit the template's *real* path (relative to the templates root) so
            // the viewer can show/open the actual file instead of reconstructing a
            // guess from the logical name. Static per template, so it's safe to bake
            // into the compiled (cached) output.
            $rel = $this->relativeTemplatePath($source->getPath());
            $fileAttr = $rel !== null
                ? ' data-craft-file={{ ' . json_encode($rel, JSON_UNESCAPED_SLASHES) . "|e('html_attr') }}"
                : '';
            // Emit only a short token (data-craft-id); the viewer fetches the
            // full props on demand. Keeps the HTML payload tiny even on pages
            // with many components and deeply-nested data.
            $wrapped = <<<TWIG
{% if xrayActive() %}<div data-craft-component={{ {$escaped}|e('html_attr') }}{$fileAttr} data-craft-id="{{ xrayPropsToken(_context)|e('html_attr') }}" style="display:contents;">{% endif %}
{$code}
{% if xrayActive() %}</div>{% endif %}
TWIG;
            return new Source($wrapped, $source->getName(), $source->getPath());
        }

        return $source;
    }

    public function getCacheKey(string $name): string
    {
        // The wrapped source differs from the original template, but Twig's
        // compiled-template cache is keyed *solely* on this value. If we returned
        // the inner key unchanged, whichever variant compiled first (wrapped on a
        // site request, or plain on a CP/console request that shares the template)
        // would be reused for the other — so components could silently fail to
        // annotate. Suffixing the key gives the X-Ray build its own namespace.
        //
        // The suffix also folds in the wrap-affecting settings (prefix + globs),
        // so changing which templates get wrapped busts the compiled cache without
        // needing a manual cache clear.
        return $this->inner->getCacheKey($name) . $this->cacheSuffix();
    }

    /**
     * A short, stable suffix derived from the settings that influence wrapping.
     * Computed once per request.
     */
    private function cacheSuffix(): string
    {
        if ($this->suffix === null) {
            $sig = $this->settings->getWrapPrefix() . '|'
                . implode(',', $this->settings->getIncludePatterns()) . '|'
                . implode(',', $this->settings->getExcludePatterns());
            $this->suffix = '|xray:' . substr(sha1($sig), 0, 10);
        }
        return $this->suffix;
    }

    /**
     * The template's real path, relative to the site templates root
     * (e.g. "parts/region/node.twig"). Returns null when the resolved path
     * is empty or lives outside the templates root, so the viewer can fall
     * back to its best-effort guess.
     */
    private function relativeTemplatePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/\\')) . '/';
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }
        return null;
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->inner->isFresh($name, $time);
    }

    public function exists(string $name): bool
    {
        return $this->inner->exists($name);
    }

    /**
     * Decide if this template should be wrapped with X-Ray metadata.
     *
     * Templates are selected by the configured filename prefix (default "_"),
     * optionally overridden by include glob patterns, minus any exclude globs.
     * Layout templates, SVGs, XML, etc. are always skipped.
     */
    private function shouldWrap(string $name, string $code): bool
    {
        if (str_starts_with($name, '@')) return false;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'xml', 'json', 'txt', 'css', 'js'], true)) return false;

        // Exclude globs win outright.
        foreach ($this->settings->getExcludePatterns() as $pattern) {
            if (fnmatch($pattern, $name)) return false;
        }

        // If include globs are set, they are the ONLY rule — a template must
        // match one of them (the prefix is ignored). Otherwise fall back to the
        // filename-prefix rule.
        $includes = $this->settings->getIncludePatterns();
        if (!empty($includes)) {
            $matched = false;
            foreach ($includes as $pattern) {
                if (fnmatch($pattern, $name)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        } else {
            $prefix = $this->settings->getWrapPrefix();
            // A non-empty prefix must match the basename; empty prefix = wrap all.
            if ($prefix !== '' && !str_starts_with(basename($name), $prefix)) {
                return false;
            }
        }

        // Skip layout templates (define blocks)
        if (preg_match('/\{%-?\s*block\s+\w+/', $code)) return false;

        // Skip templates that extend a parent
        if (preg_match('/\{%-?\s*extends\s/', $code)) return false;

        // Skip SVG/XML content
        $trimmed = ltrim($code);
        if (str_starts_with($trimmed, '<svg') || str_starts_with($trimmed, '<?xml')) return false;

        return true;
    }
}
