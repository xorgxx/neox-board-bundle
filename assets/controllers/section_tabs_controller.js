import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  connect() {
    const list = this.element.querySelector('.link-list');
    const dividers = [...(list?.querySelectorAll(':scope > .section-divider') || [])];
    if (!list || dividers.length === 0) return;

    const entries = [...list.children];
    const groups = [];
    let current = null;
    let hasUnsectionedLinks = false;
    entries.forEach((entry) => {
      if (entry.classList.contains('section-divider')) {
        current = { id: entry.dataset.sectionId, title: entry.querySelector('.section-divider-label span')?.textContent.trim() || 'Section', divider: entry, links: [] };
        groups.push(current);
        entry.hidden = true;
      } else if (entry.classList.contains('link-row')) {
        if (current) current.links.push(entry);
        else hasUnsectionedLinks = true;
      }
    });

    this.groups = groups;
    this.list = list;
    this.onGlobalDragStart = (event) => {
      this.draggedLink = event.detail.element?.classList.contains('link-row') ? event.detail.element : null;
      this.draggedLinkSourceSpaceId = this.draggedLink ? event.detail.sourceSpaceId : null;
    };
    this.onGlobalDragEnd = () => {
      this.draggedLink = null;
      this.draggedLinkSourceSpaceId = null;
      this.tabs?.querySelectorAll('.section-tab-drop-link').forEach((tab) => tab.classList.remove('section-tab-drop-link'));
    };
    document.addEventListener('neox-board:drag-start', this.onGlobalDragStart);
    document.addEventListener('neox-board:drag-end', this.onGlobalDragEnd);
    this.generalLinks = entries.filter((entry) => entry.classList.contains('link-row') && !groups.some((group) => group.links.includes(entry)));
    const tabs = document.createElement('div');
    tabs.className = 'section-tabs';
    this.tabs = tabs;
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'Sections de cet espace');
    list.before(tabs);

    if (hasUnsectionedLinks) {
      this.generalGroup = { id: '', title: 'Général', links: this.generalLinks };
      this.addTab(tabs, list, this.generalGroup);
    }
    groups.forEach((group) => this.addTab(tabs, list, group));
  }

  disconnect() {
    document.removeEventListener('neox-board:drag-start', this.onGlobalDragStart);
    document.removeEventListener('neox-board:drag-end', this.onGlobalDragEnd);
  }

  addTab(tabs, list, group) {
    const item = document.createElement('div');
    item.className = 'section-tab-item';
    const button = document.createElement('button');
    const tabId = `section-tab-${this.element.closest('.space-card')?.dataset.spaceOrderId}-${group.id || 'general'}`;
    button.type = 'button';
    button.className = 'section-tab';
    button.id = tabId;
    button.setAttribute('role', 'tab');
    button.setAttribute('aria-selected', 'false');
    button.setAttribute('aria-controls', list.id || (list.id = `section-panel-${this.element.closest('.space-card')?.dataset.spaceOrderId}`));
    button.addEventListener('keydown', (event) => this.moveTab(event, tabs));
    if (this.element.closest('[data-reorder-space-value]')) {
      if (group.divider) {
        const grip = document.createElement('span');
        grip.className = 'section-tab-grip';
        grip.draggable = true;
        grip.setAttribute('aria-hidden', 'true');
        grip.title = 'Glisser pour réordonner cette section';
        grip.textContent = '⠿';
        grip.addEventListener('dragstart', (event) => this.startSectionDrag(event, group));
        grip.addEventListener('dragend', () => this.endSectionDrag());
        item.append(grip);
      }
      item.addEventListener('dragover', (event) => this.overSectionTab(event, group));
      item.addEventListener('dragleave', (event) => {
        if (!event.relatedTarget || !item.contains(event.relatedTarget)) item.classList.remove('section-tab-drop-before', 'section-tab-drop-after', 'section-tab-drop-link');
      });
      item.addEventListener('drop', (event) => this.dropSectionOnTab(event, group, tabs, list));
    }
    button.textContent = group.title;
    button.addEventListener('click', () => this.selectTab(button, list, group));
    item.append(button);

    if (group.divider) {
      const actions = group.divider.querySelector('.section-divider-actions');
      if (actions) item.append(actions);
    }
    tabs.append(item);
    group.tab = button;
    group.links.forEach((link) => { link.hidden = true; });
    if (!tabs.dataset.initialized) {
      tabs.dataset.initialized = 'true';
      this.selectTab(button, list, group);
    }
  }


  startSectionDrag(event, group) {
    const card = this.element.closest('[data-reorder-space-value]');
    this.draggedGroup = group;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', `section:${group.id}`);
    group.tab.closest('.section-tab-item').classList.add('is-section-tab-dragging');
    document.dispatchEvent(new CustomEvent('neox-board:drag-start', {
      detail: { element: group.divider, sourceSpaceId: card.dataset.reorderSpaceValue },
    }));
  }

  overSectionTab(event, targetGroup) {
    if (this.findDraggedLink(event)) {
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      event.currentTarget.closest('.section-tab-item').classList.add('section-tab-drop-link');
      return;
    }
    if (!this.draggedGroup || targetGroup === this.draggedGroup) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    this.tabs?.querySelectorAll('.section-tab-drop-before,.section-tab-drop-after').forEach((tab) => tab.classList.remove('section-tab-drop-before', 'section-tab-drop-after'));
    const targetRect = targetGroup.tab.getBoundingClientRect();
    const side = event.clientX < targetRect.left + targetRect.width / 2 ? 'before' : 'after';
    event.currentTarget.closest('.section-tab-item').classList.add(`section-tab-drop-${side}`);
  }

  dropSectionOnTab(event, targetGroup, tabs, list) {
    const draggedLink = this.findDraggedLink(event);
    if (draggedLink) return this.dropLinkOnTab(event, targetGroup, list, draggedLink);
    if (!this.draggedGroup || targetGroup === this.draggedGroup) return;
    event.preventDefault();
    event.stopPropagation();
    const sourceIndex = this.groups.indexOf(this.draggedGroup);
    this.groups.splice(sourceIndex, 1);
    const targetRect = targetGroup.tab.getBoundingClientRect();
    const insertIndex = this.groups.indexOf(targetGroup) + (event.clientX >= targetRect.left + targetRect.width / 2 ? 1 : 0);
    this.groups.splice(insertIndex, 0, this.draggedGroup);
    const tabItems = new Map(this.groups.map((group) => [group, group.tab.closest('.section-tab-item')]));
    this.groups.forEach((group) => tabs.append(tabItems.get(group)));
    const nonMenuItems = [...list.children].filter((item) => !item.matches('[data-menu-item]'));
    list.replaceChildren(...nonMenuItems, ...this.generalLinks, ...this.groups.flatMap((group) => [group.divider, ...group.links]));
    this.element.closest('[data-reorder-space-value]')?.classList.remove('drop-container-target');
    const card = this.element.closest('[data-reorder-space-value]');
    const reorder = window.NeoxBoard?.app?.getControllerForElementAndIdentifier(card, 'reorder');
    reorder?.persist(card.dataset.reorderSpaceValue);
    this.endSectionDrag();
  }


  findDraggedLink(event) {
    if (this.draggedLink?.isConnected) return this.draggedLink;
    const marked = document.querySelector('.link-row.is-dragging');
    if (marked) {
      this.draggedLink = marked;
      this.draggedLinkSourceSpaceId ||= marked.closest('[data-reorder-space-value]')?.dataset.reorderSpaceValue;
      return marked;
    }
    let payload = '';
    try { payload = event.dataTransfer?.getData('text/plain') || ''; } catch { /* Drag data may be protected before drop. */ }
    const id = payload.startsWith('link:') ? payload.slice(5) : '';
    if (!id) return null;
    const link = document.querySelector(`[data-link-id="${CSS.escape(id)}"]`);
    if (!link) return null;
    this.draggedLink = link;
    this.draggedLinkSourceSpaceId ||= link.closest('[data-reorder-space-value]')?.dataset.reorderSpaceValue;
    return link;
  }

  dropLinkOnTab(event, targetGroup, targetList, link) {
    event.preventDefault();
    event.stopPropagation();
    const sourceSpaceId = this.draggedLinkSourceSpaceId || link.closest('[data-reorder-space-value]')?.dataset.reorderSpaceValue;
    if (!link) return;

    const sourceCard = link.closest('.space-card');
    const sourceContent = sourceCard?.querySelector('.space-card-content[data-controller~="section-tabs"]');
    const sourceTabs = sourceContent ? window.NeoxBoard?.app?.getControllerForElementAndIdentifier(sourceContent, 'section-tabs') : null;
    sourceTabs?.detachLink(link);
    if (!sourceTabs) link.remove();
    targetGroup.links.push(link);
    if (targetGroup === this.generalGroup) this.generalLinks = targetGroup.links;
    this.renderMenuOrder(targetList);
    sourceTabs?.renderMenuOrder(sourceTabs.list);
    sourceTabs?.refreshCurrentTab();
    this.selectTab(targetGroup.tab, targetList, targetGroup);

    const card = this.element.closest('[data-reorder-space-value]');
    const reorder = window.NeoxBoard?.app?.getControllerForElementAndIdentifier(card, 'reorder');
    reorder?.persist(sourceSpaceId);
    document.dispatchEvent(new Event('neox-board:drag-end'));
    this.tabs?.querySelectorAll('.section-tab-drop-link').forEach((tab) => tab.classList.remove('section-tab-drop-link'));
  }

  detachLink(link) {
    this.generalLinks = this.generalLinks.filter((item) => item !== link);
    if (this.generalGroup) this.generalGroup.links = this.generalLinks;
    this.groups.forEach((group) => { group.links = group.links.filter((item) => item !== link); });
  }

  renderMenuOrder(list) {
    if (!list) return;
    const nonMenuItems = [...list.children].filter((item) => !item.matches('[data-menu-item]'));
    list.replaceChildren(...nonMenuItems, ...this.generalLinks, ...this.groups.flatMap((group) => [group.divider, ...group.links]));
  }

  refreshCurrentTab() {
    const tab = this.tabs?.querySelector('.section-tab[aria-selected="true"]');
    if (!tab) return;
    const group = [...this.groups, ...(this.generalGroup ? [this.generalGroup] : [])].find((item) => item.tab === tab);
    if (group) this.selectTab(tab, this.list, group);
  }

  endSectionDrag() {
    this.element.querySelectorAll('.is-section-tab-dragging,.section-tab-drop-before,.section-tab-drop-after,.section-tab-drop-link').forEach((item) => item.classList.remove('is-section-tab-dragging', 'section-tab-drop-before', 'section-tab-drop-after', 'section-tab-drop-link'));
    this.tabs?.querySelectorAll('.section-tab-drop-before,.section-tab-drop-after').forEach((item) => item.classList.remove('section-tab-drop-before', 'section-tab-drop-after'));
    if (this.draggedGroup) document.dispatchEvent(new Event('neox-board:drag-end'));
    this.draggedGroup = null;
  }

  moveTab(event, tabs) {
    const tabButtons = [...tabs.querySelectorAll('[role=tab]')];
    const index = tabButtons.indexOf(event.currentTarget);
    let next = index;
    if (event.key === 'ArrowRight') next = (index + 1) % tabButtons.length;
    else if (event.key === 'ArrowLeft') next = (index - 1 + tabButtons.length) % tabButtons.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = tabButtons.length - 1;
    else return;
    event.preventDefault();
    tabButtons[next].focus();
    tabButtons[next].click();
  }

  selectTab(button, list, group) {
    list.querySelectorAll(':scope > .link-row').forEach((row) => { row.hidden = !group.links.includes(row); });
    list.setAttribute('role', 'tabpanel');
    list.setAttribute('tabindex', '0');
    list.setAttribute('aria-labelledby', button.id);
    this.element.querySelectorAll('.section-tab[role="tab"]').forEach((tab) => tab.setAttribute('aria-selected', String(tab === button)));
    let empty = this.element.querySelector('.section-tab-empty');
    if (group.links.length === 0) {
      if (!empty) {
        empty = document.createElement('p');
        empty.className = 'section-tab-empty';
        list.before(empty);
      }
      empty.textContent = 'Aucun lien dans cette section.';
      empty.hidden = false;
    } else if (empty) empty.hidden = true;
  }
}
