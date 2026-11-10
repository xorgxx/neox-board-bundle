import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['input'];

  connect() {
    this.onKeydown = this.keydown.bind(this);
    window.addEventListener('keydown', this.onKeydown);
    this.setEngine('neox');
  }

  disconnect() {
    window.removeEventListener('keydown', this.onKeydown);
  }

  keydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      this.inputTarget.focus();
      this.inputTarget.select();
    }
  }

  selectEngine(event) {
    this.setEngine(event.currentTarget.dataset.searchEngine);
  }

  submit(event) {
    if (this.engine !== 'google') return;
    const query = this.inputTarget.value.trim();
    if (!query) return;

    event.preventDefault();
    const url = new URL('https://www.google.com/search');
    url.searchParams.set('q', query);
    window.open(url.toString(), '_blank', 'noopener,noreferrer');
  }

  setEngine(engine) {
    this.engine = engine === 'google' ? 'google' : 'neox';
    this.element.querySelectorAll('[data-search-engine]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.searchEngine === this.engine));
    });
    this.inputTarget.placeholder = this.engine === 'google'
      ? 'Rechercher sur Google…'
      : 'Search link | domain or @d:1, @h:23, @w:2, @m:1';
    this.inputTarget.setAttribute('aria-label', this.engine === 'google' ? 'Rechercher sur Google' : 'Rechercher dans Neox');
  }
}
