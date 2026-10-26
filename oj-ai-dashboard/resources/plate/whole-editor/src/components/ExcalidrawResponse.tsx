import * as React from 'react';
import { Excalidraw, exportToBlob } from '@excalidraw/excalidraw';
import type { ExcalidrawImperativeAPI } from '@excalidraw/excalidraw/types';
import '@excalidraw/excalidraw/index.css';

type DrawingValue = {
  appState?: Record<string, unknown>;
  elements?: readonly any[];
  files?: Record<string, unknown>;
  image?: {
    data_url?: string;
    height?: number;
    mime?: string;
    updated_at?: string;
    width?: number;
  };
  source?: string;
  type?: string;
  version?: number;
};

interface ExcalidrawResponseProps {
  editable: boolean;
  field: HTMLInputElement;
}

declare global {
  interface Window {
    ojAiDashboardWorksheetExcalidrawFlushers?: Set<() => Promise<void>>;
    ojAiDashboardWorksheetFlushExcalidrawResponses?: () => Promise<void>;
  }
}

function flushRegistry() {
  if (!window.ojAiDashboardWorksheetExcalidrawFlushers) {
    window.ojAiDashboardWorksheetExcalidrawFlushers = new Set();
  }

  if (!window.ojAiDashboardWorksheetFlushExcalidrawResponses) {
    window.ojAiDashboardWorksheetFlushExcalidrawResponses = async () => {
      const flushers = Array.from(window.ojAiDashboardWorksheetExcalidrawFlushers || []);
      await Promise.all(flushers.map((flush) => flush()));
    };
  }

  return window.ojAiDashboardWorksheetExcalidrawFlushers;
}

function parseValue(raw: string): DrawingValue {
  if (!raw.trim()) {
    return {};
  }

  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

function blobToDataUrl(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(reader.error || new Error('Failed to read image blob.'));
    reader.onload = () => resolve(String(reader.result || ''));
    reader.readAsDataURL(blob);
  });
}

function imageSize(dataUrl: string): Promise<{ height: number; width: number }> {
  return new Promise((resolve) => {
    const image = new Image();
    image.onload = () => resolve({ height: image.naturalHeight, width: image.naturalWidth });
    image.onerror = () => resolve({ height: 0, width: 0 });
    image.src = dataUrl;
  });
}

function serializeAppState(appState: any): Record<string, unknown> {
  return {
    gridSize: appState?.gridSize ?? null,
    name: appState?.name ?? '',
    scrollX: appState?.scrollX ?? 0,
    scrollY: appState?.scrollY ?? 0,
    theme: appState?.theme ?? 'light',
    viewBackgroundColor: appState?.viewBackgroundColor ?? '#ffffff',
    zoom: appState?.zoom ?? undefined,
  };
}

