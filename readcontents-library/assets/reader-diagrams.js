const selector = '[data-contents-editor-visualization][data-visualization-type="flowchart"]';

function prepareDiagram(section) {
  const root = section.querySelector('[data-contents-editor-visualization-root]');
  if (!root) return;
  let queued = false;
  const layout = () => {
    queued = false;
    const svg = root.querySelector('[data-visualization-canvas]');
    // Animated diagrams retain their authored viewport across steps.
    if (!svg || root.querySelector('.contents-editor-public-visualization__step-controls')) return;
    const bounds = svg.getBBox();
    if (!bounds.width || !bounds.height) return;
    const sizes = [...svg.querySelectorAll('text')]
      .map((text) => Number.parseFloat(getComputedStyle(text).fontSize))
      .filter((size) => Number.isFinite(size) && size > 0);
    const scale = 14.5 / Math.min(13, ...sizes);
    const padding = 24;
    const width = bounds.width + padding * 2;
    const height = bounds.height + padding * 2;
    svg.setAttribute('viewBox', `${bounds.x - padding} ${bounds.y - padding} ${width} ${height}`);
    svg.style.aspectRatio = `${width} / ${height}`;
    svg.style.width = `${Math.ceil(width * scale)}px`;
    svg.style.inlineSize = svg.style.width;
    svg.classList.add('rcis-readable-diagram');
    root.classList.add('rcis-diagram-scroll');
    root.setAttribute('tabindex', '0');
    root.setAttribute('role', 'region');
  };
  const schedule = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(layout);
  };
  // The public React renderer mounts asynchronously after this module loads.
  new MutationObserver(schedule).observe(root, { childList: true, subtree: true, characterData: true });
  schedule();
  document.fonts.ready.then(schedule);
}

document.querySelectorAll(selector).forEach(prepareDiagram);
