import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['toggle', 'mobileToggle'];

  connect() {
    const collapsed = localStorage.getItem('neox-board-sidebar') === 'collapsed';
    this.setCollapsed(collapsed);
    this.onKeydown = (event) => {
      if (event.key === 'Escape') this.closeMobile();
    };
    window.addEventListener('keydown', this.onKeydown);
  }

  disconnect() {
    window.removeEventListener('keydown', this.onKeydown);
  }

  toggle() {
    this.setCollapsed(!this.element.classList.contains('sidebar-collapsed'));
  }

  toggleMobile() {
    this.setMobileOpen(!this.element.classList.contains('sidebar-open'));
  }

  closeMobile() {
    this.setMobileOpen(false);
  }

  setMobileOpen(open) {
    this.element.classList.toggle('sidebar-open', open);
    const backdrop = document.querySelector('.sidebar-backdrop');
    if (backdrop) backdrop.hidden = !open;
    this.mobileToggleTargets.forEach((button) => {
      button.setAttribute('aria-expanded', String(open));
      button.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
      button.title = open ? 'Fermer le menu' : 'Ouvrir le menu';
      if (!open && window.matchMedia('(max-width: 760px)').matches) button.focus();
    });
    if (open) this.element.querySelector('.sidebar nav a')?.focus();
  }

  setCollapsed(collapsed) {
    this.element.classList.toggle('sidebar-collapsed', collapsed);
    this.toggleTargets.forEach((button) => {
      button.setAttribute('aria-expanded', String(!collapsed));
      button.setAttribute('aria-label', collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale');
      button.title = collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale';
      const icon = button.querySelector('[data-sidebar-icon]');
      if (icon) icon.textContent = collapsed ? '»' : '«';
    });
    localStorage.setItem('neox-board-sidebar', collapsed ? 'collapsed' : 'expanded');
  }
}
