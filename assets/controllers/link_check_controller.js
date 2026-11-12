import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = { url: String, token: String };
  static targets = ['button', 'icon', 'spinner', 'progress', 'status', 'results'];

  async check(event) {
    event.preventDefault();
    if (this.running) return;

    this.running = true;
    this.buttonTarget.disabled = true;
    this.iconTarget.hidden = true;
    this.spinnerTarget.hidden = false;
    this.progressTarget.hidden = false;
    this.progressTarget.removeAttribute('value');
    this.statusTarget.textContent = 'Préparation de la vérification des liens…';
    this.resultsTarget.replaceChildren();
    let offset = 0;
    let total = null;
    let active = 0;
    const checkedResults = [];

    try {
      while (total === null || offset < total) {
        const body = new URLSearchParams({ _token: this.tokenValue, offset: String(offset) });
        const fetcher = window.NeoxBoard?.Turbo?.fetch || window.fetch.bind(window);
        const response = await fetcher(this.urlValue, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
          credentials: 'same-origin',
          body,
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        if (!Number.isInteger(data.total) || !Number.isInteger(data.processed) || !Array.isArray(data.results)) {
          throw new Error('Réponse de progression invalide');
        }
        total = data.total;
        offset = data.processed;
        active += data.active;
        checkedResults.push(...data.results);
        data.results.forEach((result) => this.updateIndicator(result));
        this.progressTarget.max = Math.max(total, 1);
        this.progressTarget.value = offset;
        this.statusTarget.textContent = `Vérification en cours : ${offset} / ${total} liens · ${active} actif${active > 1 ? 's' : ''}.`;
        this.renderUnavailable(checkedResults);
        if (data.done) break;
      }

      this.statusTarget.textContent = total === 0
        ? 'Aucun lien à vérifier.'
        : `${active} lien${active > 1 ? 's' : ''} actif${active > 1 ? 's' : ''} sur ${total}.`;
    } catch {
      this.statusTarget.textContent = offset > 0
        ? `Vérification interrompue après ${offset} / ${total} liens. Réessaie pour terminer.`
        : 'La vérification a échoué. Réessaie dans un instant.';
    } finally {
      this.buttonTarget.disabled = false;
      this.iconTarget.hidden = false;
      this.spinnerTarget.hidden = true;
      this.running = false;
    }
  }

  updateIndicator(result) {
    const indicator = document.querySelector(`[data-link-availability-arrow="${CSS.escape(result.id)}"]`);
    if (!indicator) return;
    indicator.classList.toggle('is-active', result.active);
    indicator.classList.toggle('is-inactive', !result.active);
    indicator.classList.remove('is-unchecked');
    indicator.title = result.label;
    indicator.setAttribute('aria-label', result.label);
  }

  renderUnavailable(results) {
    const unavailable = results.filter((result) => !result.active);
    this.resultsTarget.replaceChildren();
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
  }
}
