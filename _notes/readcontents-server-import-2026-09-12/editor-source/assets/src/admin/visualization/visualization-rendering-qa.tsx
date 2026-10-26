import * as React from 'react';
import { createRoot } from 'react-dom/client';

import '../styles.css';

import { createEmptyVisualizationDocument } from './visualization-model';
import { createVisualizationDocumentForType } from './visualization-block';
import { VisualizationRenderer } from './visualization-renderer';
import { VISUALIZATION_TYPE_DEFINITIONS } from './visualization-types';

type QaResult = {
  hasRenderableShape: boolean;
  hasSvg: boolean;
  isUnsupported: boolean;
  label: string;
  type: string;
};

declare global {
  interface Window {
    __visualizationRenderingQa?: {
      failed: QaResult[];
      results: QaResult[];
      total: number;
    };
  }
}

const SHAPE_SELECTOR =
  'path,rect,circle,line,polyline,polygon,ellipse,text,g[data-visual-node-id]';

function createQaDocument(type: (typeof VISUALIZATION_TYPE_DEFINITIONS)[number]['type']) {
  const baseDocument = createEmptyVisualizationDocument(
    type,
    `qa-${type}`,
    type
  );

  return createVisualizationDocumentForType(baseDocument, type, 'sample');
}

function collectQaResults() {
  const cards = Array.from(
    document.querySelectorAll<HTMLElement>('[data-visualization-qa-card]')
  );

  const results = cards.map((card): QaResult => {
    const svg = card.querySelector('svg');
    const type = card.dataset.visualizationType ?? '';
    const label = card.dataset.visualizationLabel ?? type;
    const text = card.textContent ?? '';
    const hasRenderableShape = Boolean(svg?.querySelector(SHAPE_SELECTOR));
    const isUnsupported =
      text.includes('렌더러 준비 중') || text.includes('Unsupported');

    return {
      hasRenderableShape,
      hasSvg: Boolean(svg),
      isUnsupported,
      label,
      type,
    };
  });

  const failed = results.filter(
    (result) =>
      !result.hasSvg || !result.hasRenderableShape || result.isUnsupported
  );

  window.__visualizationRenderingQa = {
    failed,
    results,
    total: results.length,
  };

  return { failed, results };
}

function VisualizationRenderingQaApp() {
  const [results, setResults] = React.useState<QaResult[]>([]);
  const failed = results.filter(
    (result) =>
      !result.hasSvg || !result.hasRenderableShape || result.isUnsupported
  );

  React.useEffect(() => {
    window.requestAnimationFrame(() => {
      setResults(collectQaResults().results);
    });
  }, []);

  return (
    <main className="mx-auto max-w-[1440px] px-6 py-8 text-slate-950">
      <header className="mb-6">
        <p className="mb-2 text-sm font-bold text-blue-700">시각화 QA</p>
        <h1 className="m-0 text-3xl font-bold">카드 선택 결과 렌더링 점검</h1>
        <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
          등록된 모든 시각화 유형의 starter DSL을 실제 학습자 렌더러로 렌더링합니다.
          이 화면은 유형 선택 카드와 실제 삽입 결과가 같은 의미로 보이는지
          점검하기 위한 개발용 기준 화면입니다.
        </p>
      </header>

      <section
        className={
          failed.length === 0
            ? 'mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800'
            : 'mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800'
        }
        data-visualization-qa-summary
      >
        {results.length === 0
          ? '렌더링 결과를 수집하는 중입니다.'
          : failed.length === 0
            ? `렌더링 기본 검사 통과: ${results.length}개 유형`
            : `렌더링 기본 검사 실패: ${failed.length}개 / ${results.length}개`}
      </section>

      {failed.length > 0 && (
        <section className="mb-6 rounded-xl border bg-white p-4 text-sm">
          <strong>수정이 필요한 유형</strong>
          <ul className="mt-2 grid gap-1 p-0">
            {failed.map((result) => (
              <li className="list-none text-red-700" key={result.type}>
                {result.label} ({result.type}) - SVG:{' '}
                {result.hasSvg ? '있음' : '없음'}, 도형:{' '}
                {result.hasRenderableShape ? '있음' : '없음'}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="grid gap-5 lg:grid-cols-2">
        {VISUALIZATION_TYPE_DEFINITIONS.map((definition) => {
          const qaDocument = createQaDocument(definition.type);

          return (
            <article
              className="overflow-hidden rounded-xl border bg-white shadow-sm"
              data-visualization-label={definition.label}
              data-visualization-qa-card
              data-visualization-type={definition.type}
              key={definition.type}
            >
              <div className="border-b px-4 py-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <h2 className="m-0 text-lg font-bold">
                      {definition.label}
                    </h2>
                    <p className="m-0 mt-1 text-xs leading-5 text-slate-600">
                      {definition.description}
                    </p>
                  </div>
                  <span className="rounded-full border bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-600">
                    {definition.type}
                  </span>
                </div>
              </div>
              <div className="min-h-[330px] p-3" data-visualization-qa-renderer>
                <VisualizationRenderer document={qaDocument} readOnly />
              </div>
            </article>
          );
        })}
      </section>
    </main>
  );
}

const root = document.getElementById('visualization-rendering-qa-root');

if (root) {
  createRoot(root).render(
    <React.StrictMode>
      <VisualizationRenderingQaApp />
    </React.StrictMode>
  );
}
