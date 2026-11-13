const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');

const bundle = buildSync({
  stdin: {
    contents: `
      import { Application } from '@hotwired/stimulus';
      import ReorderController from '../../assets/controllers/reorder_controller.js';
      import SectionTabsController from '../../assets/controllers/section_tabs_controller.js';
      import { installSectionTabsFrameRefresh } from '../../assets/section_tabs_frame_refresh.js';
      export { Application, ReorderController, SectionTabsController, installSectionTabsFrameRefresh };
    `,
    resolveDir: __dirname,
    sourcefile: 'section-tabs-test-entry.js',
  },
  bundle: true,
  platform: 'node',
  format: 'cjs',
  write: false,
}).outputFiles[0].text;
const compiled = new Module(path.join(__dirname, '.section-tabs-test-bundle.cjs'), module);
compiled.filename = path.join(__dirname, '.section-tabs-test-bundle.cjs');
compiled.paths = module.paths;
compiled._compile(bundle, compiled.filename);
const { Application, ReorderController, SectionTabsController, installSectionTabsFrameRefresh } = compiled.exports;

function cardMarkup(spaceId, sections, ungroupedLinks = []) {
  const items = sections.map((section) => `
    <li class="section-divider" data-section-id="${section.id}" data-menu-item="section:${section.id}">
      <div class="section-divider-label"><span>${section.title}</span></div>
      <div class="section-divider-actions"><button type="button">edit</button><button type="button">delete</button></div>
    </li>
    ${section.links.map((link) => `<li class="link-row" draggable="true" data-link-id="${link.id}" data-menu-item="link:${link.id}"><span class="resource-title">${link.title}</span></li>`).join('')}
  `).join('');
  const ungrouped = ungroupedLinks.map((link) => `<li class="link-row" draggable="true" data-link-id="${link.id}" data-menu-item="link:${link.id}"><span class="resource-title">${link.title}</span></li>`).join('');
  return `
    <article class="space-card" data-space-order-id="${spaceId}" data-controller="reorder"
      data-action="dragover->reorder#overContainer drop->reorder#dropOnContainer"
      data-reorder-space-value="${spaceId}" data-reorder-url-value="/spaces/${spaceId}/reorder"
      data-reorder-token-value="token-${spaceId}"
      data-reorder-check-url-value="/spaces/${spaceId}/links/check"
      data-reorder-token-url-value="/spaces/${spaceId}/links/drop-token"
      data-reorder-create-url-value="/spaces/${spaceId}/links/drop"
      data-reorder-create-token-value="token-${spaceId}">
      <div class="space-card-content" data-controller="section-tabs">
        <div class="drop-link-preview" data-reorder-target="dropPreview" hidden>
          <span data-reorder-target="previewSection"></span>
          <b data-reorder-target="previewTitle"></b>
          <code data-reorder-target="previewUrl"></code>
          <p data-reorder-target="previewMessage"></p>
          <button data-reorder-target="confirmButton" disabled></button>
        </div>
        <ul class="link-list">${ungrouped}${items}</ul>
      </div>
    </article>`;
}

async function setupBoard(cards) {
  const dom = new JSDOM(`<!doctype html><html><body><turbo-frame id="neox_board_main">${cards.join('')}</turbo-frame></body></html>`, { url: 'https://board.example.test/', pretendToBeVisual: true });
  const { window } = dom;
  global.window = window;
  global.document = window.document;
  global.Event = window.Event;
  global.CustomEvent = window.CustomEvent;
  global.HTMLElement = window.HTMLElement;
  global.Element = window.Element;
  global.Node = window.Node;
  global.MutationObserver = window.MutationObserver;
  global.requestAnimationFrame = window.requestAnimationFrame.bind(window);
  global.DOMParser = window.DOMParser;
  global.KeyboardEvent = window.KeyboardEvent;
  global.MouseEvent = window.MouseEvent;
  global.CSS = window.CSS || { escape: (value) => value.replace(/[^a-zA-Z0-9_-]/g, '\\$&') };
  if (!window.CSS) window.CSS = global.CSS;
  if (!window.CSS.escape) window.CSS.escape = global.CSS.escape;
  const requests = [];
  global.fetch = async (url, options) => {
    requests.push({ url, ...options, params: new URLSearchParams(options.body) });
    return { ok: true, text: async () => '' };
  };

  const app = Application.start(window.document.documentElement);
  app.register('reorder', ReorderController);
  app.register('section-tabs', SectionTabsController);
  window.NeoxBoard = { app };
  const uninstallFrameRefresh = installSectionTabsFrameRefresh(window.document, app);
  await new Promise((resolve) => setTimeout(resolve, 0));
  return { dom, window, app, requests, uninstallFrameRefresh };
}

