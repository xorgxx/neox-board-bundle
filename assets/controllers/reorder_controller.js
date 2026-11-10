import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = { url: String, token: String, space: String, checkUrl: String, createUrl: String, createToken: String };
  static targets = ['dropPreview', 'previewTitle', 'previewUrl', 'previewMessage', 'confirmButton'];

  connect() {
    this.dragged = null;
    this.dragSourceSpaceId = null;
    this.onGlobalDragStart = this.receiveDragStart.bind(this);
    this.onGlobalDragEnd = this.receiveDragEnd.bind(this);
    document.addEventListener('neox-board:drag-start', this.onGlobalDragStart);
    document.addEventListener('neox-board:drag-end', this.onGlobalDragEnd);
  }

  disconnect() {
    document.removeEventListener('neox-board:drag-start', this.onGlobalDragStart);
    document.removeEventListener('neox-board:drag-end', this.onGlobalDragEnd);
  }

  start(event) {
    this.dragged = event.currentTarget;
    this.dragSourceSpaceId = this.spaceValue;
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', this.dragged.dataset.menuItem);
      this.createDragPreview(event);
    }
    document.dispatchEvent(new CustomEvent('neox-board:drag-start', {
      detail: { element: this.dragged, sourceSpaceId: this.spaceValue },
    }));
    requestAnimationFrame(() => this.dragged?.classList.add('is-dragging'));
  }

  receiveDragStart(event) {
    this.dragged = event.detail.element;
    this.dragSourceSpaceId = event.detail.sourceSpaceId;
  }

  receiveDragEnd() {
    this.clearDragState();
  }

  over(event) {
    const target = event.currentTarget;
    if (!this.dragged) {
      if (this.isExternalUrlDrag(event.dataTransfer)) {
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
        this.element.classList.add('external-drop-target');
      }
      return;
    }
    event.preventDefault();
    if (target === this.dragged) return;
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    this.clearDropMarkers();
    const rect = target.getBoundingClientRect();
    const after = this.dragged.classList.contains('link-row') && target.classList.contains('section-divider')
      ? true
      : event.clientY > rect.top + rect.height / 2;
    target.classList.add(after ? 'drop-after' : 'drop-before');
  }

  overContainer(event) {
    if (!this.dragged && this.isExternalUrlDrag(event.dataTransfer)) {
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
      this.element.classList.add('external-drop-target');
      return;
    }
    if (event.target.closest('[data-menu-item]') || !this.dragged) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    this.element.classList.add('drop-container-target');
  }

  drop(event) {
    event.preventDefault();
    const target = event.currentTarget;
    if (!this.dragged) return;
    if (target === this.dragged) {
      event.stopPropagation();
      return this.finishDrag();
    }

    const afterSection = this.dragged.classList.contains('link-row') && target.classList.contains('section-divider');
    const after = afterSection || event.clientY > target.getBoundingClientRect().top + target.getBoundingClientRect().height / 2;
    const sourceSpaceId = this.dragSourceSpaceId;
    const crossSpace = sourceSpaceId !== this.spaceValue;
    const movingItems = crossSpace ? this.getTransferGroup(this.dragged) : [this.dragged];
    const targetList = this.element.querySelector('.link-list');
    const reference = after ? target.nextElementSibling : target;
    movingItems.forEach((item) => targetList.insertBefore(item, reference));
    event.stopPropagation();
    this.persist(sourceSpaceId);
    this.finishDrag();
  }

  dropOnContainer(event) {
    if (!this.dragged) {
      const droppedUrl = this.parseExternalUrl(event.dataTransfer);
      if (!droppedUrl) return;
      event.preventDefault();
      this.element.classList.remove('external-drop-target');
      this.previewDroppedLink(droppedUrl);
      return;
    }
    if (event.target.closest('[data-menu-item]')) return;
    event.preventDefault();
    const sourceSpaceId = this.dragSourceSpaceId;
    const crossSpace = sourceSpaceId !== this.spaceValue;
    const movingItems = crossSpace ? this.getTransferGroup(this.dragged) : [this.dragged];
    const targetList = this.element.querySelector('.link-list');
    movingItems.forEach((item) => targetList.appendChild(item));
    this.persist(sourceSpaceId);
    this.finishDrag();
  }

  getTransferGroup(item) {
    const group = [item];
    if (!item.classList.contains('section-divider')) return group;
    let next = item.nextElementSibling;
    while (next?.matches('[data-menu-item]') && !next.classList.contains('section-divider')) {
      group.push(next);
      next = next.nextElementSibling;
    }
    return group;
  }

  end() {
    this.finishDrag();
  }

  finishDrag() {
    document.dispatchEvent(new Event('neox-board:drag-end'));
  }

  clearDragState() {
    this.element.querySelectorAll('.is-dragging,.drop-before,.drop-after').forEach((item) => {
      item.classList.remove('is-dragging', 'drop-before', 'drop-after');
    });
    this.element.classList.remove('drop-container-target', 'external-drop-target');
    this.dragPreview?.remove();
    this.dragPreview = null;
    this.dragged = null;
    this.dragSourceSpaceId = null;
  }

  createDragPreview(event) {
    const isSection = this.dragged.classList.contains('section-divider');
    const title = this.dragged.querySelector(isSection ? '.section-divider-label span' : '.resource-title')?.textContent?.trim() || '';
    const preview = document.createElement('div');
    preview.className = `drag-preview${document.documentElement.dataset.theme === 'dark' ? ' is-dark' : ''}`;
    preview.setAttribute('aria-hidden', 'true');
    preview.innerHTML = `<span class="drag-preview-icon">${isSection ? '☷' : '↗'}</span><span class="drag-preview-copy"><small>${isSection ? 'Section' : 'Lien'}</small><b></b></span>`;
    preview.querySelector('b').textContent = title;
    document.body.appendChild(preview);
    this.dragPreview = preview;
    if (event.dataTransfer.setDragImage) event.dataTransfer.setDragImage(preview, 24, 22);
  }

  isExternalUrlDrag(dataTransfer) {
    if (!dataTransfer || this.dragged) return false;
    const types = Array.from(dataTransfer.types || []).map((type) => type.toLowerCase());
    return types.some((type) => ['text/uri-list', 'text/x-moz-url', 'text/plain', 'text/html'].includes(type));
  }

  parseExternalUrl(dataTransfer) {
    if (!this.isExternalUrlDrag(dataTransfer)) return null;
    const get = (type) => {
      try { return dataTransfer.getData(type).trim(); } catch { return ''; }
    };
    const moz = get('text/x-moz-url').split(/\r?\n/);
    const uriList = get('text/uri-list').split(/\r?\n/).find((line) => line && !line.startsWith('#')) || '';
    const plain = get('text/plain');
    const html = get('text/html');
    const anchor = html ? new DOMParser().parseFromString(html, 'text/html').querySelector('a[href]') : null;
    const raw = uriList || moz[0] || anchor?.href || (plain.match(/https?:\/\/[^\s<>"']+/i) || [])[0] || '';
    const candidate = raw.replace(/[.,;!?)}\]]+$/, '');
    let parsed;
    try { parsed = new URL(candidate); } catch { return null; }
    if (!['http:', 'https:'].includes(parsed.protocol) || !parsed.hostname) return null;

    let title = moz[1] || anchor?.textContent?.trim() || '';
    if (!title && plain && plain !== raw) title = plain.replace(raw, '').replace(/[()]/g, '').trim();
    if (!title || /^https?:\/\//i.test(title)) title = parsed.hostname;
    return { url: parsed.href, title: title.slice(0, 160) };
  }

  async previewDroppedLink(link) {
    this.pendingDroppedLink = link;
    this.dropPreviewTarget.hidden = false;
    this.previewTitleTarget.textContent = link.title;
    this.previewUrlTarget.textContent = link.url;
    this.previewMessageTarget.textContent = 'Vérification de l’URL…';
    this.previewMessageTarget.dataset.state = 'checking';
    this.confirmButtonTarget.disabled = true;
    const body = new URLSearchParams([
      ['_token', this.createTokenValue],
      ['title', link.title],
      ['url', link.url],
    ]);
    try {
      const response = await fetch(this.checkUrlValue, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        credentials: 'same-origin',
        body,
      });
      const result = await response.json();
      this.previewMessageTarget.textContent = result.message || 'Impossible de vérifier ce lien.';
      this.previewMessageTarget.dataset.state = result.duplicate ? 'duplicate' : (result.valid ? 'valid' : 'invalid');
      this.confirmButtonTarget.disabled = !result.valid;
    } catch (error) {
      console.error(error);
      this.previewMessageTarget.textContent = 'Impossible de vérifier cette URL. Réessayez.';
      this.previewMessageTarget.dataset.state = 'invalid';
    }
  }

  cancelPreview() {
    this.dropPreviewTarget.hidden = true;
    this.pendingDroppedLink = null;
  }

  async confirmDroppedLink() {
    if (!this.pendingDroppedLink || this.confirmButtonTarget.disabled) return;
    const link = this.pendingDroppedLink;
    const body = new URLSearchParams([
      ['_token', this.createTokenValue],
      ['title', link.title],
      ['url', link.url],
    ]);
    this.confirmButtonTarget.disabled = true;
    this.previewMessageTarget.textContent = 'Ajout du lien…';
    this.previewMessageTarget.dataset.state = 'checking';
    try {
      const response = await fetch(this.createUrlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          Accept: 'text/vnd.turbo-stream.html, application/json',
        },
        credentials: 'same-origin',
        body,
      });
      if (!response.ok) {
        const result = await response.json();
        this.previewMessageTarget.textContent = result.message || 'Le lien n’a pas pu être ajouté.';
        this.previewMessageTarget.dataset.state = result.duplicate ? 'duplicate' : 'invalid';
        this.confirmButtonTarget.disabled = true;
        return;
      }
      const stream = await response.text();
      this.cancelPreview();
      if (stream && window.NeoxBoard?.Turbo) window.NeoxBoard.Turbo.renderStreamMessage(stream);
    } catch (error) {
      console.error(error);
      this.previewMessageTarget.textContent = 'Impossible d’ajouter cette URL. Réessayez.';
      this.previewMessageTarget.dataset.state = 'invalid';
      this.confirmButtonTarget.disabled = false;
    }
  }

  moveByKeyboard(event) {
    if (!event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
    event.preventDefault();
    const item = event.currentTarget;
    const sibling = event.key === 'ArrowUp' ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling) return;
    if (event.key === 'ArrowUp') sibling.before(item);
    else sibling.after(item);
    item.focus();
    this.persist(this.spaceValue);
  }

  clearDropMarkers() {
    this.element.querySelectorAll('.drop-before,.drop-after').forEach((item) => {
      item.classList.remove('drop-before', 'drop-after');
    });
  }

  async persist(sourceSpaceId) {
    const items = [...this.element.querySelectorAll('[data-menu-item]')].map((item) => item.dataset.menuItem);
    const body = new URLSearchParams([['_token', this.tokenValue], ...items.map((item) => ['items[]', item])]);
    if (sourceSpaceId && sourceSpaceId !== this.spaceValue) {
      const sourceElement = document.querySelector(`[data-reorder-space-value="${CSS.escape(sourceSpaceId)}"]`);
      if (!sourceElement) return window.location.reload();
      const sourceItems = [...sourceElement.querySelectorAll('[data-menu-item]')].map((item) => item.dataset.menuItem);
      body.set('sourceSpaceId', sourceSpaceId);
      sourceItems.forEach((item) => body.append('sourceItems[]', item));
    }
    try {
      const response = await fetch(this.urlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          Accept: 'text/vnd.turbo-stream.html',
        },
        credentials: 'same-origin',
        body,
      });
      if (!response.ok) throw new Error('Could not save the new order');
      const stream = await response.text();
      if (stream && window.NeoxBoard?.Turbo) window.NeoxBoard.Turbo.renderStreamMessage(stream);
    } catch (error) {
      console.error(error);
      window.location.reload();
    }
  }
}
