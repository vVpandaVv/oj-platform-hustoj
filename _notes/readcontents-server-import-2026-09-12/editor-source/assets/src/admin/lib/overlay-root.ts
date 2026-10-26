const OVERLAY_ROOT_ID = 'contents-editor-overlay-root';

export function getContentsEditorOverlayRoot(): HTMLElement | undefined {
  if (typeof document === 'undefined') {
    return undefined;
  }

  const existingRoot = document.getElementById(OVERLAY_ROOT_ID);

  if (existingRoot instanceof HTMLElement) {
    return existingRoot;
  }

  const root = document.createElement('div');
  root.id = OVERLAY_ROOT_ID;
  root.setAttribute('data-contents-editor-overlay-root', 'true');
  root.style.position = 'fixed';
  root.style.inset = '0';
  root.style.width = '100%';
  root.style.height = '100%';
  root.style.pointerEvents = 'none';
  document.body.append(root);

  return root;
}
