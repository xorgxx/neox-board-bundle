import { Controller } from '@hotwired/stimulus';
import { clearDropTargetFocus } from '../drop_target_focus';

export default class extends Controller {
  static values = { url: String, token: String, space: String, checkUrl: String, createUrl: String, createToken: String, tokenUrl: String };
  static targets = ['dropPreview', 'previewTitle', 'previewUrl', 'previewMessage', 'previewSection', 'previewKind', 'previewItems', 'confirmButton'];

  static maxExternalDropLinks = 100;

  connect() {
    this.dragged = null;
    this.dragSourceSpaceId = null;
    this.previewCheckId = 0;
    this.previewAbort = null;
    this.onGlobalDragStart = this.receiveDragStart.bind(this);
    this.onGlobalDragEnd = this.receiveDragEnd.bind(this);
    this.onPaste = this.handlePaste.bind(this);
    document.addEventListener('neox-board:drag-start', this.onGlobalDragStart);
    document.addEventListener('neox-board:drag-end', this.onGlobalDragEnd);
    document.addEventListener('paste', this.onPaste);
  }

  disconnect() {
    this.previewAbort?.abort();
    document.removeEventListener('neox-board:drag-start', this.onGlobalDragStart);
    document.removeEventListener('neox-board:drag-end', this.onGlobalDragEnd);
    document.removeEventListener('paste', this.onPaste);
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
    this.dragged.classList.add('is-dragging');
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
        clearDropTargetFocus(document);
        this.element.classList.add('external-drop-target');
      }
      return;
    }
    event.preventDefault();
    clearDropTargetFocus(document);
    if (target === this.dragged) return;
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
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
      clearDropTargetFocus(document);
      this.element.classList.add('external-drop-target');
      this.externalDropSectionId = this.sectionAt(event.target);
      return;
    }
    if (event.target.closest('[data-menu-item],.section-tab-item') || !this.dragged) return;
    event.preventDefault();
    clearDropTargetFocus(document);
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
    this.integrateSectionTransfer(this.dragged, sourceSpaceId);
    event.stopPropagation();
    this.persist(sourceSpaceId, this.dragged);
    this.finishDrag();
  }

  dropOnContainer(event) {
    if (!this.dragged) {
      const dropped = this.parseExternalUrls(event.dataTransfer);
      if (dropped.links.length === 0) return;
      event.preventDefault();
      this.element.classList.remove('external-drop-target');
      const sectionId = this.sectionAt(event.target);
      dropped.links.forEach((link) => { link.sectionId = sectionId; });
      const tab = event.target.closest('.section-tab-item')?.querySelector('.section-tab');
      if (tab) tab.click();
      this.previewDroppedLink(dropped.links.length === 1 ? dropped.links[0] : dropped.links, dropped.overflowCount);
      return;
    }
    if (event.target.closest('[data-menu-item]')) return;
    event.preventDefault();
    const sourceSpaceId = this.dragSourceSpaceId;
    const crossSpace = sourceSpaceId !== this.spaceValue;
    const movingItems = crossSpace ? this.getTransferGroup(this.dragged) : [this.dragged];
    const targetList = this.element.querySelector('.link-list');
    movingItems.forEach((item) => targetList.appendChild(item));
    this.integrateSectionTransfer(this.dragged, sourceSpaceId);
    this.persist(sourceSpaceId, this.dragged);
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

  integrateSectionTransfer(item, sourceSpaceId) {
    if (!item?.classList.contains('section-divider') || !sourceSpaceId || sourceSpaceId === this.spaceValue) return;
    const sourceCard = document.querySelector(`[data-reorder-space-value="${CSS.escape(sourceSpaceId)}"]`);
    const sourceContent = sourceCard?.querySelector('.space-card-content[data-controller~="section-tabs"]');
    const targetContent = this.element.querySelector('.space-card-content[data-controller~="section-tabs"]');
    const app = window.NeoxBoard?.app;
    const sourceTabs = sourceContent ? app?.getControllerForElementAndIdentifier(sourceContent, 'section-tabs') : null;
    const targetTabs = targetContent ? app?.getControllerForElementAndIdentifier(targetContent, 'section-tabs') : null;
    const group = sourceTabs?.groups.find((candidate) => candidate.divider === item);
    if (group && targetTabs) targetTabs.acceptSectionGroup(group, sourceTabs);
  }

  sectionAt(target) {
    const tab = target.closest('.section-tab-item');
    if (tab) return tab.dataset.sectionId || '';
    const divider = target.closest('.section-divider');
    if (divider) return divider.dataset.sectionId || '';
    const row = target.closest('.link-row');
    if (row) {
      let previous = row.previousElementSibling;
      while (previous && !previous.classList.contains('section-divider')) previous = previous.previousElementSibling;
      if (previous) return previous.dataset.sectionId || '';
    }
    return this.element.querySelector('.section-tab[aria-selected="true"]')?.closest('.section-tab-item')?.dataset.sectionId || '';
  }

  end() {
    this.finishDrag();
  }

  finishDrag() {
    document.dispatchEvent(new Event('neox-board:drag-end'));
  }

  clearDragState() {
    clearDropTargetFocus(document);
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

  parseExternalUrls(dataTransfer) {
    const empty = { links: [], overflowCount: 0 };
    if (!this.isExternalUrlDrag(dataTransfer)) return empty;
    const get = (type) => {
      try { return dataTransfer.getData(type).trim(); } catch { return ''; }
    };
    const uriList = get('text/uri-list').split(/\r?\n/).map((line) => line.trim()).filter((line) => line && !line.startsWith('#'));
    const mozLines = get('text/x-moz-url').split(/\r?\n/).map((line) => line.trim());
    const html = get('text/html');
    const plain = get('text/plain');
    const sources = [];
    if (uriList.length) sources.push(uriList.map((url) => ({ url })));
    if (mozLines.some((line) => /^https?:\/\//i.test(line))) {
      const candidates = [];
      for (let i = 0; i < mozLines.length; i += 1) {
        if (!/^https?:\/\//i.test(mozLines[i])) continue;
        const title = mozLines[i + 1] && !/^https?:\/\//i.test(mozLines[i + 1]) ? mozLines[++i] : '';
        candidates.push({ url: mozLines[i - (title ? 1 : 0)], title });
      }
      sources.push(candidates);
    }
    if (html) {
      sources.push([...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('a[href]')]
        .map((anchor) => ({ url: anchor.getAttribute('href') || '', title: anchor.textContent.trim() })));
    }
    const plainLinks = [...plain.matchAll(/https?:\/\/[^\s<>"']+/gi)].map(([url]) => ({ url }));
    if (plainLinks.length) sources.push(plainLinks);
    const validCount = (source) => source.reduce((count, item) => {
      try { return ['http:', 'https:'].includes(new URL(item.url).protocol) ? count + 1 : count; } catch { return count; }
    }, 0);
    const candidates = sources.reduce((best, source) => validCount(source) > validCount(best) ? source : best, []);

    const links = [];
    for (const candidate of candidates) {
      const rawUrl = String(candidate.url || '').replace(/[.,;!?)}\]]+$/, '');
      let parsed;
      try { parsed = new URL(rawUrl); } catch { continue; }
      if (!['http:', 'https:'].includes(parsed.protocol) || !parsed.hostname) continue;
      let title = String(candidate.title || '').trim();
      if (!title || /^https?:\/\//i.test(title)) title = parsed.hostname;
      links.push({ url: parsed.href, title: title.slice(0, 160) });
    }
    return { links: links.slice(0, this.constructor.maxExternalDropLinks), overflowCount: Math.max(0, links.length - this.constructor.maxExternalDropLinks) };
  }

  parseExternalUrl(dataTransfer) {
    return this.parseExternalUrls(dataTransfer).links[0] || null;
  }

  handlePaste(event) {
    const target = event.target;
    if (target?.closest?.('input,textarea,select,[contenteditable]:not([contenteditable="false"]),[role="textbox"]')) return;

    const targetCard = target?.closest?.('.space-card');
    let destination = null;
    if (targetCard) {
      if (targetCard !== this.element) return;
      destination = target;
    } else if (this.element.matches(':hover')) {
      destination = this.element.querySelector('.section-tab-item:hover') || this.element;
    }
    if (!destination) return;

    const dropped = this.parseExternalUrls(event.clipboardData);
    if (dropped.links.length === 0) return;
    event.preventDefault();
    const sectionId = this.sectionAt(destination);
    dropped.links.forEach((link) => { link.sectionId = sectionId; });
    this.previewDroppedLink(dropped.links.length === 1 ? dropped.links[0] : dropped.links, dropped.overflowCount);
  }

  droppedLinksBody(links) {
    const body = new URLSearchParams([['_token', this.createTokenValue], ['section_id', links[0]?.sectionId || '']]);
    if (links.length === 1) {
      body.set('title', links[0].title);
      body.set('url', links[0].url);
      return body;
    }
    links.forEach((link, index) => {
      body.append(`links[${index}][title]`, link.title);
      body.append(`links[${index}][url]`, link.url);
    });
    return body;
  }

  async requestWithFreshDropToken(url, options, requestFetch = fetch) {
    const response = await requestFetch(url, options);
    if (response.status !== 403 || !this.hasTokenUrlValue) return response;

    const tokenResponse = await fetch(this.tokenUrlValue, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    if (!tokenResponse.ok || !tokenResponse.headers?.get('content-type')?.includes('application/json')) return response;
    const result = await tokenResponse.json().catch(() => ({}));
    if (typeof result.token !== 'string' || result.token === '') return response;

    this.createTokenValue = result.token;
    const body = new URLSearchParams(options.body || '');
    body.set('_token', result.token);
    return requestFetch(url, { ...options, body });
  }

  renderPreviewItems(items) {
    if (!this.hasPreviewItemsTarget) return;
    this.previewItemsTarget.replaceChildren();
    this.previewItemsTarget.hidden = items.length < 2;
    items.forEach((item) => {
      const row = document.createElement('li');
      row.className = `drop-link-preview-item is-${item.status || 'checking'}`;
      const title = document.createElement('b');
      title.textContent = item.title || item.url;
      const url = document.createElement('code');
      url.textContent = item.url;
      const status = document.createElement('span');
      status.textContent = ({ ready: 'Prêt', duplicate: 'Doublon ignoré', invalid: 'Invalide', checking: 'À vérifier' })[item.status] || 'À vérifier';
      status.title = item.message || '';
      row.append(title, url, status);
      this.previewItemsTarget.append(row);
    });
  }

  async previewDroppedLink(input, overflowCount = 0) {
    const links = Array.isArray(input) ? input : [input];
    if (links.length === 0) return;
    this.previewAbort?.abort();
    const checkId = ++this.previewCheckId;
    const abort = new AbortController();
    this.previewAbort = abort;
    this.pendingDroppedLinks = links;
    this.pendingDroppedLink = links[0];
    this.pendingDropOverflowCount = overflowCount;
    const isBatch = links.length > 1;
    if (this.hasPreviewKindTarget) this.previewKindTarget.textContent = isBatch ? `LOT DE ${links.length} LIENS` : 'LIEN';
    this.dropPreviewTarget.hidden = false;
    this.previewTitleTarget.textContent = isBatch ? `${links.length} liens détectés` : links[0].title;
    this.previewUrlTarget.textContent = isBatch ? '' : links[0].url;
    this.previewUrlTarget.hidden = isBatch;
    this.previewMessageTarget.classList.toggle('is-batch', isBatch);
    this.renderPreviewItems(links.map((link) => ({ ...link, status: 'checking' })));
    if (this.hasPreviewSectionTarget) {
      const tab = [...this.element.querySelectorAll('.section-tab-item')].find((item) => item.dataset.sectionId === links[0].sectionId);
      this.previewSectionTarget.textContent = tab?.querySelector('.section-tab')?.textContent?.trim() || 'Général';
    }
    this.previewMessageTarget.textContent = isBatch ? `Vérification des ${links.length} URL…` : 'Vérification de l’URL…';
    this.previewMessageTarget.hidden = false;
    this.previewMessageTarget.dataset.state = 'checking';
    this.confirmButtonTarget.disabled = true;
    const body = this.droppedLinksBody(links);
    try {
      const response = await this.requestWithFreshDropToken(this.checkUrlValue, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        credentials: 'same-origin',
        body,
        signal: abort.signal,
      });
      if (!response.ok) {
        if (response.status === 403) throw new Error('drop-session-expired');
        const result = await response.json().catch(() => ({}));
        this.previewMessageTarget.textContent = result.message || 'Impossible de vérifier cette URL.';
        this.previewMessageTarget.hidden = false;
        this.previewMessageTarget.dataset.state = result.duplicate ? 'duplicate' : 'invalid';
        return;
      }
      const result = await response.json();
      if (checkId !== this.previewCheckId || this.pendingDroppedLinks !== links) return;
      if (Array.isArray(result.items)) this.renderPreviewItems(result.items);
      const overflowMessage = overflowCount > 0 ? ` · seuls les ${this.constructor.maxExternalDropLinks} premiers liens seront traités` : '';
      this.previewMessageTarget.textContent = result.valid
        ? (isBatch ? `${result.readyCount || links.length} lien${(result.readyCount || links.length) > 1 ? 's' : ''} prêt${(result.readyCount || links.length) > 1 ? 's' : ''} à ajouter${result.duplicateCount ? ` · ${result.duplicateCount} doublon${result.duplicateCount > 1 ? 's' : ''} ignoré${result.duplicateCount > 1 ? 's' : ''}` : ''}${overflowMessage}.` : '')
        : (result.message || 'Impossible de vérifier ce lien.');
      this.previewMessageTarget.hidden = Boolean(result.valid) && !isBatch;
      this.previewMessageTarget.dataset.state = result.duplicate ? 'duplicate' : (result.valid ? 'valid' : 'invalid');
      this.confirmButtonTarget.disabled = !result.valid;
    } catch (error) {
      if (checkId !== this.previewCheckId || abort.signal.aborted) return;
      console.error(error);
      this.previewMessageTarget.textContent = error.message === 'drop-session-expired'
        ? 'La session a expiré. Reconnectez-vous puis réessayez.'
        : (this.pendingDroppedLinks?.length > 1 ? 'Impossible de vérifier les URL du lot. Réessayez.' : 'Impossible de vérifier cette URL. Réessayez.');
      this.previewMessageTarget.hidden = false;
      this.previewMessageTarget.dataset.state = 'invalid';
    }
  }

  cancelPreview() {
    ++this.previewCheckId;
    this.previewAbort?.abort();
    this.previewAbort = null;
    this.dropPreviewTarget.hidden = true;
    this.pendingDroppedLink = null;
    this.pendingDroppedLinks = null;
  }

  async confirmDroppedLink() {
    const links = this.pendingDroppedLinks || (this.pendingDroppedLink ? [this.pendingDroppedLink] : []);
    if (links.length === 0 || this.confirmButtonTarget.disabled) return;
    const body = this.droppedLinksBody(links);
    this.confirmButtonTarget.disabled = true;
    this.previewMessageTarget.textContent = links.length > 1 ? `Ajout des ${links.length} liens…` : 'Ajout du lien…';
    this.previewMessageTarget.hidden = false;
    this.previewMessageTarget.dataset.state = 'checking';
    try {
      const requestFetch = window.NeoxBoard?.Turbo?.fetch || fetch;
      const response = await this.requestWithFreshDropToken(this.createUrlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          Accept: 'text/vnd.turbo-stream.html, application/json',
        },
        credentials: 'same-origin',
        body,
      }, requestFetch);
      if (!response.ok) {
        const result = await response.json().catch(() => ({}));
        if (Array.isArray(result.items)) this.renderPreviewItems(result.items);
        this.previewMessageTarget.textContent = response.status === 403
          ? 'La session a expiré. Reconnectez-vous puis réessayez.'
          : (result.message || 'Le lien n’a pas pu être ajouté.');
        this.previewMessageTarget.hidden = false;
        this.previewMessageTarget.dataset.state = result.duplicate ? 'duplicate' : 'invalid';
        this.confirmButtonTarget.disabled = true;
        return;
      }
      const stream = await response.text();
      this.cancelPreview();
      if (stream && window.NeoxBoard?.Turbo) window.NeoxBoard.Turbo.renderStreamMessage(stream);
    } catch (error) {
      console.error(error);
      this.previewMessageTarget.textContent = links.length > 1 ? 'Impossible d’ajouter ce lot. Réessayez.' : 'Impossible d’ajouter cette URL. Réessayez.';
      this.previewMessageTarget.hidden = false;
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
    this.persist(this.spaceValue, item);
  }

  async persist(sourceSpaceId, focusItem = null) {
    this.focusMovedItem(focusItem);
    const items = [...this.element.querySelectorAll('[data-menu-item]')].map((item) => item.dataset.menuItem);
    const body = new URLSearchParams([['_token', this.tokenValue], ...items.map((item) => ['items[]', item])]);
    if (sourceSpaceId && sourceSpaceId !== this.spaceValue) {
      const sourceElement = document.querySelector(`[data-reorder-space-value="${CSS.escape(sourceSpaceId)}"]`);
      if (!sourceElement) {
        this.rememberFocusForReload(focusItem);
        return window.location.reload();
      }
      const sourceItems = [...sourceElement.querySelectorAll('[data-menu-item]')].map((item) => item.dataset.menuItem);
      body.set('sourceSpaceId', sourceSpaceId);
      sourceItems.forEach((item) => body.append('sourceItems[]', item));
    }
    document.dispatchEvent(new Event('neox-board:mutation-start'));
    try {
      const requestFetch = window.NeoxBoard?.Turbo?.fetch || fetch;
      const response = await requestFetch(this.urlValue, {
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
      if (stream && window.NeoxBoard?.Turbo) {
        this.rememberFocusForReload(focusItem);
        window.NeoxBoard.Turbo.renderStreamMessage(stream);
      }
    } catch (error) {
      console.error(error);
      this.rememberFocusForReload(focusItem);
      window.location.reload();
    } finally {
      document.dispatchEvent(new Event('neox-board:mutation-end'));
    }
  }

  focusMovedItem(item) {
    if (!item) return;
    let target = item;
    if (item.classList.contains('section-divider')) {
      target = [...this.element.querySelectorAll('.section-tab-item')]
        .find((tab) => tab.dataset.sectionId === item.dataset.sectionId)?.querySelector('.section-tab') || item;
    } else if (item.classList.contains('link-row')) {
      const sectionId = this.sectionAt(item);
      const tab = [...this.element.querySelectorAll('.section-tab-item')].find((candidate) => candidate.dataset.sectionId === sectionId)?.querySelector('.section-tab');
      if (tab && tab.getAttribute('aria-selected') !== 'true') tab.click();
    }
    target.scrollIntoView?.({ block: 'nearest', behavior: 'auto' });
    target.focus?.({ preventScroll: true });
  }

  rememberFocusForReload(item) {
    if (!item?.dataset.menuItem) return;
    window.NeoxBoard?.rememberMovementFocus?.({
      spaceId: this.spaceValue,
      itemKey: item.dataset.menuItem,
      sectionId: this.sectionAt(item),
    });
  }
}