test('search results rebuild section tabs after a Turbo frame load', async (t) => {
  const board = await setupBoard([cardMarkup('space-search', [
    { id: 'dev', title: 'Dev', links: [{ id: 'dev-link', title: 'Neonatis' }] },
    { id: 'prod', title: 'Prod', links: [{ id: 'prod-link', title: 'Neona project' }] },
  ])]);
  t.after(() => { board.uninstallFrameRefresh(); board.app.stop(); board.dom.window.close(); });

  const card = document.querySelector('[data-space-order-id="space-search"]');
  const list = card.querySelector('.link-list');
  list.innerHTML = `
    <li class="section-divider" data-section-id="dev"><div class="section-divider-label"><span>Dev</span></div><div class="section-divider-actions"><button>Edit</button></div></li>
    <li class="link-row" data-link-id="dev-match">Neonatis</li>
    <li class="section-divider" data-section-id="prod"><div class="section-divider-label"><span>Prod</span></div><div class="section-divider-actions"><button>Edit</button></div></li>
    <li class="link-row" data-link-id="prod-match">Neona project</li>`;

  document.querySelector('turbo-frame').dispatchEvent(new board.window.Event('turbo:frame-load', { bubbles: true }));
  await new Promise((resolve) => setTimeout(resolve, 100));

  const tabs = card.querySelectorAll('.section-tabs [role="tab"]');
  assert.deepEqual([...tabs].map((tab) => tab.textContent), ['Dev', 'Prod']);
  assert.equal(card.querySelectorAll('.section-divider:not([hidden])').length, 0, 'section divider rows are hidden in search results');
  assert.equal(card.querySelector('[data-link-id="dev-match"]').hidden, false, 'the selected section result is visible');
  assert.equal(card.querySelector('[data-link-id="prod-match"]').hidden, true, 'results from other sections stay hidden');

  tabs[1].click();
  assert.equal(card.querySelector('[data-link-id="dev-match"]').hidden, true);
  assert.equal(card.querySelector('[data-link-id="prod-match"]').hidden, false);
});

function sectionController(app, spaceId) {
  const card = document.querySelector(`[data-space-order-id="${spaceId}"]`);
  const content = card.querySelector('.space-card-content');
  return app.getControllerForElementAndIdentifier(content, 'section-tabs');
}

function reorderController(app, spaceId) {
  const card = document.querySelector(`[data-space-order-id="${spaceId}"]`);
  return app.getControllerForElementAndIdentifier(card, 'reorder');
}

function simulateDragStart(reorder, row) {
  const dataTransfer = {
    effectAllowed: '',
    setData() {},
    setDragImage() {},
  };
  reorder.start({ currentTarget: row, dataTransfer });
}

function dispatchDrop(target, type, clientX = 1) {
  const event = new window.Event(type, { bubbles: true, cancelable: true });
  Object.defineProperties(event, {
    dataTransfer: { value: { dropEffect: '' } },
    clientX: { value: clientX },
  });
  target.dispatchEvent(event);
  return event;
}

test('section tabs survive repeated Turbo morphs without duplicating tab strips', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [
    { id: 'alpha', title: 'Alpha', links: [{ id: 'link-a', title: 'A' }] },
    { id: 'beta', title: 'Beta', links: [{ id: 'link-b', title: 'B' }] },
  ])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });

  const sections = sectionController(board.app, 'space-a');
  const beta = sections.groups.find((group) => group.id === 'beta');
  sections.selectTab(beta.tab, sections.list, beta);

  // Simulate repeated controller reconnects during a card move leaving stale
  // tab strips in the card, as seen in the reported screenshot.
  sections.tabs.after(sections.tabs.cloneNode(true), sections.tabs.cloneNode(true));
  assert.equal(sections.element.querySelectorAll(':scope > .section-tabs').length, 3);

  sections.prepareForMorph();
  assert.equal(sections.element.querySelectorAll(':scope > .section-tabs').length, 0, 'all generated strips are removed before morphing');
  assert.equal(beta.divider.querySelector('.section-divider-actions') !== null, true, 'section actions return to the server-rendered divider');
  assert.equal(document.querySelector('[data-link-id="link-a"]').hidden, false, 'the pre-morph list is restored');

  sections.rebuildAfterMorph();
  const rebuilt = sectionController(board.app, 'space-a');
  assert.ok(rebuilt.tabs?.isConnected, 'tabs are rebuilt after morphing');
  assert.equal(rebuilt.tabs.querySelector('[data-section-id="beta"] .section-tab').getAttribute('aria-selected'), 'true');
  assert.equal(document.querySelector('[data-link-id="link-a"]').hidden, true, 'links remain filtered to the selected tab');

  for (let index = 0; index < 3; index += 1) {
    rebuilt.prepareForMorph();
    rebuilt.rebuildAfterMorph();
    assert.equal(rebuilt.element.querySelectorAll(':scope > .section-tabs').length, 1, 'every morph cycle leaves one tab strip');
    assert.equal(rebuilt.element.querySelectorAll('.section-divider-actions').length, 2, 'section actions are never duplicated or lost');
  }
});

