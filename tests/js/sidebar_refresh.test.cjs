const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM, CookieJar } = require('jsdom');

const template = fs.readFileSync(path.join(__dirname, '../../templates/dashboard/index.html.twig'), 'utf8');
const bootstrap = template.match(/<script nonce="\{\{ app\.request\.attributes\.get\('csp_nonce'\) \}\}">([\s\S]*?)<\/script>/)?.[1];
assert.ok(bootstrap, 'the dashboard has an early sidebar state bootstrap');

function loadPage(cookieJar, serverCollapsed, localStorageCollapsed) {
  const bodyClass = serverCollapsed ? 'sidebar-collapsed' : '';
  const html = `<!doctype html><html><head></head><body class="${bodyClass}">
    <script>${bootstrap}</script>
    <script>window.sidebarCollapsedBeforeMarkup = document.body.classList.contains('sidebar-collapsed')</script>
    <aside id="dashboard-sidebar"></aside>
  </body></html>`;
  return new JSDOM(html, {
    url: 'https://board.example.test/',
    cookieJar,
    runScripts: 'dangerously',
    beforeParse(window) {
      if (localStorageCollapsed !== null) window.localStorage.setItem('neox-board-sidebar', localStorageCollapsed);
    },
  });
}

test('a collapsed sidebar is already collapsed before sidebar markup on refresh', (t) => {
  const cookieJar = new CookieJar();
  const firstLoad = loadPage(cookieJar, false, 'collapsed');
  t.after(() => firstLoad.window.close());

  assert.equal(firstLoad.window.sidebarCollapsedBeforeMarkup, true, 'the localStorage migration runs before the sidebar is parsed');
  assert.match(firstLoad.window.document.cookie, /(?:^|; )neox_board_sidebar=collapsed(?:;|$)/);

  const refreshedPage = loadPage(cookieJar, true, 'collapsed');
  t.after(() => refreshedPage.window.close());
  assert.equal(refreshedPage.window.sidebarCollapsedBeforeMarkup, true, 'the server and early bootstrap both keep the sidebar collapsed');
});
