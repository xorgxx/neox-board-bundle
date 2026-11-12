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
      import FaviconSearchController from '../../assets/controllers/favicon_search_controller.js';
      export { Application, FaviconSearchController };
    `,
    resolveDir: __dirname,
    sourcefile: 'favicon-search-test-entry.js',
  },
  bundle: true,
  platform: 'node',
  format: 'cjs',
  write: false,
}).outputFiles[0].text;
const compiled = new Module(path.join(__dirname, '.favicon-search-test-bundle.cjs'), module);
compiled.filename = path.join(__dirname, '.favicon-search-test-bundle.cjs');
compiled.paths = module.paths;
compiled._compile(bundle, compiled.filename);
const { Application, FaviconSearchController } = compiled.exports;

test('favicon search shows progress and refreshes the board when all batches finish', async (t) => {
  const dom = new JSDOM(`<!doctype html><form class="settings-favicon-search" action="https://board.test/favicons/search" data-controller="favicon-search">
    <input type="hidden" name="_token" value="token">
    <button type="submit" data-favicon-search-target="button"><svg data-favicon-search-target="icon"></svg><span data-favicon-search-target="spinner" hidden></span><span data-favicon-search-target="label">Rechercher les favicons manquants</span></button>
    <progress data-favicon-search-target="progress" max="100" value="0" hidden></progress>
    <p data-favicon-search-target="status" role="status"></p>
  </form>`, { url: 'https://board.test/neox-board/settings' });
  const { window } = dom;
  const previous = {
    window: global.window,
    document: global.document,
    FormData: global.FormData,
    MutationObserver: global.MutationObserver,
    Element: global.Element,
    HTMLElement: global.HTMLElement,
    Node: global.Node,
  };
  global.window = window;
  global.document = window.document;
  global.FormData = window.FormData;
  global.MutationObserver = window.MutationObserver;
  global.Element = window.Element;
  global.HTMLElement = window.HTMLElement;
  global.Node = window.Node;
  const requests = [];
  const renderedStreams = [];
  const results = [
    { total: 3, processed: 2, found: 1, done: false },
    { total: 3, processed: 3, found: 0, done: true },
  ];
  window.NeoxBoard = { Turbo: {
    fetch: async (url, options) => {
      requests.push({ url, options, offset: options.body.get('offset') });
      return { ok: true, json: async () => results.shift() };
    },
    renderStreamMessage: (message) => renderedStreams.push(message),
  } };
  const app = Application.start(window.document.documentElement);
  app.register('favicon-search', FaviconSearchController);
  t.after(() => {
    app.stop();
    window.close();
    global.window = previous.window;
    global.document = previous.document;
    global.FormData = previous.FormData;
    global.MutationObserver = previous.MutationObserver;
    global.Element = previous.Element;
    global.HTMLElement = previous.HTMLElement;
    global.Node = previous.Node;
  });
  await new Promise((resolve) => setTimeout(resolve, 0));

  const form = document.querySelector('form');
  const controller = app.getControllerForElementAndIdentifier(form, 'favicon-search');
  const event = new window.Event('submit', { bubbles: true, cancelable: true });
  const pending = controller.search(event);

  assert.equal(event.defaultPrevented, true);
  assert.equal(controller.buttonTarget.disabled, true);
  assert.equal(controller.spinnerTarget.hidden, false);
  assert.equal(controller.progressTarget.hidden, false);
  assert.equal(controller.progressTarget.hasAttribute('value'), false, 'the progress bar is indeterminate while the first batch is starting');

  await pending;
  await new Promise((resolve) => setTimeout(resolve, 400));

  assert.deepEqual(requests.map((request) => request.offset), ['0', '2']);
  assert.equal(controller.progressTarget.value, 3);
  assert.match(controller.statusTarget.textContent, /3 liens analysés/);
  assert.match(controller.labelTarget.textContent, /Recherche terminée/);
  assert.deepEqual(renderedStreams, ['<turbo-stream action="refresh" method="morph" scroll="preserve"></turbo-stream>']);
});
