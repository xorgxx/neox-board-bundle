let saveQueue = Promise.resolve();

/** Run board movement saves in the same order as the user's drops. */
export function enqueueMovementSave(save) {
  document.dispatchEvent(new Event('neox-board:mutation-start'));
  const result = saveQueue.then(save, save);
  saveQueue = result.catch(() => {});

  return result.finally(() => {
    document.dispatchEvent(new Event('neox-board:mutation-end'));
  });
}

export function reportMovementSaveError(message, error) {
  if (error) console.error(error);
  document.dispatchEvent(new CustomEvent('neox-board:movement-save-error', { detail: { message } }));
}
