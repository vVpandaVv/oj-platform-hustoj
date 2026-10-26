(() => {
  "use strict";

  const screenButtons = Array.from(document.querySelectorAll("[data-screen-target]"));
  const screens = Array.from(document.querySelectorAll("[data-screen]"));
  const toast = document.querySelector(".toast");
  let toastTimer;

  const showToast = (message) => {
    if (!toast) {
      return;
    }

    toast.textContent = message;
    toast.classList.add("show");
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.classList.remove("show"), 1800);
  };

  const showScreen = (screenId, updateHash = true) => {
    const target = screens.find((screen) => screen.dataset.screen === screenId);

    if (!target) {
      return;
    }

    screens.forEach((screen) => {
      screen.hidden = screen !== target;
    });

    screenButtons.forEach((button) => {
      button.setAttribute("aria-selected", String(button.dataset.screenTarget === screenId));
    });

    document.querySelectorAll("[data-sidebar-for]").forEach((link) => {
      const screenIds = link.dataset.sidebarFor.split(/\s+/);

      if (screenIds.includes(screenId)) {
        link.setAttribute("aria-current", "page");
      } else {
        link.removeAttribute("aria-current");
      }
    });

    if (updateHash) {
      window.history.replaceState(null, "", `#${screenId}`);
    }

    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  window.OJUIPrototype = Object.freeze({
    showScreen,
    showToast,
  });

  screenButtons.forEach((button) => {
    button.addEventListener("click", () => showScreen(button.dataset.screenTarget));
  });

  document.querySelectorAll("[data-open-screen]").forEach((button) => {
    button.addEventListener("click", () => showScreen(button.dataset.openScreen));
  });

  document.querySelectorAll("a[href^='#']").forEach((link) => {
    const screenId = link.getAttribute("href").slice(1);

    if (screens.some((screen) => screen.dataset.screen === screenId)) {
      link.addEventListener("click", (event) => {
        event.preventDefault();
        showScreen(screenId);
      });
    }
  });

  document.querySelectorAll("[data-toast]").forEach((button) => {
    button.addEventListener("click", () => showToast(button.dataset.toast));
  });

  document.querySelectorAll("[data-choice-group]").forEach((group) => {
    const choices = Array.from(group.querySelectorAll("[data-choice]"));

    choices.forEach((choice) => {
      choice.addEventListener("click", () => {
        choices.forEach((item) => item.setAttribute("aria-checked", String(item === choice)));
        showToast("답안을 임시 저장했습니다.");
      });
    });
  });

  document.querySelectorAll("[data-selection-table]").forEach((table) => {
    const checkboxes = Array.from(table.querySelectorAll("tbody input[type='checkbox']:not(:disabled)"));
    const selectAll = table.querySelector("thead input[type='checkbox']");
    const bulkBarId = table.dataset.selectionTable;
    const bulkBar = document.querySelector(`[data-bulk-bar="${bulkBarId}"]`);
    const countLabel = bulkBar ? bulkBar.querySelector("[data-selection-count]") : null;

    const refreshSelection = () => {
      const count = checkboxes.filter((checkbox) => checkbox.checked).length;

      if (bulkBar) {
        bulkBar.hidden = count === 0;
      }

      if (countLabel) {
        countLabel.textContent = `${count}개 선택`;
      }

      if (selectAll) {
        selectAll.checked = count > 0 && count === checkboxes.length;
        selectAll.indeterminate = count > 0 && count < checkboxes.length;
      }
    };

    checkboxes.forEach((checkbox) => checkbox.addEventListener("change", refreshSelection));

    if (selectAll) {
      selectAll.addEventListener("change", () => {
        checkboxes.forEach((checkbox) => {
          checkbox.checked = selectAll.checked;
        });
        refreshSelection();
      });
    }
  });

  const protectionToggle = document.querySelector("[data-protection-toggle]");
  const protectionCopy = document.querySelector("[data-protection-copy]");
  const reapplyOptions = document.querySelector("[data-reapply-options]");

  if (protectionToggle && protectionCopy && reapplyOptions) {
    const refreshProtection = (notify = false) => {
      const protectedMode = protectionToggle.checked;
      reapplyOptions.hidden = protectedMode;
      protectionToggle.setAttribute("aria-expanded", String(!protectedMode));
      protectionCopy.textContent = protectedMode
        ? "기존 진행 중 응시와 완료 결과는 해당 게시 버전을 계속 사용합니다."
        : "새 버전을 만든 뒤 선택한 대상에 Jobs로 재적용합니다. 기존 버전과 결과 원본은 유지됩니다.";

      if (notify) {
        showToast(protectedMode ? "게시 버전 보호를 사용합니다." : "일괄 재적용 범위를 선택해 주세요.");
      }
    };

    protectionToggle.addEventListener("change", () => refreshProtection(true));
    refreshProtection();
  }

  document.querySelectorAll("[data-publish-action]").forEach((button) => {
    button.addEventListener("click", () => {
      if (!protectionToggle || protectionToggle.checked) {
        showToast("새 게시 버전을 만들고 신규 응시에 적용합니다.");
        return;
      }

      const selectedScopes = document.querySelectorAll("[data-reapply-scope]:checked").length;
      showToast(selectedScopes > 0
        ? "새 버전 게시와 선택 대상 재적용 작업을 등록합니다."
        : "재적용할 대상을 하나 이상 선택해 주세요.");
    });
  });

  const createTopicChip = (name, area, selection) => {
    const chip = document.createElement("span");
    const path = document.createElement("span");
    const areaLabel = document.createElement("small");
    const nameLabel = document.createElement("strong");
    const primary = !selection.querySelector(".topic-chip");
    const removeButton = document.createElement("button");

    chip.className = `topic-chip${primary ? " primary" : ""}`;
    chip.dataset.topicChip = name;
    path.className = "topic-chip-path";
    areaLabel.textContent = area;
    nameLabel.textContent = name;
    path.append(areaLabel, nameLabel);
    chip.appendChild(path);

    if (primary) {
      const role = document.createElement("span");
      role.className = "topic-chip-role";
      role.textContent = "대표";
      chip.appendChild(role);
    }

    removeButton.type = "button";
    removeButton.dataset.topicChipRemove = "";
    removeButton.setAttribute("aria-label", `${name} 주제 제거`);
    removeButton.title = `${name} 주제 제거`;
    removeButton.textContent = "×";
    chip.appendChild(removeButton);
    selection.appendChild(chip);
  };

  document.querySelectorAll("[data-topic-browser]").forEach((browser) => {
    const areaButtons = Array.from(browser.querySelectorAll("[data-topic-area]"));
    const detailPanels = Array.from(browser.querySelectorAll("[data-topic-detail-panel]"));
    const selection = browser.querySelector(".topic-selection");

    areaButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const area = button.dataset.topicArea;

        areaButtons.forEach((item) => item.setAttribute("aria-selected", String(item === button)));
        detailPanels.forEach((panel) => {
          panel.hidden = panel.dataset.topicDetailPanel !== area;
        });
      });
    });

    browser.querySelectorAll("[data-topic-choice]").forEach((button) => {
      button.addEventListener("click", () => {
        const name = button.dataset.topicChoice || "";
        const area = button.dataset.topicAreaLabel || "";
        const existing = Array.from(selection?.querySelectorAll("[data-topic-chip]") || [])
          .some((chip) => chip.dataset.topicChip === name);

        if (!selection || name === "") {
          return;
        }

        if (existing) {
          showToast("이미 선택한 세부 주제입니다.");
          return;
        }

        if (selection.querySelectorAll(".topic-chip").length >= 3) {
          showToast("세부 주제는 최대 3개까지 선택할 수 있습니다.");
          return;
        }

        createTopicChip(name, area, selection);
        showToast(`${area} 분야의 ${name} 주제를 선택했습니다.`);
      });
    });
  });

  document.querySelectorAll("[data-topic-create]").forEach((button) => {
    button.addEventListener("click", () => {
      const keyInput = document.querySelector("[data-topic-key]");
      const nameInput = document.querySelector("[data-topic-name]");
      const parentInput = document.querySelector("[data-topic-parent]");
      const aliasesInput = document.querySelector("[data-topic-aliases]");
      const topicCount = document.querySelector("[data-topic-count]");
      const key = keyInput?.value.trim() || "";
      const name = nameInput?.value.trim() || "";
      const parent = parentInput?.value || "";
      const aliases = aliasesInput?.value.trim() || "-";

      if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(key)) {
        showToast("영문 소문자·숫자·하이픈으로 세부 주제 키를 입력해 주세요.");
        keyInput?.focus();
        return;
      }

      if (name.length === 0) {
        showToast("세부 주제 이름을 입력해 주세요.");
        nameInput?.focus();
        return;
      }

      if (document.querySelector(`[data-topic-row="${key}"]`)) {
        showToast("이미 등록된 세부 주제 키입니다.");
        return;
      }

      const group = Array.from(document.querySelectorAll("[data-topic-group-name]"))
        .find((item) => item.dataset.topicGroupName === parent);
      const children = group?.querySelector("[data-topic-children]");

      if (!children) {
        showToast("세부 주제를 추가할 분야를 선택해 주세요.");
        return;
      }

      const item = document.createElement("article");
      const branch = document.createElement("span");
      const detail = document.createElement("div");
      const title = document.createElement("strong");
      const metadata = document.createElement("small");
      const keyLabel = document.createElement("span");
      const count = document.createElement("span");
      const status = document.createElement("span");

      item.className = "topic-tree-item";
      item.dataset.topicRow = key;
      branch.className = "topic-branch";
      branch.setAttribute("aria-hidden", "true");
      title.textContent = name;
      keyLabel.className = "topic-key";
      keyLabel.textContent = key;
      metadata.append(keyLabel, document.createTextNode(` · AI 별칭: ${aliases}`));
      detail.append(title, metadata);
      count.textContent = "0문제";
      status.className = "badge good";
      status.textContent = "활성";
      item.append(branch, detail, count, status);
      children.appendChild(item);

      if (topicCount) {
        const current = Number.parseInt(topicCount.textContent, 10) || 0;
        topicCount.textContent = `${current + 1}개`;
      }

      [keyInput, nameInput, aliasesInput].forEach((input) => {
        if (input) {
          input.value = "";
        }
      });
      showToast(`${parent} 분야에 ${name} 세부 주제를 추가했습니다.`);
    });
  });

  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-topic-chip-remove]");

    if (!button) {
      return;
    }

    const selection = button.closest(".topic-selection");
    const chip = button.closest(".topic-chip");
    const wasPrimary = chip?.classList.contains("primary");
    chip?.remove();

    if (wasPrimary) {
      const nextChip = selection?.querySelector(".topic-chip");
      nextChip?.classList.add("primary");

      if (nextChip && !nextChip.querySelector(".topic-chip-role")) {
        const label = document.createElement("span");
        label.className = "topic-chip-role";
        label.textContent = "대표";
        nextChip.insertBefore(label, nextChip.querySelector("button"));
      }
    }

    showToast("선택한 세부 주제를 제거했습니다.");
  });

  const topicParent = document.querySelector("[data-topic-parent]");
  const topicName = document.querySelector("[data-topic-name]");
  const topicPreview = document.querySelector("[data-topic-preview]");

  const refreshTopicPreview = () => {
    if (!topicPreview) {
      return;
    }

    topicPreview.textContent = `${topicParent?.value || "분야"} > ${topicName?.value.trim() || "세부 주제 이름"}`;
  };

  topicParent?.addEventListener("change", refreshTopicPreview);
  topicName?.addEventListener("input", refreshTopicPreview);

  const fileEditor = document.querySelector("[data-file-editor]");
  const editorName = document.querySelector("[data-editor-name]");

  document.querySelectorAll("[data-file-edit]").forEach((button) => {
    button.addEventListener("click", () => {
      if (fileEditor) {
        fileEditor.value = button.dataset.fileContent || "";
        fileEditor.focus();
      }

      if (editorName) {
        editorName.textContent = button.dataset.fileEdit;
      }

      showToast(`${button.dataset.fileEdit} 파일을 편집기에 열었습니다.`);
    });
  });

  const archiveDialog = document.querySelector("[data-archive-dialog]");

  const setArchiveDialog = (open) => {
    if (!archiveDialog) {
      return;
    }

    archiveDialog.hidden = !open;
    document.body.style.overflow = open ? "hidden" : "";

    if (open) {
      archiveDialog.querySelector("[data-archive-close]")?.focus();
    }
  };

  document.querySelectorAll("[data-archive-preview]").forEach((button) => {
    button.addEventListener("click", () => setArchiveDialog(true));
  });

  document.querySelectorAll("[data-archive-close]").forEach((button) => {
    button.addEventListener("click", () => setArchiveDialog(false));
  });

  archiveDialog?.addEventListener("click", (event) => {
    if (event.target === archiveDialog) {
      setArchiveDialog(false);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && archiveDialog && !archiveDialog.hidden) {
      setArchiveDialog(false);
    }
  });

  const renumberTabs = Array.from(document.querySelectorAll("[data-renumber-tab]"));
  const renumberPanels = Array.from(document.querySelectorAll("[data-renumber-panel]"));

  renumberTabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      const mode = tab.dataset.renumberTab;

      renumberTabs.forEach((item) => {
        item.setAttribute("aria-selected", String(item === tab));
      });

      renumberPanels.forEach((panel) => {
        panel.hidden = panel.dataset.renumberPanel !== mode;
      });
    });
  });

  const initialScreen = window.location.hash.slice(1);
  showScreen(initialScreen || screens[0]?.dataset.screen, false);
})();
