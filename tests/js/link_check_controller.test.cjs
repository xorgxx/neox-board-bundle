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
      import LinkCheckController from '../../assets/controllers/link_check_controller.js';
      export { Application, LinkCheckController };
    `,
    resolveDir: __dirname,
    sourcefile: 'link-check-test-entry.js',
  },
  bundle: true,
  platform: 'node',
  format: 'cjs',
  write: false,
}).outputFiles[0].text;
const compiled = new Module(path.join(__dirname, '.link-check-test-bundle.cjs'), module);
compiled.filename = path.join(__dirname, '.link-check-test-bundle.cjs');
compiled.paths = module.paths;
compiled._compile(bundle, compiled.filename);
const { Application, LinkCheckController } = compiled.exports;

test('link check shows batch progress and updates indicators and unavailable links', async (t) => {
  const dom = new JSDOM(`<!doctype html><form data-controller="link-check" data-link-check-url-value="https://board.test/check" data-link-check-token-value="csrf">
    <button data-link-check-target="button"><svg data-link-check-target="icon"></svg><span data-link-check-target="spinner" hidden></span></button>
    <progress data-link-check-target="progress" max="100" value="0" hidden></progress>
    <p data-link-check-target="status"></p>
  </form><svg data-link-availability-arrow="live"></svg><svg data-link-availability-arrow="dead"></svg><svg data-link-availability-arrow="later"></svg>`, { url: 'https://board.test/neox-board/settings' });
  const { window } = dom;
  const previous = {
    window: global.window,
    document: global.document,
    MutationObserver: global.MutationObserver,
    Element: global.Element,
    HTMLElement: global.HTMLElement,
    Node: global.Node,
    URLSearchParams: global.URLSearchParams,
    CSS: global.CSS,
  };
  Object.assign(global, {
    window,
    document: window.document,
    MutationObserver: window.MutationObserver,
    Element: window.Element,
    HTMLElement: window.HTMLElement,
    Node: window.Node,
    URLSearchParams: window.URLSearchParams,
    CSS: window.CSS || { escape: (value) => value.replace(/[^a-zA-Z0-9_-]/g, '\\$&') },
  });
  if (!window.CSS) window.CSS = global.CSS;
  const requests = [];
  const batches = [
    { total: 5, processed: 2, active: 1, faviconsFound: 1, done: false, results: [
      { id: 'live', title: 'Live', active: true, label: 'Actif' },
      { id: 'dead', title: 'Dead', active: false, label: 'Lien introuvable' },
    ] },
    { total: 5, processed: 4, active: 2, faviconsFound: 0, done: false, results: [
      { id: 'other-live', title: 'Other live', active: true, label: 'Actif' },
      { id: 'another-live', title: 'Another live', active: true, label: 'Actif' },
    ] },
    { total: 5, processed: 5, active: 1, faviconsFound: 1, done: true, results: [
      { id: 'later', title: 'Later', active: true, label: 'Actif' },
    ] },
  ];
  window.NeoxBoard = { Turbo: {
    fetch: async (url, options) => {
      requests.push({ url, offset: options.body.get('offset'), token: options.body.get('_token') });
      return { ok: true, json: async () => batches.shift() };
    },
  } };
  const app = Application.start(window.document.documentElement);
  app.register('link-check', LinkCheckController);
  t.after(() => {
    app.stop();
    window.close();
    Object.assign(global, previous);
  });
  await new Promise((resolve) => setTimeout(resolve, 0));

  const form = document.querySelector('form');
  const controller = app.getControllerForElementAndIdentifier(form, 'link-check');
  const event = new window.Event('submit', { bubbles: true, cancelable: true });
  const pending = controller.check(event);
  assert.equal(controller.buttonTarget.disabled, true);
  assert.equal(controller.spinnerTarget.hidden, false);
  assert.equal(controller.progressTarget.hidden, false);

  await pending;

  assert.deepEqual(requests, [
    { url: 'https://board.test/check', offset: '0', token: 'csrf' },
    { url: 'https://board.test/check', offset: '2', token: 'csrf' },
    { url: 'https://board.test/check', offset: '4', token: 'csrf' },
  ]);
  assert.equal(controller.buttonTarget.disabled, false);
  assert.equal(controller.progressTarget.value, 5);
  assert.match(controller.statusTarget.textContent, /4 \/ 5 liens accessibles/);
  assert.match(controller.statusTarget.textContent, /2 favicons récupérées/);
  assert.equal(document.querySelector('[data-link-availability-arrow="live"]').classList.contains('is-active'), true);
  assert.equal(document.querySelector('[data-link-availability-arrow="dead"]').classList.contains('is-inactive'), true);
  assert.equal(document.querySelector('[data-link-availability-arrow="later"]').classList.contains('is-active'), true);
});
