import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  connect() {
    let theme;
    try { theme = localStorage.getItem('neox-board-theme-v2'); } catch (_) {}
    if (theme !== 'light' && theme !== 'dark') theme = 'dark';
    this.apply(theme);
    let color;
    try { color = localStorage.getItem('neox-board-color-v1'); } catch (_) {}
    if (!['violet', 'blue', 'cyan', 'emerald', 'rose', 'amber'].includes(color)) color = 'violet';
    this.applyColor(color);
  }
  toggle() { this.apply(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark', true); }
  chooseColor(event) { this.applyColor(event.currentTarget.dataset.color, true); }
  apply(theme, persist = false) {
    document.documentElement.dataset.theme = theme;
    this.element.querySelectorAll('.theme-toggle').forEach((button) => {
      const icon = button.querySelector('[data-theme-icon]');
      if (icon) icon.textContent = theme === 'dark' ? '☀' : '☾';
      button.setAttribute('aria-pressed', String(theme === 'dark'));
      button.setAttribute('aria-label', theme === 'dark' ? 'Activer le thème clair' : 'Activer le thème sombre');
    });
    if (persist) { try { localStorage.setItem('neox-board-theme-v2', theme); } catch (_) {} }
  }
  applyColor(color, persist = false) {
    if (!['violet', 'blue', 'cyan', 'emerald', 'rose', 'amber'].includes(color)) return;
    document.documentElement.dataset.color = color;
    this.element.querySelectorAll('[data-color]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.color === color));
    });
    if (persist) { try { localStorage.setItem('neox-board-color-v1', color); } catch (_) {} }
  }
}
