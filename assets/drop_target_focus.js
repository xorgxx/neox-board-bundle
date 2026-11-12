const dropTargetClasses = [
  'drop-before',
  'drop-after',
  'drop-container-target',
  'external-drop-target',
  'section-tab-drop-before',
  'section-tab-drop-after',
  'section-tab-drop-link',
  'space-drop-before',
  'space-drop-after',
  'is-space-column-drop-target',
  'is-widget-drop-target',
];

/** Keep only the current drop target highlighted across the board. */
export function clearDropTargetFocus(document = globalThis.document) {
  if (!document) return;
  const selector = dropTargetClasses.map((className) => `.${className}`).join(',');
  document.querySelectorAll(selector).forEach((element) => {
    dropTargetClasses.forEach((className) => element.classList.remove(className));
  });
}
