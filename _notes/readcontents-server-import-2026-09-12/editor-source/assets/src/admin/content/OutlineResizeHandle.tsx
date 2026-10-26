import * as React from "react";

const DEFAULT_WIDTH = 280;
const MIN_WIDTH = 220;
const MAX_WIDTH = 560;
const STORAGE_KEY = "readcontents-outline-width";
type WidthRange = { min: number; max: number; now: number };
const clamp = (value: number, min: number, max: number) => Math.min(max, Math.max(min, value));

export function OutlineResizeHandle() {
  const handleRef = React.useRef<HTMLDivElement>(null);
  const [range, setRange] = React.useState<WidthRange>({ min: MIN_WIDTH, max: MAX_WIDTH, now: DEFAULT_WIDTH });

  React.useLayoutEffect(() => {
    const handle = handleRef.current;
    const grid = handle?.parentElement;
    if (!handle || !grid?.hasAttribute("data-contents-workspace-split")) return;

    const desktop = window.matchMedia("(min-width: 1024px)");
    const previousWidth = grid.style.getPropertyValue("--rcis-outline-width");
    const previousWidthPriority = grid.style.getPropertyPriority("--rcis-outline-width");
    let preferredWidth = DEFAULT_WIDTH;
    let currentWidth = DEFAULT_WIDTH;
    let drag: { pointerId: number; startX: number; startWidth: number; moved: boolean } | null = null;
    let savedStyles: { style: CSSStyleDeclaration; property: string; value: string; priority: string }[] = [];
    try {
      const saved = Number(window.localStorage.getItem(STORAGE_KEY));
      if (Number.isFinite(saved) && saved >= MIN_WIDTH && saved <= MAX_WIDTH) preferredWidth = saved;
    } catch { /* The splitter also works when browser storage is unavailable. */ }

    const isAvailable = () => desktop.matches && handle.getClientRects().length > 0 && grid.clientWidth > 0;
    const bounds = () => {
      const max = Math.min(MAX_WIDTH, Math.max(0, Math.floor(grid.clientWidth - 480 - 12)));
      return { min: Math.min(MIN_WIDTH, max), max };
    };
    const applyWidth = () => {
      const { min, max } = bounds();
      currentWidth = Math.round(clamp(preferredWidth, min, max));
      grid.style.setProperty("--rcis-outline-width", `${currentWidth}px`);
      setRange(previous => previous.min === min && previous.max === max && previous.now === currentWidth
        ? previous : { min, max, now: currentWidth });
    };
    const changeWidth = (requested: number) => {
      preferredWidth = clamp(requested, MIN_WIDTH, MAX_WIDTH);
      applyWidth();
      try {
        window.localStorage.setItem(STORAGE_KEY, String(preferredWidth));
      } catch { /* Keep the current session usable without persistence. */ }
    };
    const stopDrag = () => {
      const pointerId = drag?.pointerId;
      drag = null;
      handle.removeAttribute("data-resizing");
      for (const { style, property, value, priority } of savedStyles) {
        if (value) style.setProperty(property, value, priority);
        else style.removeProperty(property);
      }
      savedStyles = [];
      if (pointerId !== undefined && handle.hasPointerCapture(pointerId)) {
        try { handle.releasePointerCapture(pointerId); } catch { /* The pointer may already have ended. */ }
      }
    };
    const measure = () => {
      if (!isAvailable()) {
        stopDrag();
        return;
      }
      applyWidth();
    };
    const onPointerDown = (event: PointerEvent) => {
      if (!isAvailable() || !event.isPrimary || event.button !== 0 || drag) return;
      event.preventDefault();
      handle.focus({ preventScroll: true });
      applyWidth();
      try {
        handle.setPointerCapture(event.pointerId);
      } catch { return; }
      drag = { pointerId: event.pointerId, startX: event.clientX, startWidth: currentWidth, moved: false };
      handle.setAttribute("data-resizing", "true");
      for (const element of [document.documentElement, document.body]) {
        for (const [property, value] of [["user-select", "none"], ["cursor", "col-resize"]]) {
          savedStyles.push({ style: element.style, property,
            value: element.style.getPropertyValue(property), priority: element.style.getPropertyPriority(property) });
          element.style.setProperty(property, value);
        }
      }
    };
    const onPointerMove = (event: PointerEvent) => {
      if (!drag || drag.pointerId !== event.pointerId) return;
      if (!isAvailable()) { stopDrag(); return; }
      const delta = event.clientX - drag.startX;
      if (delta === 0 && !drag.moved) return;
      drag.moved = true;
      event.preventDefault();
      changeWidth(drag.startWidth + delta);
    };
    const onPointerEnd = (event: PointerEvent) => {
      if (drag?.pointerId === event.pointerId) stopDrag();
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (!isAvailable()) return;
      const { min, max } = bounds();
      const step = event.shiftKey ? 40 : 10;
      let next: number;
      switch (event.key) {
        case "ArrowLeft": next = currentWidth - step; break;
        case "ArrowRight": next = currentWidth + step; break;
        case "Home": next = min; break;
        case "End": next = max; break;
        default: return;
      }
      event.preventDefault();
      changeWidth(clamp(next, min, max));
    };
    const onDoubleClick = (event: MouseEvent) => {
      if (!isAvailable()) return;
      event.preventDefault();
      stopDrag();
      changeWidth(DEFAULT_WIDTH);
    };

    handle.addEventListener("pointerdown", onPointerDown);
    handle.addEventListener("pointermove", onPointerMove);
    handle.addEventListener("pointerup", onPointerEnd);
    handle.addEventListener("pointercancel", onPointerEnd);
    handle.addEventListener("lostpointercapture", onPointerEnd);
    handle.addEventListener("keydown", onKeyDown);
    handle.addEventListener("dblclick", onDoubleClick);
    window.addEventListener("blur", stopDrag);
    desktop.addEventListener("change", measure);
    const observer = new ResizeObserver(measure);
    observer.observe(grid);
    observer.observe(handle);
    measure();
    return () => {
      observer.disconnect();
      desktop.removeEventListener("change", measure);
      window.removeEventListener("blur", stopDrag);
      handle.removeEventListener("pointerdown", onPointerDown);
      handle.removeEventListener("pointermove", onPointerMove);
      handle.removeEventListener("pointerup", onPointerEnd);
      handle.removeEventListener("pointercancel", onPointerEnd);
      handle.removeEventListener("lostpointercapture", onPointerEnd);
      handle.removeEventListener("keydown", onKeyDown);
      handle.removeEventListener("dblclick", onDoubleClick);
      stopDrag();
      if (previousWidth) grid.style.setProperty("--rcis-outline-width", previousWidth, previousWidthPriority);
      else grid.style.removeProperty("--rcis-outline-width");
    };
  }, []);

  return (
    <div
      ref={handleRef}
      aria-label="목차 너비 조절"
      aria-orientation="vertical"
      aria-valuemin={range.min}
      aria-valuemax={range.max}
      aria-valuenow={range.now}
      aria-valuetext={`${range.now}픽셀`}
      className="rcis-outline-resize-handle"
      role="separator"
      tabIndex={0}
      title="끌거나 좌우 방향키로 목차 너비 조절 · Shift+방향키로 크게 조절 · 두 번 클릭하면 기본 너비"
    />
  );
}