async function waitForRequest(requests) {
  for (let i = 0; i < 10 && requests.length === 0; i += 1) await new Promise((resolve) => setTimeout(resolve, 0));
  assert.ok(requests.length > 0, 'expected a reorder request');
  return requests.at(-1);
}

test('dropping a link on another section tab moves it and persists the new section order', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [
    { id: 'alpha', title: 'Alpha', links: [{ id: 'link-one', title: 'One' }] },
    { id: 'beta', title: 'Beta', links: [{ id: 'link-two', title: 'Two' }] },
  ])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });

  const sections = sectionController(board.app, 'space-a');
  const reorder = reorderController(board.app, 'space-a');
  const [alpha, beta] = sections.groups;
  const link = document.querySelector('[data-link-id="link-one"]');
  simulateDragStart(reorder, link);

  const target = beta.tab.closest('.section-tab-item');
  const over = dispatchDrop(target, 'dragover');
  assert.equal(over.defaultPrevented, true, 'the tab should accept the dragged link');
  dispatchDrop(target, 'drop');

  const request = await waitForRequest(board.requests);
  assert.equal(alpha.links.includes(link), false);
  assert.equal(beta.links.includes(link), true);
  assert.equal(link.parentElement, sections.list);
  assert.deepEqual([...sections.list.querySelectorAll('[data-menu-item]')].map((item) => item.dataset.menuItem), [
    'section:alpha', 'section:beta', 'link:link-two', 'link:link-one',
  ]);
  assert.equal(beta.tab.getAttribute('aria-selected'), 'true');
  assert.deepEqual(request.params.getAll('items[]'), [
    'section:alpha', 'section:beta', 'link:link-two', 'link:link-one',
  ]);
});

test('dragging a section tab reorders its group and persists links with that section', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [
    { id: 'alpha', title: 'Alpha', links: [{ id: 'link-a', title: 'A' }] },
    { id: 'beta', title: 'Beta', links: [{ id: 'link-b', title: 'B' }] },
    { id: 'gamma', title: 'Gamma', links: [{ id: 'link-c', title: 'C' }] },
  ])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });

  const sections = sectionController(board.app, 'space-a');
  const alpha = sections.groups[0];
  const gamma = sections.groups[2];
  const dataTransfer = {
    effectAllowed: '',
    dropEffect: '',
    payload: '',
    setData(type, value) { if (type === 'text/plain') this.payload = value; },
    getData(type) { return type === 'text/plain' ? this.payload : ''; },
  };
  const dragStart = new board.window.Event('dragstart', { bubbles: true, cancelable: true });
  Object.defineProperty(dragStart, 'dataTransfer', { value: dataTransfer });
  alpha.tab.closest('.section-tab-item').querySelector('.section-tab-grip').dispatchEvent(dragStart);
  gamma.tab.getBoundingClientRect = () => ({ left: 0, width: 100 });

  const target = gamma.tab.closest('.section-tab-item');
  const dragOver = new board.window.Event('dragover', { bubbles: true, cancelable: true });
  Object.defineProperties(dragOver, { dataTransfer: { value: dataTransfer }, clientX: { value: 90 } });
  target.dispatchEvent(dragOver);
  assert.equal(dragOver.defaultPrevented, true);

  const drop = new board.window.Event('drop', { bubbles: true, cancelable: true });
  Object.defineProperties(drop, { dataTransfer: { value: dataTransfer }, clientX: { value: 90 } });
  target.dispatchEvent(drop);
  const request = await waitForRequest(board.requests);

  assert.deepEqual(sections.groups.map((group) => group.id), ['beta', 'gamma', 'alpha']);
  assert.deepEqual([...sections.tabs.querySelectorAll('.section-tab-item')].map((item) => item.dataset.sectionId), ['beta', 'gamma', 'alpha']);
  assert.deepEqual([...sections.list.querySelectorAll('[data-menu-item]')].map((item) => item.dataset.menuItem), [
    'section:beta', 'link:link-b', 'section:gamma', 'link:link-c', 'section:alpha', 'link:link-a',
  ]);
  assert.deepEqual(request.params.getAll('items[]'), [
    'section:beta', 'link:link-b', 'section:gamma', 'link:link-c', 'section:alpha', 'link:link-a',
  ]);
});

