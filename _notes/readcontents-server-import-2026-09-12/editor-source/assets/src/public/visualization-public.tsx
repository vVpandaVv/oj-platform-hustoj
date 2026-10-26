import * as React from "react";
import { createRoot, type Root } from "react-dom/client";

import { parseVisualizationDsl } from "../admin/visualization/visualization-dsl";
import { VisualizationRenderer } from "../admin/visualization/visualization-renderer";
import {
  getOrderedVisualizationSteps,
  type VisualizationDocument,
  type VisualizationStep,
} from "../admin/visualization/visualization-model";

const mountedVisualizations = new WeakMap<Element, Root>();

function readVisualizationDocument(container: Element): VisualizationDocument | null {
  const dataElement = container.querySelector<HTMLScriptElement>(
    "[data-contents-editor-visualization-data]",
  );

  if (!dataElement?.textContent) {
    return null;
  }

  try {
    const parsed = JSON.parse(dataElement.textContent);

    if (
      parsed &&
      typeof parsed === "object" &&
      typeof parsed.visualType === "string" &&
      parsed.source &&
      typeof parsed.source === "object"
    ) {
      return parsed as VisualizationDocument;
    }
  } catch {
    return null;
  }

  return null;
}

function showVisualizationError(container: Element, message: string) {
  const rootElement = container.querySelector<HTMLElement>(
    "[data-contents-editor-visualization-root]",
  );

  if (!rootElement) {
    return;
  }

  rootElement.innerHTML = "";
  const notice = document.createElement("p");
  notice.className = "contents-editor-public-visualization__error";
  notice.textContent = message;
  rootElement.append(notice);
}

function resolveStepDocument(documentData: VisualizationDocument) {
  if (documentData.source?.format !== "algoviz-dsl") {
    return documentData;
  }

  try {
    return {
      ...parseVisualizationDsl(
        documentData.source.code,
        documentData.visualType,
        documentData.title,
      ),
      assets: documentData.assets,
      metadata: documentData.metadata,
      schemaVersion: documentData.schemaVersion,
      visualId: documentData.visualId,
    };
  } catch {
    return documentData;
  }
}

function PublicVisualizationPlayer({
  documentData,
}: {
  documentData: VisualizationDocument;
}) {
  const stepDocument = React.useMemo(
    () => resolveStepDocument(documentData),
    [documentData],
  );
  const steps = React.useMemo(
    () => getOrderedVisualizationSteps(stepDocument),
    [stepDocument],
  );
  const [currentStepId, setCurrentStepId] = React.useState<string | null>(
    () => steps[0]?.id ?? null,
  );
  const [playing, setPlaying] = React.useState(false);

  React.useEffect(() => {
    if (steps.length === 0) {
      setCurrentStepId(null);
      setPlaying(false);
      return;
    }

    if (!currentStepId || !steps.some((step) => step.id === currentStepId)) {
      setCurrentStepId(steps[0].id);
    }
  }, [currentStepId, steps]);

  React.useEffect(() => {
    if (!playing || steps.length <= 1) {
      return;
    }

    const currentIndex = Math.max(
      0,
      steps.findIndex((step) => step.id === currentStepId),
    );
    const currentStep = steps[currentIndex] ?? steps[0];
    const timeout = window.setTimeout(() => {
      const nextIndex = (currentIndex + 1) % steps.length;
      setCurrentStepId(steps[nextIndex].id);
    }, currentStep.durationMs ?? 1600);

    return () => window.clearTimeout(timeout);
  }, [currentStepId, playing, steps]);

  return (
    <div
      className="contents-editor-public-visualization__player contents-editor-visualization-result-shell"
      data-visualization-display-mode="result"
    >
      <div className="contents-editor-visualization-result-shell__header">
        <strong>{documentData.title || "시각화 자료"}</strong>
      </div>
      <PublicStepBar
        currentStepId={currentStepId}
        onPlayingChange={setPlaying}
        onStepSelect={setCurrentStepId}
        playing={playing}
        steps={steps}
      />
      <VisualizationRenderer
        currentStepId={currentStepId}
        document={documentData}
        readOnly
      />
    </div>
  );
}

function PublicStepBar({
  currentStepId,
  onPlayingChange,
  onStepSelect,
  playing,
  steps,
}: {
  currentStepId: string | null;
  onPlayingChange: (playing: boolean) => void;
  onStepSelect: (stepId: string) => void;
  playing: boolean;
  steps: VisualizationStep[];
}) {
  if (steps.length === 0) {
    return null;
  }

  const currentIndex = Math.max(
    0,
    steps.findIndex((step) => step.id === currentStepId),
  );
  const currentStep = steps[currentIndex] ?? steps[0];

  return (
    <div
      className="contents-editor-public-visualization__steps"
      data-contents-editor-visualization-steps
    >
      <div className="contents-editor-public-visualization__step-controls">
        <button
          type="button"
          disabled={currentIndex <= 0}
          onClick={() => onStepSelect(steps[0].id)}
        >
          처음
        </button>
        <button
          type="button"
          disabled={currentIndex <= 0}
          onClick={() => onStepSelect(steps[currentIndex - 1].id)}
        >
          이전
        </button>
        <button type="button" onClick={() => onPlayingChange(!playing)}>
          {playing ? "일시정지" : "재생"}
        </button>
        <button
          type="button"
          disabled={currentIndex >= steps.length - 1}
          onClick={() => onStepSelect(steps[currentIndex + 1].id)}
        >
          다음
        </button>
        <button
          type="button"
          disabled={currentIndex >= steps.length - 1}
          onClick={() => onStepSelect(steps[steps.length - 1].id)}
        >
          끝
        </button>
        <span className="contents-editor-public-visualization__step-count">
          {currentIndex + 1} / {steps.length} 단계
        </span>
      </div>

      <div className="contents-editor-public-visualization__step-track">
        {steps.map((step, index) => (
          <button
            key={step.id}
            type="button"
            className={
              index === currentIndex
                ? "is-active"
                : index < currentIndex
                  ? "is-past"
                  : ""
            }
            aria-current={index === currentIndex ? "step" : undefined}
            aria-label={`${index + 1}단계로 이동`}
            onClick={() => onStepSelect(step.id)}
          />
        ))}
      </div>

      <p className="contents-editor-public-visualization__step-note">
        <strong>{currentStep.title || `${currentIndex + 1}단계`}</strong>
        {currentStep.description || "아직 단계 설명이 없습니다."}
      </p>
    </div>
  );
}

export function mountPublicVisualizations(root: ParentNode = document) {
  root
    .querySelectorAll("[data-contents-editor-visualization]")
    .forEach((container) => {
      if (mountedVisualizations.has(container)) {
        return;
      }

      const rootElement = container.querySelector<HTMLElement>(
        "[data-contents-editor-visualization-root]",
      );
      const documentData = readVisualizationDocument(container);

      if (!rootElement || !documentData) {
        showVisualizationError(
          container,
          "시각화 자료를 불러올 수 없습니다.",
        );
        return;
      }

      const reactRoot = createRoot(rootElement);
      mountedVisualizations.set(container, reactRoot);
      reactRoot.render(<PublicVisualizationPlayer documentData={documentData} />);
    });
}
