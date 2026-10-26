import React from "react";
import { createRoot } from "react-dom/client";

import { App } from "./App";
import "@excalidraw/excalidraw/index.css";
import "./styles.css";

function normalizeNumeric(value: string | undefined): number | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : undefined;
}

const root = document.getElementById("contents-editor-admin-root");
const bootErrorMessage = "읽기자료 편집기를 시작하지 못했습니다.";

if (!root) {
  const host = document.body ?? document.documentElement;
  const fallback = document.createElement("div");
  fallback.className = "notice notice-error inline";
  fallback.innerHTML = "<p><strong>" + bootErrorMessage + "</strong> 관리자 화면의 루트 컨테이너가 존재하지 않습니다.</p>";
  host.appendChild(fallback);
  throw new Error("관리자 루트 엘리먼트를 찾을 수 없습니다.");
}

const settingsFromHtml = window.contentsEditorAdmin ?? {};
const canPublishRaw = root.dataset.canPublish;
const canPublish =
  typeof canPublishRaw === "string"
    ? canPublishRaw === "1"
    : settingsFromHtml.canPublish === true;

window.contentsEditorAdmin = {
  ...settingsFromHtml,
  canPublish,
  canReimportJson: root.dataset.canReimportJson === "1",
  canImportServer: root.dataset.canImportServer === "1",
  maxUploadSize:
    normalizeNumeric(root.dataset.maxUploadSize) ?? settingsFromHtml.maxUploadSize ?? 0,
  maxUploadSizeLabel:
    root.dataset.maxUploadSizeLabel ?? settingsFromHtml.maxUploadSizeLabel ?? "",
  nonce: root.dataset.restNonce ?? settingsFromHtml.nonce ?? "",
  publicBaseUrl: root.dataset.publicBaseUrl ?? settingsFromHtml.publicBaseUrl ?? "",
  restNamespace: root.dataset.restNamespace ?? settingsFromHtml.restNamespace ?? "readcontents-library/v1",
  restUrl: root.dataset.restUrl ?? settingsFromHtml.restUrl ?? "",
  version: root.dataset.version ?? settingsFromHtml.version ?? "",
};

try {
  root.dataset.contentsEditorMounted = "1";
  createRoot(root).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
  );
} catch (error) {
  root.dataset.contentsEditorMounted = "0";
  root.innerHTML =
    "<div class=\"notice notice-error inline\"><p><strong>" +
    bootErrorMessage +
    " </strong>" +
    (error instanceof Error ? error.message : "알 수 없는 오류가 발생했습니다.") +
    "</p></div>";
  throw error;
}