export function ExcalidrawResponse({ editable, field }: ExcalidrawResponseProps) {
  const initialValue = React.useMemo(() => parseValue(field.value), [field]);
  const [previewUrl, setPreviewUrl] = React.useState(initialValue.image?.data_url || '');
  const [status, setStatus] = React.useState('');
  const apiRef = React.useRef<ExcalidrawImperativeAPI | null>(null);
  const canvasRef = React.useRef<HTMLDivElement | null>(null);
  const latestRef = React.useRef({
    appState: initialValue.appState || {},
    elements: initialValue.elements || [],
    files: initialValue.files || {},
  });
  const exportIdRef = React.useRef(0);
  const timerRef = React.useRef<number | null>(null);
  const refreshFrameRef = React.useRef<number | null>(null);
  const refreshTimeoutsRef = React.useRef<number[]>([]);

  const clearScheduledRefresh = React.useCallback(() => {
    if (refreshFrameRef.current) {
      window.cancelAnimationFrame(refreshFrameRef.current);
      refreshFrameRef.current = null;
    }

    refreshTimeoutsRef.current.forEach((timeoutId) => window.clearTimeout(timeoutId));
    refreshTimeoutsRef.current = [];
  }, []);

  const refreshCanvas = React.useCallback(() => {
    if (refreshFrameRef.current) {
      return;
    }

    refreshFrameRef.current = window.requestAnimationFrame(() => {
      refreshFrameRef.current = null;

      const api = apiRef.current;
      const canvas = canvasRef.current;
      if (!api || !canvas) {
        return;
      }

      const rect = canvas.getBoundingClientRect();
      if (rect.width < 1 || rect.height < 1) {
        return;
      }

      api.refresh();
    });
  }, []);

  const queueLayoutRefresh = React.useCallback(() => {
    refreshCanvas();
    refreshTimeoutsRef.current.forEach((timeoutId) => window.clearTimeout(timeoutId));
    refreshTimeoutsRef.current = [80, 240, 600].map((delay) => window.setTimeout(refreshCanvas, delay));
  }, [refreshCanvas]);

  const writeValue = React.useCallback(async () => {
    if (timerRef.current) {
      window.clearTimeout(timerRef.current);
      timerRef.current = null;
    }

    const exportId = exportIdRef.current + 1;
    exportIdRef.current = exportId;
    const { appState, elements, files } = latestRef.current;
    const activeElements = (Array.isArray(elements) ? elements : []).filter((element: any) => !element?.isDeleted);

    setStatus('이미지 변환 중...');

    let image: DrawingValue['image'] = {
      data_url: '',
      height: 0,
      mime: 'image/png',
      updated_at: new Date().toISOString(),
      width: 0,
    };

    if (activeElements.length > 0) {
      try {
        const blob = await exportToBlob({
          appState: {
            exportBackground: true,
            viewBackgroundColor: String(appState?.viewBackgroundColor || '#ffffff'),
          },
          elements: activeElements as any,
          exportPadding: 16,
          files: (files || {}) as any,
          maxWidthOrHeight: 1600,
          mimeType: 'image/png',
        });
        const dataUrl = await blobToDataUrl(blob);
        const size = await imageSize(dataUrl);
        image = {
          data_url: dataUrl,
          height: size.height,
          mime: 'image/png',
          updated_at: new Date().toISOString(),
          width: size.width,
        };
      } catch (error) {
        setStatus('이미지 변환에 실패했습니다. 그림 데이터는 저장됩니다.');
      }
    }

    if (exportId !== exportIdRef.current) {
      return;
    }

    const payload: DrawingValue = {
      appState: serializeAppState(appState),
      elements: activeElements,
      files: files || {},
      image,
      source: 'oj-ai-dashboard',
      type: 'excalidraw_response',
      version: 1,
    };

    field.value = JSON.stringify(payload);
    field.dispatchEvent(new Event('input', { bubbles: true }));
    setPreviewUrl(image?.data_url || '');
    setStatus(activeElements.length > 0 ? '그림이 JSON 이미지로 저장되었습니다.' : '그림을 입력하세요.');
  }, [field]);

  React.useEffect(() => {
    const flush = async () => {
      await writeValue();
    };
    const flushers = flushRegistry();
    flushers.add(flush);
    return () => {
      flushers.delete(flush);
    };
  }, [writeValue]);

  const scheduleWrite = React.useCallback(() => {
    if (timerRef.current) {
      window.clearTimeout(timerRef.current);
    }
    timerRef.current = window.setTimeout(() => {
      void writeValue();
    }, 650);
  }, [writeValue]);

  React.useEffect(() => {
    return () => {
      if (timerRef.current) {
        window.clearTimeout(timerRef.current);
      }
      clearScheduledRefresh();
    };
  }, [clearScheduledRefresh]);

  React.useLayoutEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) {
      return;
    }

    queueLayoutRefresh();

    const handleLayoutChange = () => {
      queueLayoutRefresh();
    };

    const resizeObserver = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(handleLayoutChange)
      : null;
    resizeObserver?.observe(canvas);

    const mutationObserver = typeof MutationObserver !== 'undefined'
      ? new MutationObserver(handleLayoutChange)
      : null;

    if (mutationObserver) {
      const observedNodes = new Set<Element>();
      observedNodes.add(document.documentElement);
      if (document.body) {
        observedNodes.add(document.body);
      }

      let ancestor: HTMLElement | null = canvas;
      for (let depth = 0; ancestor && depth < 10; depth += 1) {
        observedNodes.add(ancestor);
        ancestor = ancestor.parentElement;
      }

      observedNodes.forEach((node) => {
        mutationObserver.observe(node, {
          attributeFilter: ['class', 'style'],
          attributes: true,
        });
      });
    }

    window.addEventListener('resize', handleLayoutChange);
    window.addEventListener('orientationchange', handleLayoutChange);
    window.addEventListener('scroll', handleLayoutChange, true);
    window.visualViewport?.addEventListener('resize', handleLayoutChange);
    window.visualViewport?.addEventListener('scroll', handleLayoutChange);

    return () => {
      resizeObserver?.disconnect();
      mutationObserver?.disconnect();
      window.removeEventListener('resize', handleLayoutChange);
      window.removeEventListener('orientationchange', handleLayoutChange);
      window.removeEventListener('scroll', handleLayoutChange, true);
      window.visualViewport?.removeEventListener('resize', handleLayoutChange);
      window.visualViewport?.removeEventListener('scroll', handleLayoutChange);
    };
  }, [queueLayoutRefresh]);

  return (
    <div className="hws-excalidraw-response">
      <div className="hws-excalidraw-response__canvas" ref={canvasRef}>
        <Excalidraw
          autoFocus={false}
          detectScroll
          excalidrawAPI={(api) => {
            apiRef.current = api;
            queueLayoutRefresh();
          }}
          initialData={{
            appState: initialValue.appState || {},
            elements: initialValue.elements || [],
            files: initialValue.files || {},
            scrollToContent: true,
          }}
          langCode="ko-KR"
          onChange={(elements, appState, files) => {
            latestRef.current = { appState, elements, files };
            scheduleWrite();
          }}
          viewModeEnabled={!editable}
        />
      </div>
      {previewUrl && (
        <div className="hws-excalidraw-response__preview">
          <img alt="Excalidraw response preview" src={previewUrl} />
        </div>
      )}
      <p className="hws-excalidraw-response__status">{status || '그림은 자동으로 이미지 JSON으로 저장됩니다.'}</p>
    </div>
  );
}

