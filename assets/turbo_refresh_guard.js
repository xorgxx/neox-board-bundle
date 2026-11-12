/** Hold server-driven morphs until a drag and its board mutation have settled. */
export function installTurboRefreshGuard(document, app) {
  let dragging = false;
  let rendering = false;
  let mutations = 0;
  const pending = [];

  function prepareForMorph() {
    const modal = document.querySelector('.board-modal');
    if (modal?.open) modal.close();
    document.querySelectorAll('[data-controller~="section-tabs"]').forEach((element) => {
      app.getControllerForElementAndIdentifier(element, 'section-tabs')?.prepareForMorph();
    });
  }

  function flush() {
    if (dragging || rendering || mutations > 0 || pending.length === 0) return;
    rendering = true;
    prepareForMorph();
    pending.shift()();
  }

  function beforeRender(event) {
    if ((dragging || mutations > 0 || rendering) && typeof event.detail?.resume === 'function') {
      event.preventDefault();
      pending.push(event.detail.resume);
      return;
    }
    rendering = true;
    prepareForMorph();
  }

  function afterRender() {
    rendering = false;
    flush();
  }

  function startDrag() { dragging = true; }
  function finishDrag() { dragging = false; flush(); }
  function startMutation() { mutations += 1; }
  function finishMutation() { mutations = Math.max(0, mutations - 1); flush(); }

  document.addEventListener('turbo:before-render', beforeRender);
  document.addEventListener('turbo:render', afterRender);
  document.addEventListener('dragstart', startDrag, true);
  document.addEventListener('dragend', finishDrag, true);
  document.addEventListener('drop', finishDrag);
  document.addEventListener('neox-board:touch-drag-start', startDrag);
  document.addEventListener('neox-board:touch-drag-end', finishDrag);
  document.addEventListener('neox-board:mutation-start', startMutation);
  document.addEventListener('neox-board:mutation-end', finishMutation);

  return {
    disconnect() {
      document.removeEventListener('turbo:before-render', beforeRender);
      document.removeEventListener('turbo:render', afterRender);
      document.removeEventListener('dragstart', startDrag, true);
      document.removeEventListener('dragend', finishDrag, true);
      document.removeEventListener('drop', finishDrag);
      document.removeEventListener('neox-board:touch-drag-start', startDrag);
      document.removeEventListener('neox-board:touch-drag-end', finishDrag);
      document.removeEventListener('neox-board:mutation-start', startMutation);
      document.removeEventListener('neox-board:mutation-end', finishMutation);
    },
  };
}
