import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = { url: String, token: String };
  static targets = ['button', 'status', 'results'];

  async check(event) {
    event.preventDefault();
    this.buttonTarget.disabled = true;
    this.statusTarget.textContent = 'Vérification des liens en cours…';
    this.resultsTarget.replaceChildren();
    try {
      const response = await fetch(this.urlValue, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        credentials: 'same-origin',
        body: new URLSearchParams({ _token: this.tokenValue }),
      });
      if (!response.ok) throw new Error('La vérification a échoué. Réessaie dans un instant.');
      const data = await response.json();
      const unavailable = data.results.filter((result) => !result.active);
      data.results.forEach((result) => {
        const indicator = document.querySelector(`[data-link-availability-arrow="${CSS.escape(result.id)}"]`);
        if (!indicator) return;
        indicator.classList.toggle('is-active', result.active);
        indicator.classList.toggle('is-inactive', !result.active);
        indicator.classList.remove('is-unchecked');
        indicator.title = result.label;
        indicator.setAttribute('aria-label', result.label);
      });
      this.statusTarget.textContent = data.checked === 0
        ? 'Aucun lien à vérifier.'
        : `${data.active} lien${data.active > 1 ? 's' : ''} actif${data.active > 1 ? 's' : ''} sur ${data.checked}.`;
      if (unavailable.length === 0) return;
      const heading = document.createElement('b');
      heading.textContent = 'Liens à vérifier';
      const list = document.createElement('ul');
      unavailable.forEach((result) => {
        const item = document.createElement('li');
        const title = document.createElement('span');
        title.textContent = result.title;
        const status = document.createElement('small');
        status.textContent = result.label;
        item.append(title, status);
        list.append(item);
      });
      this.resultsTarget.append(heading, list);
    } catch (error) {
      this.statusTarget.textContent = error.message || 'Impossible de vérifier les liens.';
    } finally {
      this.buttonTarget.disabled = false;
    }
  }
}
