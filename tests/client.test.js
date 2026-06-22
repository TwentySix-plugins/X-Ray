/**
 * X-Ray — front-end client tests.
 *
 * Loads the REAL xray-client.js inside a hand-rolled DOM/window stub and
 * drives it through the message/mouse flows, asserting the visible behaviour
 * for each Settings-driven config. No external dependencies — `node` only.
 *
 *   node plugins/x-ray/tests/client.test.js
 */
'use strict';
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const { URL } = require('url');

const CLIENT = path.join(__dirname, '..', 'src', 'web', 'assets', 'dist', 'xray-client.js');
const SOURCE = fs.readFileSync(CLIENT, 'utf8');

let pass = 0, fail = 0;
const failures = [];
function check(name, fn) {
  try { fn(); pass++; console.log('  \x1b[32m✓\x1b[0m ' + name); }
  catch (e) { fail++; failures.push(name + ' — ' + e.message); console.log('  \x1b[31m✗ ' + name + ' — ' + e.message + '\x1b[0m'); }
}
function assert(cond, msg) { if (!cond) throw new Error(msg || 'assertion failed'); }
function group(n) { console.log('\n\x1b[33m▸ ' + n + '\x1b[0m'); }

// ── Minimal DOM element stub ───────────────────────────────────────────────
function makeEl(tag) {
  const el = {
    tagName: tag, style: {}, className: '', dataset: {}, children: [],
    offsetWidth: 0, offsetHeight: 0, _html: '', _label: null,
    classList: {
      _s: new Set(),
      add(c) { this._s.add(c); }, remove(c) { this._s.delete(c); },
      toggle(c, f) { const has = this._s.has(c); (f === undefined ? !has : f) ? this._s.add(c) : this._s.delete(c); },
      contains(c) { return this._s.has(c); },
    },
    appendChild(c) { this.children.push(c); return c; },
    setAttribute(k, v) { el[k] = v; },
    addEventListener() {}, removeEventListener() {},
    getBoundingClientRect() { return { top: 10, left: 10, width: 100, height: 20 }; },
    querySelector(sel) {
      if (sel === '.xr-tooltip-label') return (el._label || (el._label = makeEl('span')));
      return null;
    },
    contains() { return true; },
    closest() { return null; },
    get innerHTML() { return el._html; },
    set innerHTML(v) { el._html = v; },
  };
  return el;
}

// Registry of components "on the page", scanned by document.querySelectorAll
// so the client's render-count logic has something to tally. Reset per client.
let DOM_COMPONENTS = [];

// ── Load the client with a given config, return handles to drive it ────────
function loadClient(config) {
  DOM_COMPONENTS = [];
  const created = [];
  const body = makeEl('body');
  const documentElement = makeEl('html');
  const docL = {};
  const winL = {};
  const posted = [];

  const document = {
    body, documentElement,
    createElement(t) { const e = makeEl(t); created.push(e); return e; },
    createRange() { return { selectNodeContents() {}, getBoundingClientRect() { return { top: 0, left: 0, width: 0, height: 0 }; } }; },
    addEventListener(t, fn) { (docL[t] = docL[t] || []).push(fn); },
    querySelectorAll(sel) { return sel === '[data-craft-component]' ? DOM_COMPONENTS.slice() : []; },
    contains() { return true; },
  };
  const location = { origin: 'http://localhost:8080', href: 'http://localhost:8080/games/neon-void' };
  const parentWin = { postMessage(m) { posted.push(m); } };
  const window = {
    xrayConfig: config,
    innerWidth: 1200, innerHeight: 800,
    location,
    addEventListener(t, fn) { (winL[t] = winL[t] || []).push(fn); },
    removeEventListener() {},
    parent: parentWin,
  };

  const sandbox = { window, document, location, URL, console, JSON, parseInt, Math, Object, RegExp, Date, setTimeout: () => {} };
  vm.createContext(sandbox);
  vm.runInContext(SOURCE, sandbox);

  const byClass = (c) => created.find(e => e.className === c);
  return {
    posted, docL, winL, parentWin,
    overlay: byClass('xr-overlay'),
    selectedOverlay: byClass('xr-selected-overlay'),
    tooltip: byClass('xr-tooltip'),
    documentElement,
    // Default the message source to the real parent window so the client's
    // "only trust my embedder" guard accepts it; pass an explicit source to
    // simulate a foreign frame.
    fireMessage: (data, source) => (winL.message || []).forEach(fn => fn({ data, source: source === undefined ? parentWin : source, origin: 'http://localhost:8080' })),
    fireMouseMove: (target, x, y) => (docL.mousemove || []).forEach(fn => fn({ target, clientX: x || 50, clientY: y || 50 })),
    fireSelectClick: (target) => docL.click[0]({ preventDefault() {}, stopPropagation() {}, target }),
    fireLinkClick: (ev) => docL.click[1](ev),
  };
}

