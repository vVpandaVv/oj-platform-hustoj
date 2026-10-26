const DEFAULT_WIDTH = 280;
const MIN_WIDTH = 220;
const MAX_WIDTH = 560;
const WIDTH_KEY = 'readcontents-reader-outline-width';
const HIDDEN_KEY = 'readcontents-reader-outline-hidden';
const initialized = new WeakSet();
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const readPreference = key => {
  try { return window.localStorage.getItem(key); } catch { return null; }
};
const savePreference = (key, value) => {
  try { window.localStorage.setItem(key, String(value)); } catch { /* Storage is optional. */ }
};

function initializeReader(root) {
  if (initialized.has(root)) return;
  const layout = root.querySelector('[data-rcis-reader-layout]');
  const outline = root.querySelector('[data-rcis-reader-outline]');
  const handle = root.querySelector('[data-rcis-reader-resize]');
  const toggle = root.querySelector('[data-rcis-outline-toggle]');
  if (!layout || !outline || !handle || !toggle) return;
  initialized.add(root);

  const desktop = window.matchMedia('(min-width: 1024px)');
  const storedWidth = Number(readPreference(WIDTH_KEY));
  let preferredWidth = Number.isFinite(storedWidth) && storedWidth >= MIN_WIDTH && storedWidth <= MAX_WIDTH
    ? storedWidth : DEFAULT_WIDTH;
  let currentWidth = DEFAULT_WIDTH;
  let desktopHidden = readPreference(HIDDEN_KEY) === 'true';
  let mobileOpen = false;
  let drag = null;
  let savedStyles = [];
  const isOpen = () => desktop.matches ? !desktopHidden : mobileOpen;
  const isResizable = () => desktop.matches && isOpen() && handle.getClientRects().length > 0 && layout.clientWidth > 0;
  const bounds = () => {
    const max = Math.min(MAX_WIDTH, Math.max(0, Math.floor(layout.clientWidth - 480 - 12)));
    return { min: Math.min(MIN_WIDTH, max), max };
  };
  const stopDrag = () => {
    const pointerId = drag?.pointerId;
    drag = null;
    handle.removeAttribute('data-resizing');
    for (const { style, property, value, priority } of savedStyles) {
      if (value) style.setProperty(property, value, priority);
      else style.removeProperty(property);
    }
    savedStyles = [];
    if (pointerId !== undefined && handle.hasPointerCapture(pointerId)) {
      try { handle.releasePointerCapture(pointerId); } catch { /* Pointer already ended. */ }
    }
  };
  const measure = () => {
    if (!isResizable()) { stopDrag(); return; }
    const { min, max } = bounds();
    currentWidth = Math.round(clamp(preferredWidth, min, max));
    layout.style.setProperty('--rcis-reader-outline-width', `${currentWidth}px`);
    handle.setAttribute('aria-valuemin', String(min));
    handle.setAttribute('aria-valuemax', String(max));
    handle.setAttribute('aria-valuenow', String(currentWidth));
    handle.setAttribute('aria-valuetext', `${currentWidth}픽셀`);
  };
  const changeWidth = requested => {
    preferredWidth = clamp(requested, MIN_WIDTH, MAX_WIDTH);
    measure();
    savePreference(WIDTH_KEY, preferredWidth);
  };
  const renderVisibility = () => {
    const open = isOpen();
    const focused = document.activeElement;
    if ((!open && outline.contains(focused)) || ((!desktop.matches || !open) && focused === handle)) {
      toggle.focus({ preventScroll: true });
    }
    root.dataset.outlineOpen = String(open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.textContent = open ? '목차 숨기기' : '목차 보기';
    outline.hidden = !open;
    handle.hidden = !desktop.matches || !open;
    measure();
    if (open) requestAnimationFrame(() => {
      const current = outline.querySelector('[aria-current="page"]');
      if (!isOpen() || !current?.getClientRects().length) return;
      const item = current.getBoundingClientRect();
      const panel = outline.getBoundingClientRect();
      if (item.top < panel.top || item.bottom > panel.bottom) {
        outline.scrollTop += item.top - panel.top - (outline.clientHeight - item.height) / 2;
      }
    });
  };

  toggle.addEventListener('click', () => {
    stopDrag();
    if (desktop.matches) {
      desktopHidden = !desktopHidden;
      savePreference(HIDDEN_KEY, desktopHidden);
    } else {
      mobileOpen = !mobileOpen;
    }
    renderVisibility();
  });
  handle.title = '끌거나 좌우 방향키로 목차 너비 조절 · Shift+방향키로 크게 조절 · 두 번 클릭하면 기본 너비';
  handle.addEventListener('pointerdown', event => {
    if (!isResizable() || !event.isPrimary || event.button !== 0 || drag) return;
    event.preventDefault();
    handle.focus({ preventScroll: true });
    measure();
    try { handle.setPointerCapture(event.pointerId); } catch { return; }
    drag = { pointerId: event.pointerId, startX: event.clientX, startWidth: currentWidth, moved: false };
    handle.setAttribute('data-resizing', 'true');
    for (const element of [document.documentElement, document.body]) {
      for (const [property, value] of [['user-select', 'none'], ['cursor', 'col-resize']]) {
        savedStyles.push({ style: element.style, property,
          value: element.style.getPropertyValue(property), priority: element.style.getPropertyPriority(property) });
        element.style.setProperty(property, value);
      }
    }
  });
  handle.addEventListener('pointermove', event => {
    if (!drag || drag.pointerId !== event.pointerId) return;
    if (!isResizable()) { stopDrag(); return; }
    const delta = event.clientX - drag.startX;
    if (delta === 0 && !drag.moved) return;
    drag.moved = true;
    event.preventDefault();
    changeWidth(drag.startWidth + delta);
  });
  const onPointerEnd = event => {
    if (drag?.pointerId === event.pointerId) stopDrag();
  };
  handle.addEventListener('pointerup', onPointerEnd);
  handle.addEventListener('pointercancel', onPointerEnd);
  handle.addEventListener('lostpointercapture', onPointerEnd);
  handle.addEventListener('keydown', event => {
    if (!isResizable()) return;
    const { min, max } = bounds();
    const step = event.shiftKey ? 40 : 10;
    let next;
    switch (event.key) {
      case 'ArrowLeft': next = currentWidth - step; break;
      case 'ArrowRight': next = currentWidth + step; break;
      case 'Home': next = min; break;
      case 'End': next = max; break;
      default: return;
    }
    event.preventDefault();
    changeWidth(clamp(next, min, max));
  });
  handle.addEventListener('dblclick', event => {
    if (!isResizable()) return;
    event.preventDefault();
    stopDrag();
    changeWidth(DEFAULT_WIDTH);
  });
  desktop.addEventListener('change', () => {
    stopDrag();
    mobileOpen = false;
    renderVisibility();
  });
  window.addEventListener('blur', stopDrag);
  window.addEventListener('pagehide', stopDrag);
  const observer = new ResizeObserver(measure);
  observer.observe(layout);
  observer.observe(handle);
  renderVisibility();
}

function initializeReaders() {
  document.querySelectorAll('[data-rcis-reader]').forEach(initializeReader);
}
initializeReaders();
document.addEventListener('DOMContentLoaded', initializeReaders, { once: true });
