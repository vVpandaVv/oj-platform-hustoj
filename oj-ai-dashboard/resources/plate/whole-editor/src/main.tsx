import * as React from 'react';
import { createRoot } from 'react-dom/client';

import { ExcalidrawResponse } from './components/ExcalidrawResponse';
import { PlateReadonlyViewer, PlateTemplateEditor, StudentPlateEditor } from './components/PlateTemplateEditor';
import { TooltipProvider } from './registry/ui/tooltip';
import './styles.css';

type BuilderDom = {
  builderField: HTMLTextAreaElement | null;
  drawingRootElements: HTMLElement[];
  readonlyResponsesField: HTMLTextAreaElement | null;
  readonlyValueField: HTMLTextAreaElement | null;
  readonlyViewerRootElement: HTMLElement | null;
  renderField: HTMLTextAreaElement | null;
  rootElement: HTMLElement | null;
  studentContentField: HTMLTextAreaElement | null;
  studentRootElement: HTMLElement | null;
  templateRootElement: HTMLElement | null;
  titleField: HTMLInputElement | null;
};

function getBuilderDom(): BuilderDom {
  const templateRootElement = document.getElementById('ws-plate-template-builder-root');
  const explicitStudentRootElement = document.getElementById('ws-plate-student-editor-root');
  const readonlyViewerRootElement = document.getElementById('ws-plate-readonly-viewer-root');
  const studentRootElement = explicitStudentRootElement
    || (templateRootElement?.getAttribute('data-student-editor') === '1' ? templateRootElement : null);
  const rootElement = templateRootElement?.getAttribute('data-student-editor') === '1' ? null : templateRootElement;

  [templateRootElement, explicitStudentRootElement, readonlyViewerRootElement].forEach((element) => {
    element?.classList.add('hws-plate-template-builder-root');
  });

  return {
    builderField: document.getElementById('ws-builder-schema') as HTMLTextAreaElement | null,
    drawingRootElements: Array.from(document.querySelectorAll<HTMLElement>('[data-ws-excalidraw-response-root]')),
    readonlyResponsesField: document.getElementById('ws-plate-preview-responses') as HTMLTextAreaElement | null,
    readonlyValueField: document.getElementById('ws-plate-readonly-value') as HTMLTextAreaElement | null,
    readonlyViewerRootElement,
    renderField: document.getElementById('ws-render-schema') as HTMLTextAreaElement | null,
    rootElement,
    studentContentField: document.getElementById('oj-ai-dashboard-worksheet-content') as HTMLTextAreaElement | null,
    studentRootElement,
    templateRootElement,
    titleField: document.getElementById('ws-template-title') as HTMLInputElement | null,
  };
}

function injectPlateFallbackStyles() {
  if (document.getElementById('hws-plate-runtime-fallback-styles')) {
    return;
  }

  const style = document.createElement('style');
  style.id = 'hws-plate-runtime-fallback-styles';
  style.textContent = `
    #ws-plate-template-builder-root,
    #ws-plate-student-editor-root,
    .hws-plate-template-builder-root {
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      overflow: visible;
    }
    .hws-plate-loading-message {
      display: block;
      width: 100%;
      box-sizing: border-box;
      margin: 0;
      padding: 12px 14px;
      border: 1px solid var(--border, #e5e7eb);
      border-radius: 8px;
      background: var(--background, #fff);
      color: var(--muted-foreground, #64748b);
      line-height: 1.5;
      word-break: keep-all;
    }
    .hws-response-node__image {
      max-width: 100%;
      height: auto;
      border: 1px solid var(--border, #e5e7eb);
      border-radius: 8px;
      background: #fff;
    }
    .hws-response-node__pre {
      max-height: 260px;
      margin: 0;
      overflow: auto;
      white-space: pre-wrap;
    }
  `;
  document.head.appendChild(style);
}

declare global {
  interface Window {
    ojAiDashboardWorksheetPlateBuilderShowError?: (message: unknown) => void;
  }
}

class BuilderErrorBoundary extends React.Component<React.PropsWithChildren, { error: Error | null }> {
  state = { error: null };

  static getDerivedStateFromError(error: Error) {
    return { error };
  }

  componentDidCatch(error: Error) {
    window.ojAiDashboardWorksheetPlateBuilderShowError?.(error.message);
  }

  render() {
    if (this.state.error) {
      return (
        <div className="hws-plate-load-error">
          <strong>Plate 편집기 오류가 발생했습니다.</strong>
          <p>{this.state.error.message}</p>
        </div>
      );
    }

    return this.props.children;
  }
}

function showError(message: unknown) {
  const text = message instanceof Error ? message.message : String(message || '알 수 없는 오류');
  if (window.ojAiDashboardWorksheetPlateBuilderShowError) {
    window.ojAiDashboardWorksheetPlateBuilderShowError(text);
    return;
  }

  const { readonlyViewerRootElement, studentRootElement, rootElement } = getBuilderDom();
  const errorRootElement = readonlyViewerRootElement || studentRootElement || rootElement;
  if (errorRootElement) {
    errorRootElement.innerHTML = `<div class="hws-plate-load-error"><strong>Plate 편집기 로드에 실패했습니다.</strong><p>${text}</p></div>`;
  }
}

