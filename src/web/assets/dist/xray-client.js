/**
 * X-Ray — Front-end Client Script
 *
 * This script is injected into the front-end site only when:
 *   - The URL contains ?xray=1
 *   - The current user is a logged-in Craft admin
 *
 * It communicates with the parent window (the CP viewer) via postMessage.
 */
(function () {
  'use strict';

  // ─── State ────────────────────────────────────────────────────────────────
  let inspectMode = false;
  let currentTarget = null;
  let selectedTarget = null;

  // ─── Config (from the plugin Settings page) ────────────────────────────────
  const CFG    = (window.xrayConfig || window.xrayTheme || {});
  const ACCENT = /^#[0-9a-fA-F]{6}$/.test(CFG.accent || '') ? CFG.accent : '#7B61FF';
  const STYLE  = ['dotted', 'dashed', 'solid'].includes(CFG.style) ? CFG.style : 'dotted';
  const PARAM  = (typeof CFG.param === 'string' && CFG.param) ? CFG.param : 'xray';
  const SHOW_TOOLTIP   = CFG.showTooltip !== false;          // default on
  const TOOLTIP_LABEL  = CFG.tooltipLabel === 'path' ? 'path' : 'name';
  const PERSIST_SELECT  = CFG.persistSelection === true;     // default off
  const BLOCK_EXTERNAL  = CFG.blockExternalNav !== false;    // default on

  // Convert "#rrggbb" → "rgba(r,g,b,a)" so we can tint with transparency.
  function accentRgba(alpha) {
    const r = parseInt(ACCENT.slice(1, 3), 16);
    const g = parseInt(ACCENT.slice(3, 5), 16);
    const b = parseInt(ACCENT.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  // Core styles are applied inline (not just via the CSS bundle) so the
  // highlight renders even if the external stylesheet fails to load/caches.
  const overlay  = document.createElement('div');
  overlay.className = 'xr-overlay';
  Object.assign(overlay.style, {
    position:      'fixed',
    pointerEvents: 'none',
    zIndex:        '2147483640',
    borderRadius:  '5px',
    background:    accentRgba(0.12),
    outline:       '2px ' + STYLE + ' ' + ACCENT,
    outlineOffset: '2px',
    boxShadow:     '0 0 0 1px rgba(13, 13, 17, 0.5)',
    display:       'none',
  });
  document.body.appendChild(overlay);

  // Persistent outline for the *selected* (clicked) component — only created
  // when the setting is enabled. Solid accent so it reads apart from the hover.
  const selectedOverlay = document.createElement('div');
  selectedOverlay.className = 'xr-selected-overlay';
  Object.assign(selectedOverlay.style, {
    position:      'fixed',
    pointerEvents: 'none',
    zIndex:        '2147483639',
    borderRadius:  '5px',
    background:    accentRgba(0.08),
    outline:       '2px solid ' + ACCENT,
    outlineOffset: '2px',
    boxShadow:     '0 0 0 1px rgba(13, 13, 17, 0.5), 0 0 0 4px ' + accentRgba(0.18),
    display:       'none',
  });
  if (PERSIST_SELECT) document.body.appendChild(selectedOverlay);

  const tooltip  = document.createElement('div');
  tooltip.className = 'xr-tooltip';
  Object.assign(tooltip.style, {
    position:      'fixed',
    pointerEvents: 'none',
    zIndex:        '2147483641',
    display:       'none',
    alignItems:    'center',
    gap:           '6px',
    padding:       '5px 10px',
    borderRadius:  '5px',
    font:          "600 11px/1 'SFMono-Regular', Consolas, Menlo, monospace",
    color:         '#fff',
    background:    ACCENT,
    boxShadow:     '0 4px 16px rgba(0,0,0,0.25)',
    whiteSpace:    'nowrap',
    maxWidth:      '400px',
  });
  tooltip.innerHTML = '<span class="xr-tooltip-icon">⬡</span><span class="xr-tooltip-label"></span>';
  document.body.appendChild(tooltip);

  const tooltipLabel = tooltip.querySelector('.xr-tooltip-label');

  // ─── Utilities ────────────────────────────────────────────────────────────

  /**
   * Walk up the DOM to find the nearest element with data-craft-component.
   */
  function findComponent(el) {
    while (el && el !== document.body) {
      if (el.dataset && el.dataset.craftComponent) {
        return el;
      }
      el = el.parentElement;
    }
    return null;
  }

  /**
   * Tally how many times each component template was rendered on the current
   * page, keyed by its data-craft-component value. The full-document scan is
   * memoised for a short window so rapid hovering across many components doesn't
   * re-scan the DOM on every transition; the window keeps the count "live"
   * enough to cover content injected after first paint.
   */
  let _countMap = null;
  let _countMapAt = 0;
  function buildCountMap() {
    const now = Date.now();
    if (_countMap && (now - _countMapAt) < 1000) return _countMap;
    const map = Object.create(null);
    const nodes = document.querySelectorAll('[data-craft-component]');
    for (let i = 0; i < nodes.length; i++) {
      const name = nodes[i].dataset.craftComponent;
      if (name) map[name] = (map[name] || 0) + 1;
    }
    _countMap = map;
    _countMapAt = now;
    return map;
  }

  /**
   * Build a component tree by walking up through all craft components
   * from the given element.
   */
  function buildComponentChain(el) {
    const chain = [];
    const counts = buildCountMap();
    let node = el;
    while (node && node !== document.body) {
      if (node.dataset && node.dataset.craftComponent) {
        const name = node.dataset.craftComponent;
        // Carry only the lightweight token — the viewer fetches props on demand.
        chain.push({
          component: name,
          id: node.dataset.craftId || null,
          // How many times THIS template appears on the page (>= 1).
          count: counts[name] || 1,
        });
      }
      node = node.parentElement;
    }
    return chain;
  }

  /**
   * Get the visual bounding box of a component. The wrapper Craft injects is
   * `display:contents`, which has NO box of its own — so getBoundingClientRect()
   * returns 0×0. In that case we measure the actual rendered contents via a Range.
   */
  function getComponentRect(el) {
    const r = el.getBoundingClientRect();
    if (r.width > 0 || r.height > 0) return r;
    try {
      const range = document.createRange();
      range.selectNodeContents(el);
      const rr = range.getBoundingClientRect();
      if (rr.width > 0 || rr.height > 0) return rr;
    } catch (e) {}
    return r;
  }

  function positionOverlay(el) {
    const r = getComponentRect(el);
    // Inflate the box so the dotted highlight wraps slightly OUTSIDE the element
    // (combined with outline-offset, it sits a few px clear of the real border).
    // The overlay is position:fixed, so viewport coords are used (no scroll offset).
    const pad = 3;
    overlay.style.top    = (r.top    - pad) + 'px';
    overlay.style.left   = (r.left   - pad) + 'px';
    overlay.style.width  = (r.width  + pad * 2) + 'px';
    overlay.style.height = (r.height + pad * 2) + 'px';
    overlay.style.display = 'block';
  }

  // Park the persistent "selected" outline on a component (or hide it).
  function positionSelected(el) {
    if (!PERSIST_SELECT || !el || !document.contains(el)) {
      selectedOverlay.style.display = 'none';
      return;
    }
    const r = getComponentRect(el);
    const pad = 3;
    selectedOverlay.style.top    = (r.top    - pad) + 'px';
    selectedOverlay.style.left   = (r.left   - pad) + 'px';
    selectedOverlay.style.width  = (r.width  + pad * 2) + 'px';
    selectedOverlay.style.height = (r.height + pad * 2) + 'px';
    selectedOverlay.style.display = 'block';
  }

  // What the tooltip shows: component name, or its full template path.
  function labelFor(componentName) {
    if (TOOLTIP_LABEL === 'path') return 'templates/' + componentName + '.twig';
    const i = componentName.lastIndexOf('/');
    return i === -1 ? componentName : componentName.slice(i + 1);
  }

  function showTooltip(componentName) {
    if (!SHOW_TOOLTIP) return;
    const text = labelFor(componentName);
    if (tooltipLabel.textContent !== text) tooltipLabel.textContent = text;
    tooltip.style.display = 'flex';
  }

  /**
   * Park the label right next to the crosshair (+) cursor and keep it on-screen.
   * `tooltip` is position:fixed, so viewport (client) coords are used directly.
   */
  function moveTooltipToCursor(x, y) {
    let tx = x + 16;          // sit just to the lower-right of the cursor
    let ty = y + 18;
    const tw = tooltip.offsetWidth;
    const th = tooltip.offsetHeight;
    if (tx + tw + 8 > window.innerWidth)  tx = x - tw - 14;  // flip left near edge
    if (ty + th + 8 > window.innerHeight) ty = y - th - 14;  // flip up near edge
    tooltip.style.left = Math.max(4, tx) + 'px';
    tooltip.style.top  = Math.max(4, ty) + 'px';
  }

  function hideHighlight() {
    overlay.style.display  = 'none';
    tooltip.style.display  = 'none';
    currentTarget = null;
  }

  // ─── Mouse Events ─────────────────────────────────────────────────────────

  document.addEventListener('mousemove', function (e) {
    if (!inspectMode) return;

    const component = findComponent(e.target);
    if (!component) {
      hideHighlight();
      return;
    }

    // The label tracks the cursor on every move so it stays beside the + cursor.
    if (SHOW_TOOLTIP) moveTooltipToCursor(e.clientX, e.clientY);

    if (component === currentTarget) return;
    currentTarget = component;

    positionOverlay(component);
    showTooltip(component.dataset.craftComponent);

    // Tell parent to preview this component
    window.parent.postMessage({
      type:   'xr:hover',
      chain:  buildComponentChain(component),
    }, '*');
  }, { passive: true });

  document.addEventListener('click', function (e) {
    if (!inspectMode) return;
    e.preventDefault();
    e.stopPropagation();

    const component = findComponent(e.target);
    if (!component) return;

    // Lock the persistent highlight onto the clicked component.
    if (PERSIST_SELECT) {
      selectedTarget = component;
      positionSelected(selectedTarget);
    }

    window.parent.postMessage({
      type:      'xr:select',
      component: component.dataset.craftComponent,
      id:        component.dataset.craftId || null,
      chain:     buildComponentChain(component),
    }, '*');
  }, true);

  document.addEventListener('mouseleave', function () {
    if (!inspectMode) return;
    hideHighlight();
    window.parent.postMessage({ type: 'xr:hover', chain: [] }, '*');
  });

  // Keep the highlight glued to the element while scrolling / resizing
  // (the overlay is position:fixed, so its coords must be recomputed).
  function reposition() {
    if (!inspectMode) return;
    if (selectedTarget) positionSelected(selectedTarget);
    if (!currentTarget) return;
    if (!document.contains(currentTarget)) { hideHighlight(); return; }
    positionOverlay(currentTarget);
  }
  // Coalesce bursts of scroll/resize events into one measurement per frame so
  // we don't run getBoundingClientRect()/Range layout work on every event.
  const raf = (typeof window.requestAnimationFrame === 'function')
    ? window.requestAnimationFrame.bind(window)
    : function (cb) { return setTimeout(cb, 16); };
  let repositionPending = false;
  function scheduleReposition() {
    if (repositionPending) return;
    repositionPending = true;
    raf(function () { repositionPending = false; reposition(); });
  }
  // capture:true so we also catch scrolling inside nested scroll containers.
  window.addEventListener('scroll', scheduleReposition, { passive: true, capture: true });
  window.addEventListener('resize', scheduleReposition, { passive: true });

  // Keep the activation flag across internal navigations, so X-Ray
  // stays alive when you click through to a detail page. External links are
  // blocked here when the "block external navigation" setting is on.
  // (Runs only when NOT inspecting — inspect-mode clicks select components.)
  document.addEventListener('click', function (e) {
    if (inspectMode) return;
    const a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target === '_blank' || e.metaKey || e.ctrlKey) return;
    let url;
    try { url = new URL(a.getAttribute('href'), location.href); } catch (err) { return; }
    if (url.origin !== location.origin) {                       // external link
      if (BLOCK_EXTERNAL) { e.preventDefault(); e.stopPropagation(); }
      return;
    }
    if (url.searchParams.get(PARAM) === '1') return;            // already flagged
    url.searchParams.set(PARAM, '1');
    a.href = url.toString();   // default navigation now carries the flag
  }, true);

  // ─── Message Listener (from parent CP window) ─────────────────────────────

  window.addEventListener('message', function (e) {
    // Only accept commands from our embedder (the CP viewer). This is
    // origin-agnostic on purpose — the CP may live on a different host than the
    // site — but a sibling/child frame can never drive inspect mode.
    if (e.source && e.source !== window.parent) return;
    if (!e.data || typeof e.data.type !== 'string') return;

    switch (e.data.type) {
      case 'xr:enable':
        inspectMode = true;
        document.documentElement.classList.add('xr-cursor-active');
        document.documentElement.style.cursor = 'crosshair';
        break;

      case 'xr:disable':
        inspectMode = false;
        document.documentElement.classList.remove('xr-cursor-active');
        document.documentElement.style.cursor = '';
        hideHighlight();
        selectedTarget = null;
        selectedOverlay.style.display = 'none';
        break;

      case 'xr:ping':
        window.parent.postMessage({ type: 'xr:ready' }, '*');
        break;
    }
  });

  // Notify parent that the script loaded and is ready
  window.parent.postMessage({ type: 'xr:ready' }, '*');
})();
