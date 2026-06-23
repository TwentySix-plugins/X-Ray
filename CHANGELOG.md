# Release Notes for X-Ray

## 1.0.2 - 2026-06-23

### Fixed
- Location breadcrumb and "Open in editor" now use each template's real path (relative to the templates root) instead of guessing `templates/{name}.twig`, fixing doubled extensions (e.g. `node.twig.twig`) and wrong paths for nested partials.

## 1.0.1 - 2026-06-22

### Removed
- Craft Element and Element Collection sidebar panels, along with deep element/collection serialization.
- Elements and collections now appear as lightweight inline summaries in the Props panel only.

## 1.0.0 - 2026-06-22

### Added
- Initial release.
- Hover any front-end element inside the control panel viewer to see which Twig template rendered it.
- Props panel showing the variables passed into each component, with PHP type labels.
- Component hierarchy tree (innermost → outermost) and per-template render counts.
- Rich rendering for Craft elements, Matrix blocks, assets (with thumbnails), and field-data types (Options, Color, Link, rich text).
- Globals tab listing every Global Set and its fields.
- "Open in editor" deep links (VS Code, Cursor, Windsurf, PHPStorm, WebStorm, IntelliJ, Zed, Sublime, Nova) with Default and Variable modes.
- Configurable activation parameter, environment gating, template include/exclude globs, accent colour, highlight style, and tooltip options.