function parsePlateValue(raw: string): any[] {
  if (!raw.trim()) {
    return [{ type: 'p', children: [{ text: '' }] }];
  }

  try {
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) {
      return normalizePlateValue(parsed);
    }
    if (parsed?.engine === 'plate_student' && Array.isArray(parsed.plate_value)) {
      return normalizePlateValue(parsed.plate_value);
    }
    if (Array.isArray(parsed?.plate_value)) {
      return normalizePlateValue(parsed.plate_value);
    }
  } catch {
    return raw.split(/\r\n|\r|\n/).map((line) => ({ type: 'p', children: [{ text: line }] }));
  }

  return [{ type: 'p', children: [{ text: '' }] }];
}

function normalizePlateValue(value: any[]): any[] {
  const nodes = value
    .map((node) => normalizePlateNode(node))
    .filter(Boolean);

  return nodes.length > 0 ? nodes : [{ type: 'p', children: [{ text: '' }] }];
}

function normalizePlateNode(node: any): any | null {
  if (!node || typeof node !== 'object') {
    return null;
  }

  if (typeof node.text === 'string') {
    return { ...node };
  }

  const next = { ...node };
  const children = Array.isArray(next.children)
    ? next.children.map((child: any) => normalizePlateNode(child)).filter(Boolean)
    : [];

  next.children = children.length > 0 ? children : [{ text: '' }];
  return next;
}

function bootstrapPlateBuilder() {
  injectPlateFallbackStyles();

  const {
    builderField,
    drawingRootElements,
    readonlyResponsesField,
    readonlyValueField,
    readonlyViewerRootElement,
    renderField,
    rootElement,
    studentContentField,
    studentRootElement,
    templateRootElement,
    titleField,
  } = getBuilderDom();

  try {
    if (templateRootElement?.getAttribute('data-dev-fixture') === '1' && builderField && renderField) {
      const defaultSchema = window.ojAiDashboardWorksheetPlateBuilder?.defaultSchema || {};
      const fixtureJson = JSON.stringify(defaultSchema, null, 2);
      builderField.value = fixtureJson;
      renderField.value = fixtureJson;
    }

    drawingRootElements.forEach((drawingRootElement) => {
      const fieldId = drawingRootElement.getAttribute('data-ws-field-id') || '';
      const field = fieldId ? document.getElementById(fieldId) as HTMLInputElement | null : null;
      if (!field || drawingRootElement.dataset.hwsMounted === '1') {
        return;
      }

      drawingRootElement.dataset.hwsMounted = '1';
      createRoot(drawingRootElement).render(
        <BuilderErrorBoundary>
          <ExcalidrawResponse
            editable={drawingRootElement.getAttribute('data-editable') === '1'}
            field={field}
          />
        </BuilderErrorBoundary>
      );
    });

    if (readonlyViewerRootElement && readonlyValueField) {
      if (readonlyViewerRootElement.dataset.hwsMounted === '1') {
        return;
      }
      readonlyViewerRootElement.dataset.hwsMounted = '1';

      if (readonlyResponsesField?.value.trim()) {
        try {
          const parsedResponses = JSON.parse(readonlyResponsesField.value);
          window.ojAiDashboardWorksheetPlatePreviewResponses =
            parsedResponses && typeof parsedResponses === 'object' && !Array.isArray(parsedResponses)
              ? parsedResponses
              : {};
        } catch {
          window.ojAiDashboardWorksheetPlatePreviewResponses = {};
        }
      }

      createRoot(readonlyViewerRootElement).render(
        <BuilderErrorBoundary>
          <TooltipProvider delayDuration={350}>
            <PlateReadonlyViewer value={parsePlateValue(readonlyValueField.value)} />
          </TooltipProvider>
        </BuilderErrorBoundary>
      );
    } else if (studentRootElement && studentContentField) {
      if (studentRootElement.dataset.hwsMounted === '1') {
        return;
      }
      studentRootElement.dataset.hwsMounted = '1';
      const editable = studentRootElement.getAttribute('data-editable') === '1';
      createRoot(studentRootElement).render(
        <BuilderErrorBoundary>
          <TooltipProvider delayDuration={350}>
            <StudentPlateEditor contentField={studentContentField} editable={editable} />
          </TooltipProvider>
        </BuilderErrorBoundary>
      );
    } else if (rootElement && builderField && renderField) {
      if (rootElement.dataset.hwsMounted === '1') {
        return;
      }
      rootElement.dataset.hwsMounted = '1';
      createRoot(rootElement).render(
        <BuilderErrorBoundary>
          <TooltipProvider delayDuration={350}>
            <PlateTemplateEditor builderField={builderField} renderField={renderField} titleField={titleField} />
          </TooltipProvider>
        </BuilderErrorBoundary>
      );
    } else if (readonlyViewerRootElement || rootElement || studentRootElement) {
      showError('편집기 초기화에 필요한 화면 요소를 찾을 수 없습니다.');
    }
  } catch (error) {
    showError(error);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrapPlateBuilder, { once: true });
} else {
  bootstrapPlateBuilder();
}

