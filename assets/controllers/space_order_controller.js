import { Controller } from '@hotwired/stimulus';
import { clearDropTargetFocus } from '../drop_target_focus';
import { enqueueMovementSave, reportMovementSaveError } from '../movement_save_queue';

export default class extends Controller {
  static values = { url: String, token: String };
  static targets = ['status'];

  touchStart(event) {
    if (event.pointerType !== 'touch' || this.touchDrag) return;
    const card = event.currentTarget.closest('.space-card');
    if (!card) return;

    const handle = event.currentTarget;
    this.touchDrag = {
      pointerId: event.pointerId,
      handle,
      card,
      wasDraggable: handle.draggable,
      startX: event.clientX,
      startY: event.clientY,
      x: event.clientX,
      y: event.clientY,
      active: false,
      timer: window.setTimeout(() => this.activateTouchDrag(), 300),
      frame: null,
    };
    handle.draggable = false;
    try { handle.setPointerCapture(event.pointerId); } catch { /* The pointer may already have ended. */ }
  }

  touchMove(event) {
    const drag = this.touchDrag;
    if (!drag || event.pointerId !== drag.pointerId) return;
    drag.x = event.clientX;
    drag.y = event.clientY;

    if (!drag.active) {
      if (Math.hypot(drag.x - drag.startX, drag.y - drag.startY) > 10) {
        drag.cancelled = true;
        window.clearTimeout(drag.timer);
      }
      return;
    }

    event.preventDefault();
    this.updateTouchDropTarget(drag);
    this.scheduleTouchAutoScroll(drag);
  }

  touchEnd(event) {
    const drag = this.touchDrag;
    if (!drag || event.pointerId !== drag.pointerId) return;
    window.clearTimeout(drag.timer);

    if (drag.active) {
      event.preventDefault();
      this.dropTouchCard(drag);
    }
    this.finishTouchDrag();
  }

  touchCancel(event) {
    if (this.touchDrag && event.pointerId === this.touchDrag.pointerId) this.finishTouchDrag();
  }

  touchContextMenu(event) {
    if (this.touchDrag) event.preventDefault();
  }

  activateTouchDrag() {
    const drag = this.touchDrag;
    if (!drag) return;
    drag.active = true;
    document.dispatchEvent(new Event('neox-board:touch-drag-start'));
    this.draggedCard = drag.card;
    drag.card.classList.add('is-space-dragging');
    this.element.querySelectorAll('.space-column > .space-card').forEach((target) => {
      if (target !== drag.card) target.classList.add('space-drop-allowed');
    });
    this.element.querySelectorAll('.space-column').forEach((column) => {
      if (!column.querySelector(':scope > .space-card')) column.classList.add('is-space-column-drop-allowed');
    });
    drag.card.classList.add('is-touch-space-dragging');
  }

  updateTouchDropTarget(drag) {
    const hovered = document.elementFromPoint(drag.x, drag.y);
    const target = hovered?.closest('.space-card');
    clearDropTargetFocus(document);
    if (target && target !== drag.card) {
      const bounds = target.getBoundingClientRect();
      target.classList.add(drag.y > bounds.top + bounds.height / 2 ? 'space-drop-after' : 'space-drop-before');
      return;
    }

    const column = hovered?.closest('.space-column');
    if (column && !column.querySelector(':scope > .space-card')) column.classList.add('is-space-column-drop-target');
  }

  dropTouchCard(drag) {
    const hovered = document.elementFromPoint(drag.x, drag.y);
    const target = hovered?.closest('.space-card');
    if (target && target !== drag.card) {
      const bounds = target.getBoundingClientRect();
      if (drag.y > bounds.top + bounds.height / 2) target.after(drag.card);
      else target.before(drag.card);
      this.saveOrder(drag.card);
      return;
    }

    const column = hovered?.closest('.space-column');
    if (column && !column.querySelector(':scope > .space-card')) {
      column.append(drag.card);
      this.saveOrder(drag.card);
    }
  }

  scheduleTouchAutoScroll(drag) {
    if (drag.frame !== null) return;
    const tick = () => {
      if (this.touchDrag !== drag || !drag.active) return;
      const edge = 72;
      const y = drag.y;
      const height = window.innerHeight;
      const amount = y < edge ? -Math.ceil((edge - y) / 5) : y > height - edge ? Math.ceil((y - height + edge) / 5) : 0;
      if (amount) {
        window.scrollBy(0, amount);
        this.updateTouchDropTarget(drag);
      }
      drag.frame = window.requestAnimationFrame(tick);
    };
    drag.frame = window.requestAnimationFrame(tick);
  }

  finishTouchDrag() {
    const drag = this.touchDrag;
    if (!drag) return;
    window.clearTimeout(drag.timer);
    if (drag.frame !== null) window.cancelAnimationFrame(drag.frame);
    drag.handle.draggable = drag.wasDraggable;
    drag.card.classList.remove('is-touch-space-dragging');
    if (drag.active) document.dispatchEvent(new Event('neox-board:touch-drag-end'));
    this.touchDrag = null;
    this.finishDrag();
  }

  startSpace(event) {
    const card = event.currentTarget.closest('.space-card');
    if (!card || !event.dataTransfer) return;
    this.draggedCard = card;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('application/x-neox-space', card.dataset.spaceOrderId);
    card.classList.add('is-space-dragging');
    this.element.querySelectorAll('.space-column > .space-card').forEach((target) => {
      if (target !== card) target.classList.add('space-drop-allowed');
    });
    this.element.querySelectorAll('.space-column').forEach((column) => {
      if (!column.querySelector(':scope > .space-card')) column.classList.add('is-space-column-drop-allowed');
    });
  }

