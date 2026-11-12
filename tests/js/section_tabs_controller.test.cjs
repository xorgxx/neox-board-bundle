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
      export { Application, ReorderController, SectionTabsController };
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
const { Application, ReorderController, SectionTabsController } = compiled.exports;

function cardMarkup(spaceId, sections, ungroupedLinks = []) {
  const items = sections.map((section) => `
    <li class="section-divider" data-section-id="${section.id}" data-menu-item="section:${section.id}">
      <div class="section-divider-label"><span>${section.title}</span></div>
      <div class="section-divider-actions"></div>
    </li>
    ${section.links.map((link) => `<li class="link-row" draggable="true" data-link-id="${link.id}" data-menu-item="link:${link.id}"><span class="resource-title">${link.title}</span></li>`).join('')}
  `).join('');
  const ungrouped = ungroupedLinks.map((link) => `<li class="link-row" draggable="true" data-link-id="${link.id}" data-menu-item="link:${link.id}"><span class="resource-title">${link.title}</span></li>`).join('');
  return `
    <article class="space-card" data-space-order-id="${spaceId}" data-controller="reorder"
      data-reorder-space-value="${spaceId}" data-reorder-url-value="/spaces/${spaceId}/reorder"
      data-reorder-token-value="token-${spaceId}">
      <div class="space-card-content" data-controller="section-tabs">
        <ul class="link-list">${ungrouped}${items}</ul>
      </div>
    </article>`;
}

async function setupBoard(cards) {
  const dom = new JSDOM(`<!doctype html><html><body>${cards.join('')}</body></html>`, { url: 'https://board.example.test/' });
  const { window } = dom;
  global.window = window;
  global.document = window.document;
  global.Event = window.Event;
  global.CustomEvent = window.CustomEvent;
  global.HTMLElement = window.HTMLElement;
  global.Node = window.Node;
  global.MutationObserver = window.MutationObserver;
  global.DOMParser = window.DOMParser;
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
  await new Promise((resolve) => setTimeout(resolve, 0));
  return { dom, window, app, requests };
}

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
