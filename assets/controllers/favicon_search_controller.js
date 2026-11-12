import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['button', 'icon', 'spinner', 'label', 'progress', 'status'];

  async search(event) {
    event.preventDefault();
    if (this.running) return;

    this.running = true;
    this.buttonTarget.disabled = true;
    this.iconTarget.hidden = true;
    this.spinnerTarget.hidden = false;
    this.progressTarget.hidden = false;
    this.progressTarget.removeAttribute('value');
    this.labelTarget.textContent = 'Recherche en cours…';
    this.statusTarget.textContent = 'Préparation de la recherche des favicons…';
    let offset = 0;
    let found = 0;
    let total = null;

    try {
      while (total === null || offset < total) {
        const payload = new FormData(this.element);
        payload.set('offset', String(offset));
        const fetcher = window.NeoxBoard?.Turbo?.fetch || window.fetch.bind(window);
        const response = await fetcher(this.element.action, {
          method: 'POST',
          headers: { Accept: 'application/json' },
          body: payload,
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const result = await response.json();
        if (!Number.isInteger(result.total) || !Number.isInteger(result.processed) || !Number.isInteger(result.found)) {
          throw new Error('Réponse de progression invalide');
        }
        total = result.total;
        offset = result.processed;
        found += result.found;
        this.progressTarget.max = Math.max(total, 1);
        this.progressTarget.value = offset;
        this.statusTarget.textContent = `Recherche en cours : ${offset} / ${total} liens analysés · ${found} favicon${found > 1 ? 's' : ''} trouvée${found > 1 ? 's' : ''}.`;
        if (result.done) break;
      }

      this.statusTarget.textContent = total === 0
        ? 'Aucun lien à analyser.'
        : `Recherche terminée : ${total} liens analysés · ${found} favicon${found > 1 ? 's' : ''} trouvée${found > 1 ? 's' : ''}.`;
      this.labelTarget.textContent = 'Recherche terminée';
      window.setTimeout(() => {
        window.NeoxBoard?.Turbo?.renderStreamMessage('<turbo-stream action="refresh" method="morph" scroll="preserve"></turbo-stream>');
      }, 350);
    } catch {
      this.statusTarget.textContent = 'La recherche a échoué. Réessaie dans un instant.';
      this.buttonTarget.disabled = false;
      this.iconTarget.hidden = false;
      this.spinnerTarget.hidden = true;
      this.labelTarget.textContent = 'Rechercher les favicons manquants';
      this.progressTarget.hidden = true;
      this.running = false;
    }
  }
}
