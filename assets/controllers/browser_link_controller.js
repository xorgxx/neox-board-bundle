import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['dialog', 'url', 'status', 'notice'];

  open(event) {
    event.preventDefault();
    const link = event.currentTarget;
    this.urlTarget.value = link.dataset.browserUrl || link.getAttribute('href') || '';
    this.statusTarget.textContent = '';
    this.dialogTarget.showModal();
  }

  close() {
    this.dialogTarget.close();
  }

  backdrop(event) {
    if (event.target === this.dialogTarget) this.close();
  }

  async copy() {
    this.urlTarget.focus();
    this.urlTarget.select();
    try {
      await navigator.clipboard.writeText(this.urlTarget.value);
      this.statusTarget.textContent = 'Adresse copiée. Collez-la dans la barre d’adresse du navigateur.';
    } catch {
      this.statusTarget.textContent = 'Sélectionnez l’adresse, copiez-la, puis collez-la dans la barre d’adresse du navigateur.';
    }
  }

  async copyShortcut(event) {
    const url = event.currentTarget.dataset.browserUrl || '';
    this.urlTarget.value = url;
    try {
      await navigator.clipboard.writeText(url);
      this.noticeTarget.textContent = `${url} a été copié. Colle-le dans la barre d’adresse du navigateur.`;
    } catch {
      this.statusTarget.textContent = 'Copie indisponible : sélectionne cette adresse et copie-la dans la barre d’adresse.';
      this.dialogTarget.showModal();
    }
  }
}
