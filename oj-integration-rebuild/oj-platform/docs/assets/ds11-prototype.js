(() => {
  "use strict";

  const toast = document.querySelector(".toast");
  const prototypeUi = window.OJUIPrototype;
  let toastTimer;

  const showToast = (message) => {
    if (prototypeUi && typeof prototypeUi.showToast === "function") {
      prototypeUi.showToast(message);
      return;
    }

    if (!toast) {
      return;
    }

    toast.textContent = message;
    toast.classList.add("show");
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.classList.remove("show"), 1800);
  };

  const modeButtons = Array.from(document.querySelectorAll("[data-submit-mode]"));
  const modePanels = Array.from(document.querySelectorAll("[data-submit-panel]"));
  const submissionCompose = document.querySelector("[data-submission-compose]");
  const blocklyFrame = document.querySelector("[data-blockly-frame]");
  const blocklyContract = window.OJBlocklyContract;
  const sourceEditor = document.querySelector(".source-editor");
  const codeSubmitButton = document.querySelector("[data-submit-code]");
  const policyError = document.querySelector("[data-policy-error]");
  const standardSubmissionScreen = sourceEditor?.closest("[data-screen]");
  const forbiddenPatterns = Array.from(document.querySelectorAll("[data-forbidden-code]"))
    .map((item) => item.textContent.trim())
    .filter(Boolean);
  const frontCode = document.querySelector("[data-code-prefix]")?.textContent.trim() || "";
  const rearCode = document.querySelector("[data-code-suffix]")?.textContent.trim() || "";
  const detailTitle = document.querySelector("[data-submission-detail-title]");
  const detailProblem = document.querySelector("[data-submission-detail-problem]");
  const detailMode = document.querySelector("[data-submission-detail-mode]");
  const detailSource = document.querySelector("[data-submission-detail-source]");
  const retryButton = document.querySelector("[data-submission-retry]");

  const clearPolicyError = () => {
    if (!policyError) {
      return;
    }

    policyError.hidden = true;
    policyError.textContent = "";
  };

  const showPolicyError = (message, focusEditor = false) => {
    if (policyError) {
      policyError.textContent = message;
      policyError.hidden = false;
      policyError.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }

    if (focusEditor && sourceEditor) {
      sourceEditor.focus();
    }

    showToast(message);
  };

  const updateSubmissionDetail = (screen, mode, source) => {
    const problemId = screen?.dataset.submissionProblemId || "1024";
    const problemTitle = screen?.dataset.submissionProblemTitle || "구간 합 구하기";
    const modeLabel = mode === "blockly"
      ? "Blockly"
      : mode === "code_completion"
        ? "코드 빈칸 완성"
        : "코드";

    if (detailTitle) {
      detailTitle.textContent = problemTitle;
    }

    if (detailProblem) {
      detailProblem.textContent = problemId;
    }

    if (detailMode) {
      detailMode.textContent = modeLabel;
    }

    if (detailSource) {
      detailSource.textContent = source;
    }

    if (retryButton && screen?.dataset.screen) {
      retryButton.dataset.openScreen = screen.dataset.screen;
    }
  };

  const submitSource = (source, mode) => {
    clearPolicyError();

    if (source.trim() === "") {
      showPolicyError(mode === "blockly"
        ? "생성된 Python 코드를 확인할 수 없어 제출하지 않았습니다."
        : "제출할 코드를 입력해 주세요.", mode === "code");
      return;
    }

    const matchedPattern = forbiddenPatterns.find((pattern) => source.includes(pattern));

    if (matchedPattern) {
      showPolicyError(`제출할 수 없습니다. 금지 코드 "${matchedPattern}"이 포함되어 있습니다.`, mode === "code");
      return;
    }

    const wrapperCount = [frontCode, rearCode].filter(Boolean).length;
    showToast(`금지 코드 검사를 통과했습니다. 서버가 앞·뒤 코드 ${wrapperCount}개를 붙여 제출합니다.`);
    updateSubmissionDetail(standardSubmissionScreen, mode, source);

    if (prototypeUi && typeof prototypeUi.showScreen === "function") {
      window.setTimeout(() => prototypeUi.showScreen("submission-detail"), 240);
    }
  };

  const setSubmitMode = (mode) => {
    if (mode === "blockly" && blocklyFrame && blocklyFrame.dataset.loaded !== "true") {
      blocklyFrame.src = blocklyFrame.dataset.blocklySrc;
      blocklyFrame.dataset.loaded = "true";
    }

    modeButtons.forEach((button) => {
      button.setAttribute("aria-pressed", String(button.dataset.submitMode === mode));
    });
    modePanels.forEach((panel) => {
      panel.hidden = panel.dataset.submitPanel !== mode;
    });
    if (submissionCompose) {
      submissionCompose.classList.toggle("block-mode-active", mode === "blockly");
    }
  };

  modeButtons.forEach((button) => {
    button.addEventListener("click", () => {
      clearPolicyError();
      setSubmitMode(button.dataset.submitMode);
      showToast(button.dataset.submitMode === "blockly"
        ? "블록 편집기를 선택했습니다. Python 3이 자동 적용됩니다."
        : "코드 편집기로 전환했습니다.");
    });
  });

  if (sourceEditor) {
    sourceEditor.addEventListener("input", clearPolicyError);
  }

  if (codeSubmitButton && sourceEditor) {
    codeSubmitButton.addEventListener("click", () => submitSource(sourceEditor.value, "code"));
  }

  const completionApi = window.OJCodeCompletionPrototype;
  const completionRoot = document.querySelector("[data-code-completion-mode='student']");
  const completionScreen = completionRoot?.closest("[data-screen]");
  const completionSubmitButton = completionScreen?.querySelector("[data-submit-completion]");
  const completionPolicyError = completionScreen?.querySelector("[data-policy-error]");
  const completionForbiddenPatterns = completionScreen
    ? Array.from(completionScreen.querySelectorAll("[data-forbidden-code]"))
      .map((item) => item.textContent.trim())
      .filter(Boolean)
    : [];

  const clearCompletionError = () => {
    if (!completionPolicyError) {
      return;
    }

    completionPolicyError.hidden = true;
    completionPolicyError.textContent = "";
  };

  const showCompletionError = (message) => {
    if (completionPolicyError) {
      completionPolicyError.textContent = message;
      completionPolicyError.hidden = false;
      completionPolicyError.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }

    showToast(message);
  };

  completionRoot?.addEventListener("oj:code-completion-change", clearCompletionError);

  completionSubmitButton?.addEventListener("click", () => {
    clearCompletionError();

    if (!completionApi || !completionRoot) {
      showCompletionError("빈칸 코드 편집기를 불러오지 못했습니다.");
      return;
    }

    const missing = completionApi.getMissing(completionRoot);

    if (missing.length > 0) {
      showCompletionError(`입력하지 않은 빈칸이 ${missing.length}개 있습니다.`);
      completionApi.focusFirstMissing(completionRoot);
      return;
    }

    const forbiddenMatch = completionApi.findForbiddenOverlap(
      completionRoot,
      completionForbiddenPatterns
    );

    if (forbiddenMatch) {
      showCompletionError(`제출할 수 없습니다. 학생 입력 영역에 금지 코드 "${forbiddenMatch.pattern}"이 포함되어 있습니다.`);
      const matchedInput = Array.from(completionRoot.querySelectorAll("[data-code-completion-blank]"))
        .find((input) => forbiddenMatch.blankIds.includes(input.dataset.codeCompletionBlank));
      matchedInput?.focus();
      return;
    }

    const source = completionApi.getSource(completionRoot);
    const completionFront = completionScreen.querySelector("[data-code-prefix]")?.textContent.trim() || "";
    const completionRear = completionScreen.querySelector("[data-code-suffix]")?.textContent.trim() || "";
    const wrapperCount = [completionFront, completionRear].filter(Boolean).length;

    updateSubmissionDetail(completionScreen, "code_completion", source);
    showToast(`빈칸 2개를 검증했습니다. 서버가 앞·뒤 코드 ${wrapperCount}개를 붙여 제출합니다.`);

    if (prototypeUi && typeof prototypeUi.showScreen === "function") {
      window.setTimeout(() => prototypeUi.showScreen("submission-detail"), 240);
    }
  });

  window.addEventListener("message", (event) => {
    if (!blocklyFrame || !blocklyContract || event.source !== blocklyFrame.contentWindow) {
      return;
    }

    if (blocklyContract.isMessage(event.data, blocklyContract.types.submit)) {
      submitSource(String(event.data.generatedCode || ""), "blockly");
      return;
    }

    if (blocklyContract.isMessage(event.data, blocklyContract.types.resize)) {
      const height = Number(event.data.height);
      if (Number.isFinite(height)) {
        blocklyFrame.style.height = `${Math.min(2200, Math.max(720, height))}px`;
      }
    }
  });

  const pollButton = document.querySelector("[data-poll-demo]");
  const pollPrimary = document.querySelector("[data-poll-primary]");
  const pollCopy = document.querySelector("[data-poll-copy]");

  if (pollButton && pollPrimary && pollCopy) {
    pollButton.addEventListener("click", () => {
      pollPrimary.classList.remove("waiting");
      pollPrimary.querySelector("span").textContent = "채점 완료";
      pollPrimary.querySelector("strong").textContent = "맞았습니다";
      document.querySelectorAll("[data-poll-value]").forEach((value) => {
        value.textContent = value.dataset.pollValue;
      });
      pollCopy.textContent = "채점이 완료되어 자동 갱신을 중지했습니다.";
      pollButton.disabled = true;
      pollButton.textContent = "갱신 완료";
      showToast("제출 #58421의 결과를 갱신했습니다.");
    });
  }

  const diagnosticButton = document.querySelector("[data-run-diagnostics]");

  if (diagnosticButton) {
    diagnosticButton.addEventListener("click", () => {
      document.querySelectorAll("[data-diagnostic-state]").forEach((item) => {
        item.dataset.diagnosticState = "ok";
        const status = item.querySelector("[data-diagnostic-status]");
        if (status) {
          status.textContent = "정상";
        }
      });
      showToast("로컬 Blockly 자산과 Python 생성기 진단을 완료했습니다.");
    });
  }

  document.querySelectorAll("[data-setting-toggle]").forEach((toggle) => {
    toggle.addEventListener("change", () => {
      showToast(toggle.checked ? "설정을 사용합니다." : "설정을 사용하지 않습니다.");
    });
  });

  const initialMode = modeButtons.find((button) => button.getAttribute("aria-pressed") === "true");
  if (initialMode) {
    setSubmitMode(initialMode.dataset.submitMode);
  }
})();
