import {
  type CodeDrawingType,
  renderCodeDrawing,
} from "@platejs/code-drawing";

type JsonRecord = Record<string, unknown>;

const mountedCodeDrawings = new WeakSet<Element>();
const mountedExcalidraws = new WeakSet<Element>();

function readJsonData(
  container: Element,
  selector: string,
): JsonRecord | null {
  const script = container.querySelector<HTMLScriptElement>(selector);

  if (!script?.textContent) {
    return null;
  }

  try {
    const value = JSON.parse(script.textContent);

    return value && typeof value === "object" && !Array.isArray(value)
      ? (value as JsonRecord)
      : null;
  } catch {
    return null;
  }
}

function replaceWithError(root: HTMLElement, message: string) {
  const notice = document.createElement("p");
  notice.className = "contents-editor-code-drawing__status";
  notice.setAttribute("role", "status");
  notice.textContent = message;
  root.replaceChildren(notice);
}

async function mountCodeDrawing(container: Element) {
  if (mountedCodeDrawings.has(container)) {
    return;
  }

  mountedCodeDrawings.add(container);
  const data = readJsonData(
    container,
    "[data-contents-editor-code-drawing-data]",
  );
  const root = container.querySelector<HTMLElement>(
    "[data-contents-editor-code-drawing-root]",
  );

  if (!data || !root) {
    return;
  }

  const code = typeof data.code === "string" ? data.code : "";
  const drawingType =
    typeof data.drawingType === "string" ? data.drawingType : "Mermaid";
  const drawingMode =
    typeof data.drawingMode === "string" ? data.drawingMode : "Both";
  const normalizedMode = drawingMode.toLowerCase();
  const showCode = normalizedMode === "both" || normalizedMode === "code";
  const showDrawing = normalizedMode !== "code";

  root.replaceChildren();

  if (showCode) {
    const pre = document.createElement("pre");
    const codeElement = document.createElement("code");
    codeElement.textContent = code;
    pre.append(codeElement);
    root.append(pre);
  }

  if (!showDrawing) {
    return;
  }

  const renderRoot = document.createElement("div");
  renderRoot.className = "contents-editor-code-drawing__render";
  renderRoot.setAttribute("role", "img");
  renderRoot.setAttribute("aria-label", `${drawingType} 코드 그림`);
  root.append(renderRoot);

  try {
    const imageUrl = await renderCodeDrawing(
      drawingType as CodeDrawingType,
      code,
    );
    const image = document.createElement("img");
    image.alt = `${drawingType} 코드로 생성한 그림`;
    image.decoding = "async";
    image.src = imageUrl;
    renderRoot.replaceChildren(image);
  } catch {
    replaceWithError(renderRoot, "코드 그림을 렌더링하지 못했습니다.");
  }
}

async function mountExcalidraw(container: Element) {
  if (mountedExcalidraws.has(container)) {
    return;
  }

  mountedExcalidraws.add(container);
  const data = readJsonData(
    container,
    "[data-contents-editor-excalidraw-data]",
  );
  const root = container.querySelector<HTMLElement>(
    "[data-contents-editor-excalidraw-root]",
  );

  if (!data || !root) {
    return;
  }

  const elements = Array.isArray(data.elements)
    ? data.elements
    : Array.isArray(data.excalidrawElements)
      ? data.excalidrawElements
      : [];

  if (elements.length === 0) {
    replaceWithError(root, "저장된 Excalidraw 요소가 없습니다.");
    return;
  }

  try {
    const { exportToSvg } = await import("@excalidraw/excalidraw");
    const appState =
      data.appState && typeof data.appState === "object"
        ? (data.appState as JsonRecord)
        : data.state && typeof data.state === "object"
          ? (data.state as JsonRecord)
          : {};
    const files =
      data.files && typeof data.files === "object"
        ? data.files
        : null;
    const svg = await exportToSvg({
      appState: {
        ...appState,
        exportWithDarkMode: false,
      },
      elements: elements as Parameters<typeof exportToSvg>[0]["elements"],
      files: files as Parameters<typeof exportToSvg>[0]["files"],
    });
    svg.setAttribute("aria-label", "Excalidraw 그림");
    svg.setAttribute("role", "img");
    root.replaceChildren(svg);
  } catch {
    replaceWithError(root, "Excalidraw 그림을 렌더링하지 못했습니다.");
  }
}

export function mountPublicInteractiveBlocks(root: ParentNode = document) {
  root
    .querySelectorAll("[data-contents-editor-code-drawing]")
    .forEach((container) => void mountCodeDrawing(container));
  root
    .querySelectorAll("[data-contents-editor-excalidraw]")
    .forEach((container) => void mountExcalidraw(container));
}
