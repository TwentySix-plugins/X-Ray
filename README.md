# X-Ray

**A component X-ray for Craft CMS.** Inspect your front end from inside the control panel: hover any element to see which Twig template rendered it, and the props (variables) it received — without leaving the CP.

X-Ray wraps your component templates with an interactive overlay. Open the **X-Ray** section in the control panel, point it at any page, and hover. You get the template path, a component hierarchy, per-template render counts, fully-typed props, rich rendering for Craft elements/Matrix blocks/assets, a Globals browser, and one-click "open in editor" deep links.

---

## Requirements

- Craft CMS **5.0.0** or later
- PHP **8.2** or later
- Admin access (X-Ray only ever activates for logged-in admins)

## Installation

From the **Plugin Store**: search for "X-Ray" in your control panel under **Plugins**, then click **Install**.

With **Composer**:

```bash
composer require twentysixx/x-ray
php craft plugin/install x-ray
```

## Usage

1. Open **X-Ray** in the control-panel sidebar.
2. Enter a front-end URL (or use the configured Start URL) and load it in the viewer.
3. Click **Inspect** (or press <kbd>Alt</kbd>+<kbd>I</kbd>) and hover over the page.
4. The sidebar shows the rendered template, its hierarchy, and the props passed in. Click a component to pin it.

X-Ray activates a page by appending `?xray=1` (configurable) and is only ever served to logged-in admins — your public front end is never affected.

### Which templates get annotated

By default, X-Ray annotates partials whose filename starts with `_` (e.g. `_components/_card.twig`). You can change the prefix, or switch to glob-based include/exclude patterns, in **Settings**. Templates that `extends` a layout or define `{% block %}`s are skipped automatically.

## Settings

- **Start URL** — the URL the viewer loads first (defaults to the site base URL).
- **Activation parameter** — the query flag that turns X-Ray on (default `xray`).
- **Active mode** — always, only in dev mode, or only in listed environments.
- **Template wrapping** — filename prefix, plus include/exclude globs.
- **Appearance** — accent colour, highlight style, tooltip label.
- **Behaviour** — edit links, persistent selection, block external navigation.
- **Open in editor** — pick a default editor, or a picker shown on each click; optional local base-path mapping for Docker/DDEV/VM setups.

All settings can also be set in `config/x-ray.php` for environment-specific overrides.

## Privacy

X-Ray runs entirely within your own Craft install. It makes no external/network calls and is served only to authenticated admins.

## Support

- Issues: https://github.com/TwentySix-plugins/X-Ray/issues

## License

See [LICENSE.md](LICENSE.md).