test('dropping a link onto a section in another space updates both ordered lists', async (t) => {
  const board = await setupBoard([
    cardMarkup('space-source', [{ id: 'source-section', title: 'Source', links: [{ id: 'move-me', title: 'Move me' }] }]),
    cardMarkup('space-target', [{ id: 'target-section', title: 'Target', links: [{ id: 'stay', title: 'Stay' }] }]),
  ]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });

  const sourceSections = sectionController(board.app, 'space-source');
  const targetSections = sectionController(board.app, 'space-target');
  const sourceReorder = reorderController(board.app, 'space-source');
  const link = document.querySelector('[data-link-id="move-me"]');
  simulateDragStart(sourceReorder, link);

  const target = targetSections.groups[0].tab.closest('.section-tab-item');
  assert.equal(dispatchDrop(target, 'dragover').defaultPrevented, true);
  dispatchDrop(target, 'drop');

  const request = await waitForRequest(board.requests);
  assert.equal(sourceSections.groups[0].links.includes(link), false);
  assert.equal(targetSections.groups[0].links.includes(link), true);
  assert.equal(document.querySelector('[data-space-order-id="space-source"] [data-link-id="move-me"]'), null);
  assert.equal(document.querySelector('[data-space-order-id="space-target"] [data-link-id="move-me"]'), link);
  assert.deepEqual(request.params.getAll('items[]'), ['section:target-section', 'link:stay', 'link:move-me']);
  assert.equal(request.params.get('sourceSpaceId'), 'space-source');
  assert.deepEqual(request.params.getAll('sourceItems[]'), ['section:source-section']);
});

test('dropping a complete section onto another card immediately creates its tab and moves its links', async (t) => {
  const board = await setupBoard([
    cardMarkup('space-source', [{ id: 'moving-section', title: 'Moving section', links: [{ id: 'moving-link', title: 'Move with section' }] }]),
    cardMarkup('space-target', [{ id: 'target-section', title: 'Target section', links: [{ id: 'staying-link', title: 'Stay' }] }]),
  ]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });

  const sourceSections = sectionController(board.app, 'space-source');
  const targetSections = sectionController(board.app, 'space-target');
  const sourceGroup = sourceSections.groups[0];
  const targetGroup = targetSections.groups[0];
  const dataTransfer = {
    effectAllowed: '',
    dropEffect: '',
    payload: '',
    setData(type, value) { if (type === 'text/plain') this.payload = value; },
    getData(type) { return type === 'text/plain' ? this.payload : ''; },
  };
  const start = new board.window.Event('dragstart', { bubbles: true, cancelable: true });
  Object.defineProperty(start, 'dataTransfer', { value: dataTransfer });
  sourceGroup.tab.closest('.section-tab-item').querySelector('.section-tab-grip').dispatchEvent(start);
  targetGroup.tab.getBoundingClientRect = () => ({ left: 0, width: 100 });

  const targetTab = targetGroup.tab.closest('.section-tab-item');
  const over = new board.window.Event('dragover', { bubbles: true, cancelable: true });
  Object.defineProperties(over, { dataTransfer: { value: dataTransfer }, clientX: { value: 90 } });
  targetTab.dispatchEvent(over);
  assert.equal(over.defaultPrevented, true, 'another card should accept the dragged section');

  const drop = new board.window.Event('drop', { bubbles: true, cancelable: true });
  Object.defineProperties(drop, { dataTransfer: { value: dataTransfer }, clientX: { value: 90 } });
  targetTab.dispatchEvent(drop);
  const request = await waitForRequest(board.requests);

  assert.deepEqual(sourceSections.groups.map((group) => group.id), []);
  assert.deepEqual(targetSections.groups.map((group) => group.id), ['target-section', 'moving-section']);
  assert.equal(document.querySelector('[data-space-order-id="space-source"] [data-section-id="moving-section"]'), null);
  assert.ok(document.querySelector('[data-space-order-id="space-target"] [data-section-id="moving-section"]'));
  assert.ok(document.querySelector('[data-space-order-id="space-target"] [data-link-id="moving-link"]'));
  assert.deepEqual(request.params.getAll('items[]'), ['section:target-section', 'link:staying-link', 'section:moving-section', 'link:moving-link']);
  assert.equal(request.params.get('sourceSpaceId'), 'space-source');
  assert.deepEqual(request.params.getAll('sourceItems[]'), []);
});

