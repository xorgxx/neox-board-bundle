import { Controller } from '@hotwired/stimulus';
import { clearDropTargetFocus } from '../drop_target_focus';
import { enqueueMovementSave, reportMovementSaveError } from '../movement_save_queue';

export default class extends Controller {
  static values = { url: String, token: String };

  start(event) {
    const card = event.target.closest('.weather-widget-card');
    if (!card || !event.dataTransfer) return;
    this.draggedCard = card;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('application/x-neox-widget', 'weather');
    card.classList.add('is-widget-dragging');
    this.element.querySelectorAll('.space-column').forEach((column) => column.classList.add('widget-drop-allowed'));
  }

  overColumn(event) {
    if (!Array.from(event.dataTransfer?.types || []).includes('application/x-neox-widget')) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    clearDropTargetFocus(document);
    event.currentTarget.classList.add('is-widget-drop-target');
  }

  async dropColumn(event) {
    if (!Array.from(event.dataTransfer?.types || []).includes('application/x-neox-widget')) return;
    event.preventDefault();
    const column = Number(event.currentTarget.dataset.spaceColumn);
    const bounds = event.currentTarget.getBoundingClientRect();
    const position = event.clientY < bounds.top + bounds.height / 2 ? 'top' : 'bottom';
    await this.save({ size: 'column', column, position });
  }

  end() {
    this.clear();
  }

  clear() {
    clearDropTargetFocus(document);
    this.element.classList.remove('is-widget-dragging');
    this.element.querySelectorAll('.is-widget-dragging').forEach((card) => card.classList.remove('is-widget-dragging'));
    this.element.querySelectorAll('.widget-drop-allowed,.is-widget-drop-target').forEach((column) => column.classList.remove('widget-drop-allowed', 'is-widget-drop-target'));
    this.draggedCard = null;
  }

  async save(layout) {
    const body = new URLSearchParams({ _token: this.tokenValue, ...layout });
    try {
      await enqueueMovementSave(async () => {
        const requestFetch = window.NeoxBoard?.Turbo?.fetch || fetch;
        const response = await requestFetch(this.urlValue, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/vnd.turbo-stream.html' },
          credentials: 'same-origin',
          body,
        });
        if (!response.ok) throw new Error(`Could not save the widget position (${response.status})`);
        const stream = await response.text();
        if (stream && window.NeoxBoard?.Turbo) window.NeoxBoard.Turbo.renderStreamMessage(stream);
      });
    } catch (error) {
      reportMovementSaveError('Le déplacement du widget reste affiché, mais sa sauvegarde a échoué. Vérifie la connexion puis réessaie.', error);
    } finally {
      this.clear();
    }
  }
}
