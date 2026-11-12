import { Application } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import ModalController from './controllers/modal_controller';
import ReorderController from './controllers/reorder_controller';
import SpaceOrderController from './controllers/space_order_controller';
import ThemeController from './controllers/theme_controller';
import NavigationController from './controllers/navigation_controller';
import SidebarController from './controllers/sidebar_controller';
import SearchController from './controllers/search_controller';
import BrowserLinkController from './controllers/browser_link_controller';
import HeaderController from './controllers/header_controller';
import SpaceCollapseController from './controllers/space_collapse_controller';
import WidgetLayoutController from './controllers/widget_layout_controller';
import LinkCheckController from './controllers/link_check_controller';
import SectionTabsController from './controllers/section_tabs_controller';
import { installTurboRefreshGuard } from './turbo_refresh_guard';
import 'preline';

const app = Application.start();
app.register('modal', ModalController);
app.register('reorder', ReorderController);
app.register('space-order', SpaceOrderController);
app.register('theme', ThemeController);
app.register('navigation', NavigationController);
app.register('sidebar', SidebarController);
app.register('search', SearchController);
app.register('browser-link', BrowserLinkController);
app.register('header', HeaderController);
app.register('space-collapse', SpaceCollapseController);
app.register('widget-layout', WidgetLayoutController);
app.register('link-check', LinkCheckController);
app.register('section-tabs', SectionTabsController);

const movementFocusKey = 'neox-board:movement-focus';
function rememberMovementFocus(location) {
  try { sessionStorage.setItem(movementFocusKey, JSON.stringify(location)); } catch { /* Storage may be unavailable in private contexts. */ }
}

function restoreMovementFocus() {
  let location;
  try {
    const stored = sessionStorage.getItem(movementFocusKey);
    if (!stored) return;
    sessionStorage.removeItem(movementFocusKey);
    location = JSON.parse(stored);
  } catch { return; }

  requestAnimationFrame(() => requestAnimationFrame(() => {
    const card = [...document.querySelectorAll('.space-card[data-space-order-id]')]
      .find((item) => item.dataset.spaceOrderId === location.spaceId);
    if (!card) return;

    const sectionTab = location.sectionId === null
      ? null
      : [...card.querySelectorAll('.section-tab-item')]
        .find((item) => item.dataset.sectionId === location.sectionId)?.querySelector('.section-tab');
    if (sectionTab && sectionTab.getAttribute('aria-selected') !== 'true') sectionTab.click();

    let target = location.itemKey
      ? [...card.querySelectorAll('[data-menu-item]')].find((item) => item.dataset.menuItem === location.itemKey)
      : null;
    if (location.itemKey?.startsWith('section:')) target = sectionTab;
    target ||= card.querySelector('.space-drag-handle, .space-collapse-toggle');
    if (!target) return;
    target.scrollIntoView?.({ block: 'center', behavior: 'auto' });
    target.focus?.({ preventScroll: true });
  }));
}

document.addEventListener('turbo:load', restoreMovementFocus);
installTurboRefreshGuard(document, app);
document.addEventListener('turbo:morph', () => {
  requestAnimationFrame(() => {
    document.querySelectorAll('[data-controller~="section-tabs"]').forEach((element) => {
      app.getControllerForElementAndIdentifier(element, 'section-tabs')?.rebuildAfterMorph();
    });
  });
});
window.NeoxBoard = { Turbo, app, rememberMovementFocus };
document.cookie = 'neox_board_asset=202611122341; Path=/; Max-Age=2592000; SameSite=Lax';