test('dropping a complete section on an empty card makes its tab visible without a refresh', async (t) => {
  const board = await setupBoard([
    cardMarkup('space-source', [{ id: 'moving-section', title: 'Moving section', links: [{ id: 'moving-link', title: 'Move with section' }] }]),
    cardMarkup('space-target', []),
  ]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });

  const sourceSections = sectionController(board.app, 'space-source');
  const targetSections = sectionController(board.app, 'space-target');
  const group = sourceSections.groups[0];
  const dataTransfer = { effectAllowed: '', setData() {} };
  const start = new board.window.Event('dragstart', { bubbles: true, cancelable: true });
  Object.defineProperty(start, 'dataTransfer', { value: dataTransfer });
  group.tab.closest('.section-tab-item').querySelector('.section-tab-grip').dispatchEvent(start);

  const targetCard = document.querySelector('[data-space-order-id="space-target"]');
  const over = dispatchDrop(targetCard, 'dragover');
  assert.equal(over.defaultPrevented, true);
  dispatchDrop(targetCard, 'drop');
  const request = await waitForRequest(board.requests);

  assert.equal(sourceSections.groups.length, 0);
  assert.deepEqual(targetSections.groups.map((item) => item.id), ['moving-section']);
  assert.equal(targetSections.groups[0].tab.getAttribute('aria-selected'), 'true');
  assert.ok(document.querySelector('[data-space-order-id="space-target"] .section-tab-item'));
  assert.ok(document.querySelector('[data-space-order-id="space-target"] [data-link-id="moving-link"]'));
  assert.deepEqual(request.params.getAll('items[]'), ['section:moving-section', 'link:moving-link']);
});

test('dropping an unsectioned link into a tab removes it from General and saves it under the section', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [
    { id: 'alpha', title: 'Alpha', links: [] },
  ], [{ id: 'loose', title: 'Loose' }])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });

  const sections = sectionController(board.app, 'space-a');
  const reorder = reorderController(board.app, 'space-a');
  const link = document.querySelector('[data-link-id="loose"]');
  simulateDragStart(reorder, link);
  const target = sections.groups[0].tab.closest('.section-tab-item');

  assert.equal(dispatchDrop(target, 'dragover').defaultPrevented, true);
  dispatchDrop(target, 'drop');

  const request = await waitForRequest(board.requests);
  assert.equal(sections.generalGroup.links.includes(link), false);
  assert.equal(sections.groups[0].links.includes(link), true);
  assert.deepEqual(request.params.getAll('items[]'), ['section:alpha', 'link:loose']);
});

test('an external URL dropped on a section tab carries that section into the preview', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [
    { id: 'alpha', title: 'Alpha', links: [] },
    { id: 'beta', title: 'Beta', links: [] },
  ])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });
  const sections = sectionController(board.app, 'space-a');
  const reorder = reorderController(board.app, 'space-a');
  let preview;
  reorder.previewDroppedLink = (link) => { preview = link; };
  const target = sections.groups[1].tab.closest('.section-tab-item');
  const dataTransfer = { types: ['text/uri-list'], getData: () => 'https://example.com/page', dropEffect: '' };
  reorder.dropOnContainer({ target, dataTransfer, preventDefault() {} });
  assert.equal(preview.sectionId, 'beta');
  assert.equal(preview.url, 'https://example.com/page');
});

