import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['link'];

  connect() {
    this.onFrameLoad = this.frameLoaded.bind(this);
    document.addEventListener('turbo:frame-load', this.onFrameLoad);
  }

  disconnect() {
    document.removeEventListener('turbo:frame-load', this.onFrameLoad);
  }

  frameLoaded(event) {
    if (event.target.id !== 'neox_board_main') return;
    const { panel, spaceId } = this.currentPanel();
    this.activate(panel, spaceId);
  }

  currentPanel() {
    const path = window.location.pathname.replace(/\/$/, '');
    const spaceMatch = path.match(/\/neox-board\/spaces\/([0-9a-fA-F-]{36})$/);
    if (spaceMatch) return { panel: 'space', spaceId: spaceMatch[1] };
    const panels = { '/neox-board/spaces': 'spaces', '/neox-board/favorites': 'favorites', '/neox-board/activity': 'activity', '/neox-board/settings': 'settings' };
    return { panel: panels[path] || 'overview', spaceId: null };
  }

  activate(panel, spaceId = null) {
    this.linkTargets.forEach((link) => {
      const active = link.dataset.panel === panel
        && (panel !== 'space' || link.dataset.spaceId === spaceId);
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  }
}
