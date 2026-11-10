import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['content', 'toggle'];

  connect() {
    this.spaceId = this.element.dataset.spaceOrderId;
    this.storageKey = `neox-board-space-collapsed:${this.spaceId}`;
    this.setCollapsed(localStorage.getItem(this.storageKey) === 'true');
  }

  toggle() {
    this.setCollapsed(!this.contentTarget.hidden);
  }

  expand() {
    if (this.contentTarget.hidden) this.setCollapsed(false);
  }

  setCollapsed(collapsed) {
    this.contentTarget.hidden = collapsed;
    this.element.classList.toggle('is-collapsed', collapsed);
    this.toggleTargets.forEach((button) => {
      button.setAttribute('aria-expanded', String(!collapsed));
      button.setAttribute('aria-label', collapsed ? 'Déplier cet espace' : 'Replier cet espace');
      button.title = collapsed ? 'Déplier cet espace' : 'Replier cet espace';
    });
    localStorage.setItem(this.storageKey, String(collapsed));
  }
}
