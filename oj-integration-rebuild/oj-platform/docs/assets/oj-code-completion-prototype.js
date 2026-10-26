(() => {
  "use strict";

  const examples = Object.freeze({
    "prefix-sum-v1": Object.freeze({
      version: "code-completion-v1",
      templateHash: "sha256:prefix-sum-v1",
      language: "python3",
      lines: [
        [{ type: "code", text: "n, m = map(int, input().split())" }],
        [{ type: "code", text: "numbers = list(map(int, input().split()))" }],
        [],
        [{ type: "code", text: "prefix = [0]" }],
        [{ type: "code", text: "for number in numbers:" }],
        [
          { type: "code", text: "    prefix.append(prefix[-1] " },
          {
            type: "blank",
            id: "accumulate-operator",
            label: "누적 연산",
            placeholder: "연산자",
            maxLength: 8,
            width: 9,
          },
          { type: "code", text: " number)" },
        ],
        [],
        [{ type: "code", text: "for _ in range(m):" }],
        [{ type: "code", text: "    left, right = map(int, input().split())" }],
        [
          { type: "code", text: "    print(" },
          {
            type: "blank",
            id: "range-expression",
            label: "구간 합 식",
            placeholder: "식을 입력",
            maxLength: 80,
            width: 28,
          },
          { type: "code", text: ")" },
        ],
      ],
    }),
  });

  const initialized = new WeakMap();

  const inputElements = (root) => Array.from(root.querySelectorAll("[data-code-completion-blank]"));

  const updateState = (root) => {
    const inputs = inputElements(root);
    const filled = inputs.filter((input) => input.value.trim() !== "").length;
    const preview = root.dataset.codeCompletionMode === "preview";

    inputs.forEach((input) => {
      input.classList.toggle("is-filled", input.value.trim() !== "");
      input.setAttribute("aria-invalid", String(!preview && input.value.trim() === ""));
    });

    const progress = root.querySelector("[data-code-completion-progress]");

    if (progress) {
      progress.textContent = preview
        ? `${inputs.length}개 빈칸 · 학생 입력 영역`
        : `${filled}/${inputs.length}개 완료`;
    }

    root.dispatchEvent(new CustomEvent("oj:code-completion-change", {
      bubbles: true,
      detail: {
        filled,
        total: inputs.length,
      },
    }));
  };

  const createCodeSegment = (text) => {
    const segment = document.createElement("span");
    segment.className = "code-completion-text";
    segment.dataset.codeSegment = "text";
    segment.textContent = text;
    return segment;
  };

  const createBlankSegment = (blank, preview) => {
    const input = document.createElement("input");
    input.type = "text";
    input.className = "code-completion-blank";
    input.dataset.codeSegment = "blank";
    input.dataset.codeCompletionBlank = blank.id;
    input.setAttribute("aria-label", blank.label);
    input.setAttribute("autocomplete", "off");
    input.setAttribute("spellcheck", "false");
    input.maxLength = Number(blank.maxLength) || 120;
    input.placeholder = preview ? blank.label : blank.placeholder;
    input.style.setProperty("--blank-ch", String(Math.max(6, Number(blank.width) || 12)));

    if (preview) {
      input.readOnly = true;
      input.tabIndex = -1;
      input.setAttribute("aria-readonly", "true");
    }

    return input;
  };

  const initialize = (root) => {
    if (!root || initialized.has(root)) {
      return initialized.get(root) || null;
    }

    const schema = examples[root.dataset.codeCompletionExample];
    const canvas = root.querySelector("[data-code-completion-canvas]");

    if (!schema || !canvas) {
      return null;
    }

    const preview = root.dataset.codeCompletionMode === "preview";
    canvas.textContent = "";

    schema.lines.forEach((segments, index) => {
      const line = document.createElement("div");
      line.className = "code-completion-line";
      line.dataset.codeCompletionLine = String(index + 1);

      const lineNumber = document.createElement("span");
      lineNumber.className = "code-completion-line-number";
      lineNumber.setAttribute("aria-hidden", "true");
      lineNumber.textContent = String(index + 1);

      const content = document.createElement("span");
      content.className = "code-completion-line-content";

      segments.forEach((segment) => {
        content.appendChild(segment.type === "blank"
          ? createBlankSegment(segment, preview)
          : createCodeSegment(segment.text || ""));
      });

      line.append(lineNumber, content);
      canvas.appendChild(line);
    });

    root.dataset.templateHash = schema.templateHash;
    root.dataset.language = schema.language;

    inputElements(root).forEach((input) => {
      input.addEventListener("input", () => updateState(root));
    });

    initialized.set(root, schema);
    updateState(root);
    return schema;
  };

  const schemaFor = (root) => initialized.get(root) || initialize(root);

  const composeSource = (root) => {
    if (!schemaFor(root)) {
      return { source: "", studentRanges: [] };
    }

    let source = "";
    const studentRanges = [];

    Array.from(root.querySelectorAll("[data-code-completion-line]")).forEach((line, lineIndex) => {
      if (lineIndex > 0) {
        source += "\n";
      }

      Array.from(line.querySelectorAll("[data-code-segment]")).forEach((segment) => {
        if (segment.dataset.codeSegment !== "blank") {
          source += segment.textContent;
          return;
        }

        const start = source.length;
        source += segment.value;
        studentRanges.push({
          id: segment.dataset.codeCompletionBlank,
          start,
          end: source.length,
        });
      });
    });

    return { source, studentRanges };
  };

  const getSource = (root) => composeSource(root).source;

  const getAnswers = (root) => Object.fromEntries(
    inputElements(root).map((input) => [input.dataset.codeCompletionBlank, input.value])
  );

  const getStudentFragments = (root) => inputElements(root).map((input) => input.value);

  const findForbiddenOverlap = (root, patterns) => {
    const { source, studentRanges } = composeSource(root);

    for (const pattern of patterns) {
      let matchStart = source.indexOf(pattern);

      while (pattern && matchStart !== -1) {
        const matchEnd = matchStart + pattern.length;
        const overlappingRanges = studentRanges.filter((range) =>
          matchStart < range.end && matchEnd > range.start
        );

        if (overlappingRanges.length > 0) {
          return {
            pattern,
            start: matchStart,
            end: matchEnd,
            blankIds: overlappingRanges.map((range) => range.id),
          };
        }

        matchStart = source.indexOf(pattern, matchStart + 1);
      }
    }

    return null;
  };

  const getMissing = (root) => inputElements(root).filter((input) => input.value.trim() === "");

  const focusFirstMissing = (root) => {
    const missing = getMissing(root);

    if (missing.length > 0) {
      missing[0].focus();
    }
  };

  const roots = Array.from(document.querySelectorAll("[data-code-completion]"));
  roots.forEach(initialize);

  window.OJCodeCompletionPrototype = Object.freeze({
    initialize,
    getAnswers,
    getMissing,
    getSource,
    getStudentFragments,
    findForbiddenOverlap,
    focusFirstMissing,
  });
})();
