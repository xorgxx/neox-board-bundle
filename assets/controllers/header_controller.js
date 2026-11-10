import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  connect() {
    this.bar = this.element.querySelector('.topbar');
    this.onScroll = () => {
      if (this.frame) return;
      this.frame = window.requestAnimationFrame(() => {
        this.bar?.classList.toggle('is-compact', window.scrollY > 32);
        this.frame = null;
      });
    };
    window.addEventListener('scroll', this.onScroll, { passive: true });
    this.onScroll();
  }

  disconnect() {
    window.removeEventListener('scroll', this.onScroll);
    if (this.frame) window.cancelAnimationFrame(this.frame);
  }
}
