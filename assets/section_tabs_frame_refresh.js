export function rebuildSectionTabsInFrame(frame, app) {
  const elements = [
    ...(frame.matches?.('[data-controller~="section-tabs"]') ? [frame] : []),
    ...frame.querySelectorAll?.('[data-controller~="section-tabs"]') || [],
  ];
  let attempt = 0;
  const rebuild = () => {
    const unattached = [];
    elements.forEach((element) => {
      if (!element.isConnected) return;
      const controller = app.getControllerForElementAndIdentifier(element, 'section-tabs');
      if (controller) controller.rebuildAfterFrame();
      else unattached.push(element);
    });
    if (unattached.length === 0 || attempt++ >= 4) {
      unattached.forEach((element) => {
        const controllers = (element.getAttribute('data-controller') || '').split(/\s+/).filter(Boolean);
        element.removeAttribute('data-controller');
        requestAnimationFrame(() => {
          if (element.isConnected) element.setAttribute('data-controller', controllers.join(' '));
        });
      });
      return;
    }
    requestAnimationFrame(rebuild);
  };
  requestAnimationFrame(() => requestAnimationFrame(rebuild));
}

export function installSectionTabsFrameRefresh(document, app) {
  const onFrameChange = (event) => rebuildSectionTabsInFrame(event.target, app);
  document.addEventListener('turbo:frame-render', onFrameChange);
  document.addEventListener('turbo:frame-load', onFrameChange);
  return () => {
    document.removeEventListener('turbo:frame-render', onFrameChange);
    document.removeEventListener('turbo:frame-load', onFrameChange);
  };
}
