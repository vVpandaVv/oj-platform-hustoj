(() => {
  "use strict";

  document.querySelectorAll("[data-learner-progress-group]").forEach((group) => {
    const buttons = Array.from(group.querySelectorAll("[data-learner-progress-target]"));
    const details = Array.from(group.querySelectorAll("[data-learner-progress-detail]"));

    const showLearner = (learnerId) => {
      buttons.forEach((button) => {
        button.setAttribute("aria-pressed", String(button.dataset.learnerProgressTarget === learnerId));
      });

      details.forEach((detail) => {
        detail.hidden = detail.dataset.learnerProgressDetail !== learnerId;
      });
    };

    buttons.forEach((button) => {
      button.addEventListener("click", () => {
        showLearner(button.dataset.learnerProgressTarget);
      });
    });

    showLearner(buttons.find((button) => button.getAttribute("aria-pressed") === "true")?.dataset.learnerProgressTarget
      || buttons[0]?.dataset.learnerProgressTarget
      || "");
  });

  document.querySelectorAll("[data-contest-phase-group]").forEach((group) => {
    const buttons = Array.from(group.querySelectorAll("[data-contest-phase-target]"));
    const details = Array.from(group.querySelectorAll("[data-contest-phase-detail]"));

    const showPhase = (phase) => {
      buttons.forEach((button) => {
        button.setAttribute("aria-pressed", String(button.dataset.contestPhaseTarget === phase));
      });

      details.forEach((detail) => {
        detail.hidden = detail.dataset.contestPhaseDetail !== phase;
      });
    };

    buttons.forEach((button) => {
      button.addEventListener("click", () => {
        showPhase(button.dataset.contestPhaseTarget);
      });
    });

    showPhase(buttons.find((button) => button.getAttribute("aria-pressed") === "true")?.dataset.contestPhaseTarget
      || buttons[0]?.dataset.contestPhaseTarget
      || "");
  });

  document.querySelectorAll("[data-scoring-policy-group]").forEach((group) => {
    const select = group.querySelector("[data-scoring-policy-select]");
    const panels = Array.from(group.querySelectorAll("[data-scoring-policy-panel]"));
    const summary = group.querySelector("[data-scoring-policy-summary]");
    const partialScoreSelect = group.querySelector("[data-partial-score-select]");
    const scoreInputs = Array.from(group.querySelectorAll(".score-field input"));
    const summaries = {
      standard_score_v1: "일반 점수형 · 정확성 90% · 시간 10% · 오답 1회 -5점",
      icpc_v1: "ICPC · 해결 수 우선 · 오답당 벌점 1,200초",
      oi_v1: "OI · 최고 통과율 점수 · 완전 해결 수 · 벌점 순",
    };

    if (!select) {
      return;
    }

    const showPolicy = (policy) => {
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.scoringPolicyPanel !== policy;
      });

      if (summary) {
        const partialScoreEnabled = partialScoreSelect?.value === "pass_rate_correctness_base_v1";
        summary.textContent = policy === "standard_score_v1" && partialScoreEnabled
          ? "일반 점수형 · 통과율 × 정답 기본 점수 · 시간 10% · 오답 1회 -5점"
          : summaries[policy] || "";
      }

      scoreInputs.forEach((input) => {
        const disabled = policy === "icpc_v1";
        input.disabled = disabled;
        input.closest(".score-field")?.classList.toggle("is-disabled", disabled);
      });
    };

    select.addEventListener("change", () => {
      showPolicy(select.value);
    });
    partialScoreSelect?.addEventListener("change", () => {
      showPolicy(select.value);
    });
    showPolicy(select.value);
  });

  document.querySelectorAll("[data-sort-matrix]").forEach((matrix) => {
    const select = matrix.querySelector("[data-sort-select]");
    const body = matrix.querySelector("tbody");
    const status = matrix.querySelector("[data-sort-status]");

    if (!select || !body) {
      return;
    }

    const rows = Array.from(body.querySelectorAll("tr"));
    const nameSort = (first, second) => (first.dataset.name || "").localeCompare(
      second.dataset.name || "",
      "ko"
    );
    const numericSort = (key, direction) => (first, second) => {
      const firstValue = Number(first.dataset[key] || 0);
      const secondValue = Number(second.dataset[key] || 0);
      const difference = direction === "asc" ? firstValue - secondValue : secondValue - firstValue;

      if (difference !== 0) {
        return difference;
      }

      const rankDifference = Number(first.dataset.rank || 0) - Number(second.dataset.rank || 0);
      return rankDifference || nameSort(first, second);
    };
    const sorters = {
      "rank-asc": numericSort("rank", "asc"),
      "score-desc": numericSort("score", "desc"),
      "score-asc": numericSort("score", "asc"),
      "progress-desc": numericSort("progress", "desc"),
      "progress-asc": numericSort("progress", "asc"),
      "name-asc": nameSort,
    };

    const sortRows = () => {
      const sorter = sorters[select.value] || nameSort;
      rows.sort(sorter).forEach((row) => body.appendChild(row));

      if (status) {
        status.textContent = `${select.options[select.selectedIndex].text}으로 정렬했습니다.`;
      }
    };

    select.addEventListener("change", sortRows);
    sortRows();
  });
})();