function makeComponent(name, token) {
  const c = makeEl('div');
  c.dataset.craftComponent = name;
  c.dataset.craftId = token || ('xr_' + name.replace(/\W/g, ''));
  c.parentElement = undefined;
  DOM_COMPONENTS.push(c);   // register so querySelectorAll can count it
  return c;
}

// ─── Tests ─────────────────────────────────────────────────────────────────

group('Boot & lifecycle');
check('posts xr:ready on load', () => {
  const c = loadClient({});
  assert(c.posted.some(m => m.type === 'xr:ready'), 'no xr:ready posted');
});
check('xr:enable sets crosshair cursor', () => {
  const c = loadClient({});
  c.fireMessage({ type: 'xr:enable' });
  assert(c.documentElement.style.cursor === 'crosshair', 'cursor not crosshair');
  assert(c.documentElement.classList.contains('xr-cursor-active'), 'missing active class');
});
check('xr:disable clears cursor', () => {
  const c = loadClient({});
  c.fireMessage({ type: 'xr:enable' });
  c.fireMessage({ type: 'xr:disable' });
  assert(c.documentElement.style.cursor === '', 'cursor not cleared');
});
check('xr:ping replies xr:ready', () => {
  const c = loadClient({});
  const before = c.posted.length;
  c.fireMessage({ type: 'xr:ping' });
  assert(c.posted.slice(before).some(m => m.type === 'xr:ready'), 'no ready after ping');
});

group('Message source validation');
check('ignores commands from a foreign frame', () => {
  const c = loadClient({});
  // A message from some other window (not our embedder) must not toggle inspect.
  c.fireMessage({ type: 'xr:enable' }, { postMessage() {} });
  assert(c.documentElement.style.cursor !== 'crosshair', 'foreign xr:enable was honoured');
});
check('ignores ping from a foreign frame', () => {
  const c = loadClient({});
  const before = c.posted.length;
  c.fireMessage({ type: 'xr:ping' }, { postMessage() {} });
  assert(!c.posted.slice(before).some(m => m.type === 'xr:ready'), 'replied to a foreign ping');
});
check('accepts commands from the parent window', () => {
  const c = loadClient({});
  c.fireMessage({ type: 'xr:enable' }, c.parentWin);
  assert(c.documentElement.style.cursor === 'crosshair', 'parent xr:enable not honoured');
});

group('Theming');
check('overlay outline uses accent + style from config', () => {
  const c = loadClient({ accent: '#22C55E', style: 'dashed' });
  assert(c.overlay.style.outline.includes('dashed'), 'style not applied: ' + c.overlay.style.outline);
  assert(c.overlay.style.outline.includes('#22C55E'), 'accent not applied: ' + c.overlay.style.outline);
});
check('invalid accent falls back to default', () => {
  const c = loadClient({ accent: 'lime', style: 'solid' });
  assert(c.overlay.style.outline.includes('#7B61FF'), 'fallback accent missing');
});
check('tooltip background uses accent', () => {
  const c = loadClient({ accent: '#FF0000' });
  assert(c.tooltip.style.background === '#FF0000', 'tooltip bg wrong: ' + c.tooltip.style.background);
});

group('Hover → overlay + message');
check('hover shows overlay and posts xr:hover chain', () => {
  const c = loadClient({});
  c.fireMessage({ type: 'xr:enable' });
  c.fireMouseMove(makeComponent('_components/_block-car'));
  assert(c.overlay.style.display === 'block', 'overlay not shown');
  const hover = c.posted.filter(m => m.type === 'xr:hover').pop();
  assert(hover && hover.chain.length === 1, 'no hover chain');
  assert(hover.chain[0].component === '_components/_block-car', 'wrong component in chain');
  assert(typeof hover.chain[0].id === 'string' && hover.chain[0].id.startsWith('xr_'), 'chain carries a token id, not inline props');
  assert(!('props' in hover.chain[0]), 'chain must NOT carry inline props');
});
check('hover does nothing when not inspecting', () => {
  const c = loadClient({});
  c.fireMouseMove(makeComponent('_x'));
  assert(c.overlay.style.display !== 'block', 'overlay shown without inspect');
});

group('Render count (template include counter)');
check('chain entries carry how many times the template is on the page', () => {
  const c = loadClient({});
  c.fireMessage({ type: 'xr:enable' });
  // Three instances of the same template, plus one of a different template.
  makeComponent('_components/_lab-item');
  makeComponent('_components/_lab-item');
  const hovered = makeComponent('_components/_lab-item');
  makeComponent('_components/_game-card');
  c.fireMouseMove(hovered);
  const hover = c.posted.filter(m => m.type === 'xr:hover').pop();
  assert(hover && hover.chain.length === 1, 'no hover chain');
  assert(hover.chain[0].count === 3, 'expected count 3, got ' + hover.chain[0].count);
});
check('a unique template reports a count of 1', () => {
  const c = loadClient({});
  c.fireMessage({ type: 'xr:enable' });
  const only = makeComponent('_components/_solo');
  c.fireMouseMove(only);
  const hover = c.posted.filter(m => m.type === 'xr:hover').pop();
  assert(hover.chain[0].count === 1, 'expected count 1, got ' + hover.chain[0].count);
});