  overSpace(event) {
    if (!this.draggedCard || !Array.from(event.dataTransfer?.types || []).includes('application/x-neox-space')) return;
    const target = event.currentTarget.closest('.space-card');
    if (!target || target === this.draggedCard) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    clearDropTargetFocus(document);
    const to = target.getBoundingClientRect();
    const after = event.clientY > to.top + to.height / 2;
    target.classList.add(after ? 'space-drop-after' : 'space-drop-before');
  }

  dropSpace(event) {
    if (!this.draggedCard || !Array.from(event.dataTransfer?.types || []).includes('application/x-neox-space')) return;
    const target = event.currentTarget.closest('.space-card');
    if (!target || target === this.draggedCard) return;
    event.preventDefault();
    const to = target.getBoundingClientRect();
    const after = event.clientY > to.top + to.height / 2;
    if (after) target.after(this.draggedCard);
    else target.before(this.draggedCard);
    this.saveOrder();
    this.finishDrag();
  }

  overEmptyColumn(event) {
    const column = event.currentTarget;
    if (!this.draggedCard || column.querySelector(':scope > .space-card')) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    clearDropTargetFocus(document);
    column.classList.add('is-space-column-drop-target');
  }

  dropInEmptyColumn(event) {
    const column = event.currentTarget;
    if (!this.draggedCard || column.querySelector(':scope > .space-card')) return;
    event.preventDefault();
    column.append(this.draggedCard);
    this.saveOrder();
    this.finishDrag();
  }

  moveByKeyboard(event) {
    if (!event.altKey || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    event.preventDefault();
    const card = event.currentTarget.closest('.space-card');
    const crossColumn = event.key === 'ArrowLeft' || event.key === 'ArrowRight';
    const currentColumn = card?.parentElement;
    let neighbor;
    if (crossColumn && currentColumn) {
      const columns = [...this.element.querySelectorAll(':scope > .space-columns > .space-column')];
      const targetColumn = columns.find((column) => column !== currentColumn);
      if (targetColumn) {
        const center = card.getBoundingClientRect().top + card.getBoundingClientRect().height / 2;
        neighbor = [...targetColumn.querySelectorAll(':scope > .space-card')]
          .sort((a, b) => Math.abs(a.getBoundingClientRect().top + a.getBoundingClientRect().height / 2 - center) - Math.abs(b.getBoundingClientRect().top + b.getBoundingClientRect().height / 2 - center))[0];
        if (neighbor) {
          const after = center > neighbor.getBoundingClientRect().top + neighbor.getBoundingClientRect().height / 2;
          if (after) neighbor.after(card);
          else neighbor.before(card);
          event.currentTarget.focus();
          this.saveOrder(card);
          return;
        }
        targetColumn.append(card);
        event.currentTarget.focus();
        this.saveOrder(card);
        return;
      }
    }
    const neighborIndex = [...(currentColumn?.children ?? [])].indexOf(card) + (event.key === 'ArrowUp' ? -1 : 1);
    neighbor = currentColumn?.children[neighborIndex];
    if (!card || !neighbor?.classList.contains('space-card')) return;
    if (event.key === 'ArrowUp') neighbor.before(card);
    else neighbor.after(card);
    event.currentTarget.focus();
    this.saveOrder(card);
  }

  endSpace() {
    this.finishDrag();
  }

  finishDrag() {
    this.clearMarkers();
    this.element.querySelectorAll('.is-space-dragging').forEach((card) => card.classList.remove('is-space-dragging'));
    this.draggedCard = null;
  }

  clearMarkers() {
    clearDropTargetFocus(document);
    this.element.querySelectorAll('.space-drop-allowed').forEach((card) => card.classList.remove('space-drop-allowed'));
    this.element.querySelectorAll('.is-space-column-drop-allowed,.is-space-column-drop-target').forEach((column) => column.classList.remove('is-space-column-drop-allowed', 'is-space-column-drop-target'));
  }

  async saveOrder(card = this.draggedCard) {
    const focusHandle = card?.querySelector('.space-drag-handle');
    focusHandle?.focus({ preventScroll: true });
    const body = new URLSearchParams([['_token', this.tokenValue]]);
    this.element.querySelectorAll(':scope > .space-columns > .space-column').forEach((column, index) => {
      column.querySelectorAll(':scope > .space-card').forEach((card) => body.append(`columns[${index}][]`, card.dataset.spaceOrderId));
    });
    try {
      await enqueueMovementSave(async () => {
        const requestFetch = window.NeoxBoard?.Turbo?.fetch || fetch;
        const response = await requestFetch(this.urlValue, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/vnd.turbo-stream.html' },
          credentials: 'same-origin',
          body,
        });
        if (!response.ok) throw new Error(`Could not save the space order (${response.status})`);
        const stream = await response.text();
        if (stream && window.NeoxBoard?.Turbo) {
          if (card?.dataset.spaceOrderId) window.NeoxBoard?.rememberMovementFocus?.({ spaceId: card.dataset.spaceOrderId, itemKey: null, sectionId: null });
          window.NeoxBoard.Turbo.renderStreamMessage(stream);
        }
      });
      if (this.hasStatusTarget) this.statusTarget.textContent = 'Ordre des espaces enregistré.';
    } catch (error) {
      if (this.hasStatusTarget) this.statusTarget.textContent = 'Le déplacement reste affiché, mais sa sauvegarde a échoué.';
      reportMovementSaveError('Le déplacement des espaces reste affiché, mais sa sauvegarde a échoué. Vérifie la connexion puis réessaie.', error);
    }
  }
}
