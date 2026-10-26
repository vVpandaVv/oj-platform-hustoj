import "./style.css";
import { mountPublicInteractiveBlocks } from "./interactive-blocks-public";
import { mountPublicVisualizations } from "./visualization-public";

function markActiveTopicNavLink() {
  const currentPath = window.location.pathname.replace(/\/+$/, "");

  document
    .querySelectorAll<HTMLAnchorElement>(".contents-editor-topic-nav__lessons a")
    .forEach((link) => {
      const isActive = link.pathname.replace(/\/+$/, "") === currentPath;

      link.classList.toggle("is-active", isActive);

      if (isActive) {
        link.setAttribute("aria-current", "page");
      } else {
        link.removeAttribute("aria-current");
      }
    });
}

function mountPublicLessonContent() {
  mountPublicInteractiveBlocks();
  mountPublicVisualizations();
  markActiveTopicNavLink();
}

mountPublicLessonContent();

document.addEventListener("DOMContentLoaded", () => {
  mountPublicLessonContent();
});

document.addEventListener("click", (event) => {
  const target = event.target;

  if (!(target instanceof Element)) {
    return;
  }

  const topicMenuButton = target.closest<HTMLButtonElement>(
    "[data-contents-editor-topic-menu-button]",
  );

  if (topicMenuButton) {
    const topic = topicMenuButton.closest<HTMLElement>(
      "[data-contents-editor-topic-menu-open]",
    );
    const isOpen =
      topic?.dataset.contentsEditorTopicMenuOpen === "true";
    const nextOpen = !isOpen;
    const buttonLabel = topicMenuButton.querySelector<HTMLElement>("span");
    const buttonIcon = topicMenuButton.querySelector<HTMLElement>(
      ".contents-editor-topic-menu-button__icon",
    );

    topic?.setAttribute(
      "data-contents-editor-topic-menu-open",
      nextOpen ? "true" : "false",
    );
    topicMenuButton.setAttribute("aria-expanded", nextOpen ? "true" : "false");

    if (buttonLabel) {
      buttonLabel.textContent = nextOpen ? "목차 닫기" : "목차 보기";
    }

    if (buttonIcon) {
      buttonIcon.textContent = nextOpen ? "-" : "+";
    }

    return;
  }

  const branchToggle = target.closest<HTMLButtonElement>(
    "[data-contents-editor-topic-branch-toggle]",
  );

  if (branchToggle) {
    const controlsId = branchToggle.getAttribute("aria-controls");
    const controlledList = controlsId
      ? document.getElementById(controlsId)
      : null;
    const isOpen = branchToggle.getAttribute("aria-expanded") === "true";
    const nextOpen = !isOpen;
    const icon = branchToggle.querySelector<HTMLElement>(
      ".contents-editor-topic-nav__toggle-icon",
    );

    branchToggle.setAttribute("aria-expanded", nextOpen ? "true" : "false");

    if (controlledList) {
      controlledList.toggleAttribute("hidden", !nextOpen);
    }

    if (icon) {
      icon.textContent = nextOpen ? "-" : "+";
    }

    return;
  }

  const checkButton = target.closest("[data-contents-editor-quiz-check]");

  if (!checkButton) {
    return;
  }

  const quiz = checkButton.closest<HTMLElement>("[data-contents-editor-quiz]");

  if (!quiz) {
    return;
  }

  const selected = quiz.querySelector<HTMLInputElement>(
    'input[type="radio"]:checked',
  );
  const result = quiz.querySelector<HTMLElement>(
    "[data-contents-editor-quiz-result]",
  );
  const explanation = quiz.querySelector<HTMLElement>(
    "[data-contents-editor-quiz-explanation]",
  );

  if (!result) {
    return;
  }

  result.classList.remove("is-correct", "is-incorrect");

  if (!selected) {
    result.textContent = "먼저 답안을 선택하세요.";
    explanation?.setAttribute("hidden", "");
    return;
  }

  const correctChoice = quiz.dataset.correctChoice ?? "";
  const isCorrect = selected.value === correctChoice;

  result.textContent = isCorrect
    ? quiz.dataset.feedbackCorrect || "정답입니다."
    : quiz.dataset.feedbackIncorrect || "다시 생각해 보세요.";
  result.classList.add(isCorrect ? "is-correct" : "is-incorrect");
  explanation?.removeAttribute("hidden");
});