test('external URL in General is checked, submitted to General, and refreshes the board', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [
    { id: 'work', title: 'Work', links: [] },
  ], [{ id: 'general-link', title: 'General link' }])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });
  const requests = [];
  let renderedStream = '';
  board.window.NeoxBoard.Turbo = { renderStreamMessage: (stream) => { renderedStream = stream; } };
  global.fetch = async (url, options) => {
    requests.push({ url, params: new URLSearchParams(options.body) });
    if (url.endsWith('/check')) return { ok: true, json: async () => ({ valid: true, duplicate: false }) };
    return { ok: true, text: async () => '<turbo-stream action="refresh"></turbo-stream>' };
  };
  const reorder = reorderController(board.app, 'space-a');
  const generalTab = sectionController(board.app, 'space-a').generalGroup.tab.closest('.section-tab-item');
  const event = new board.window.Event('drop', { bubbles: true, cancelable: true });
  Object.defineProperty(event, 'dataTransfer', { value: {
    types: ['text/uri-list'], getData: () => 'http://192.168.2.17:3000/stacks',
  } });
  generalTab.dispatchEvent(event);
  for (let i = 0; i < 10 && reorder.confirmButtonTarget.disabled; i += 1) await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(event.defaultPrevented, true);
  assert.equal(reorder.previewSectionTarget.textContent, 'Général');
  assert.equal(reorder.previewMessageTarget.hidden, true);
  assert.equal(reorder.confirmButtonTarget.disabled, false);
  assert.equal(requests[0].params.get('section_id'), '');
  await reorder.confirmDroppedLink();
  assert.equal(requests[1].params.get('section_id'), '');
  assert.equal(requests[1].params.get('url'), 'http://192.168.2.17:3000/stacks');
  assert.equal(renderedStream, '<turbo-stream action="refresh"></turbo-stream>');
  assert.equal(reorder.dropPreviewTarget.hidden, true);
});

test('external link drop renews an expired CSRF token for validation and submission without reloading', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });
  const reorder = reorderController(board.app, 'space-a');
  const requests = [];
  let tokenVersion = 0;
  let renderedStream = '';
  board.window.NeoxBoard.Turbo = { renderStreamMessage: (stream) => { renderedStream = stream; } };
  global.fetch = async (url, options = {}) => {
    const body = new URLSearchParams(options.body || '');
    requests.push({ url, body });
    if (url.endsWith('/drop-token')) {
      tokenVersion += 1;
      return {
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: async () => ({ token: `fresh-token-${tokenVersion}` }),
      };
    }
    if (url.endsWith('/check')) {
      if (body.get('_token') === 'token-space-a') return { ok: false, status: 403 };
      return { ok: true, status: 200, json: async () => ({ valid: true, duplicate: false }) };
    }
    if (url.endsWith('/drop')) {
      if (body.get('_token') === 'fresh-token-1') return { ok: false, status: 403 };
      return { ok: true, status: 200, text: async () => '<turbo-stream action="refresh"></turbo-stream>' };
    }
    throw new Error(`Unexpected URL: ${url}`);
  };

  await reorder.previewDroppedLink({ title: 'Link', url: 'https://example.test', sectionId: '' });
  assert.equal(reorder.confirmButtonTarget.disabled, false);
  assert.equal(reorder.createTokenValue, 'fresh-token-1');
  await reorder.confirmDroppedLink();

  assert.equal(tokenVersion, 2);
  assert.deepEqual(requests.map(({ url, body }) => [url.split('/').at(-1), body.get('_token')]), [
    ['check', 'token-space-a'],
    ['drop-token', null],
    ['check', 'fresh-token-1'],
    ['drop', 'fresh-token-1'],
    ['drop-token', null],
    ['drop', 'fresh-token-2'],
  ]);
  assert.equal(renderedStream, '<turbo-stream action="refresh"></turbo-stream>');
  assert.equal(reorder.dropPreviewTarget.hidden, true);
});

test('a late validation response cannot enable a newer invalid preview', async (t) => {
  const board = await setupBoard([cardMarkup('space-a', [])]);
  t.after(() => { board.app.stop(); board.dom.window.close(); });
  const reorder = reorderController(board.app, 'space-a');
  const pending = [];
  global.fetch = () => new Promise((resolve) => { pending.push(resolve); });
  const first = reorder.previewDroppedLink({ title: 'First', url: 'https://first.example', sectionId: '' });
  const second = reorder.previewDroppedLink({ title: 'Second', url: 'https://second.example', sectionId: '' });
  pending[1]({ ok: true, status: 200, json: async () => ({ valid: false, duplicate: true, message: 'Doublon' }) });
  await second;
  pending[0]({ ok: true, status: 200, json: async () => ({ valid: true, duplicate: false }) });
  await first;
  assert.equal(reorder.previewTitleTarget.textContent, 'Second');
  assert.equal(reorder.previewMessageTarget.textContent, 'Doublon');
  assert.equal(reorder.confirmButtonTarget.disabled, true);
});