group('Tooltip config');
check('default tooltip shows component basename', () => {
  const c = loadClient({});
  c.fireMessage({ type: 'xr:enable' });
  c.fireMouseMove(makeComponent('_components/_block-car'));
  assert(c.tooltip.style.display === 'flex', 'tooltip not shown');
  assert(c.tooltip._label.textContent === '_block-car', 'label not basename: ' + c.tooltip._label.textContent);
});
check('tooltipLabel=path shows full template path', () => {
  const c = loadClient({ tooltipLabel: 'path' });
  c.fireMessage({ type: 'xr:enable' });
  c.fireMouseMove(makeComponent('_components/_block-car'));
  assert(c.tooltip._label.textContent === 'templates/_components/_block-car.twig', 'wrong path: ' + c.tooltip._label.textContent);
});
check('showTooltip=false suppresses the tooltip', () => {
  const c = loadClient({ showTooltip: false });
  c.fireMessage({ type: 'xr:enable' });
  c.fireMouseMove(makeComponent('_components/_block-car'));
  assert(c.tooltip.style.display !== 'flex', 'tooltip shown when disabled');
  assert(c.overlay.style.display === 'block', 'overlay should still show');
});

group('Persist selection');
check('persistSelection=true outlines clicked component + posts xr:select', () => {
  const c = loadClient({ persistSelection: true });
  c.fireMessage({ type: 'xr:enable' });
  c.fireSelectClick(makeComponent('_components/_block-detail'));
  assert(c.selectedOverlay && c.selectedOverlay.style.display === 'block', 'selected overlay not shown');
  assert(c.posted.some(m => m.type === 'xr:select'), 'no xr:select posted');
});
check('persistSelection=false leaves no selected overlay element', () => {
  const c = loadClient({ persistSelection: false });
  c.fireMessage({ type: 'xr:enable' });
  c.fireSelectClick(makeComponent('_x'));
  // element is created but never appended/shown
  assert(!c.selectedOverlay || c.selectedOverlay.style.display !== 'block', 'selected overlay shown when off');
  assert(c.posted.some(m => m.type === 'xr:select'), 'xr:select should still fire');
});

group('External navigation + param rewriting');
function anchor(href) {
  let set = null;
  return {
    target: '', getAttribute() { return href; },
    get href() { return set || href; }, set href(v) { set = v; },
    _wasSet: () => set,
  };
}
function linkEvent(a) {
  let prevented = false;
  return {
    metaKey: false, ctrlKey: false,
    preventDefault() { prevented = true; }, stopPropagation() {},
    target: { closest() { return a; } },
    _prevented: () => prevented,
  };
}
check('blockExternalNav=true prevents external link clicks', () => {
  const c = loadClient({ blockExternalNav: true });
  const ev = linkEvent(anchor('https://evil.example.com/x'));
  c.fireLinkClick(ev);
  assert(ev._prevented(), 'external nav not prevented');
});
check('blockExternalNav=false allows external link clicks', () => {
  const c = loadClient({ blockExternalNav: false });
  const ev = linkEvent(anchor('https://evil.example.com/x'));
  c.fireLinkClick(ev);
  assert(!ev._prevented(), 'external nav wrongly prevented');
});
check('internal links get the configured activation param', () => {
  const c = loadClient({ param: 'inspect-me' });
  const a = anchor('/games/dragon-realms-vi');
  c.fireLinkClick(linkEvent(a));
  assert(/inspect-me=1/.test(a._wasSet() || ''), 'param not appended: ' + a._wasSet());
});
check('default param is xray', () => {
  const c = loadClient({});
  const a = anchor('/news');
  c.fireLinkClick(linkEvent(a));
  assert(/xray=1/.test(a._wasSet() || ''), 'default param missing: ' + a._wasSet());
});

// ─── Summary ───────────────────────────────────────────────────────────────
const total = pass + fail;
console.log('\n' + '═'.repeat(52));
if (fail === 0) {
  console.log('\x1b[32m✅  ALL ' + total + ' CLIENT TESTS PASSED\x1b[0m\n');
  process.exit(0);
} else {
  console.log('\x1b[31m❌  ' + fail + ' of ' + total + ' FAILED\x1b[0m');
  failures.forEach(f => console.log('   • ' + f));
  process.exit(1);
}
