import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  connect() { this.element.addEventListener('turbo:frame-load', this.open); }
  disconnect() { this.element.removeEventListener('turbo:frame-load', this.open); }
  open = () => { const dialog = this.element.querySelector('dialog'); if (dialog && !dialog.open) dialog.showModal(); };
  frameLoaded(event) {
    if (event.target.id !== 'neox_board_modal') return;
    if (!event.target.innerHTML.trim()) this.close(); else this.open();
  }
  close() { this.element.querySelector('dialog')?.close(); }
  backdrop(event) { if (event.target === this.element.querySelector('dialog')) this.close(); }
}
