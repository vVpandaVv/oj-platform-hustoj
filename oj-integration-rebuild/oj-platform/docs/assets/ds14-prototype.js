(() => {
  "use strict";

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

  document.querySelectorAll("[data-count-input]").forEach((input) => {
    const output = document.querySelector(`[data-count-output="${input.dataset.countInput}"]`);
    const limit = Number(input.getAttribute("maxlength") || 0);

    const update = () => {
      if (output) {
        output.textContent = `${input.value.length} / ${limit.toLocaleString("ko-KR")}`;
      }
    };

    input.addEventListener("input", update);
    update();
  });

  document.querySelectorAll("[data-context-launch]").forEach((button) => {
    button.addEventListener("click", () => {
      document.querySelectorAll("[data-active-context]").forEach((target) => {
        target.textContent = button.dataset.contextLaunch;
      });
    });
  });

  document.querySelectorAll("[data-add-attachment]").forEach((button) => {
    button.addEventListener("click", () => {
      const list = document.querySelector(`[data-attachment-list="${button.dataset.addAttachment}"]`);

      if (!list || list.children.length >= 3) {
        showToast("시안에서는 첨부 3개까지만 추가해 볼 수 있습니다.");
        return;
      }

      const item = document.createElement("div");
      item.className = "attachment-item";
      item.innerHTML = "<span>알고리즘_질문.pdf · 428KB</span><button class=\"button ghost\" type=\"button\" data-remove-attachment>제거</button>";
      list.appendChild(item);
      showToast("보호 첨부 목록에 파일을 추가했습니다.");
    });
  });

  document.addEventListener("click", (event) => {
    const removeButton = event.target.closest("[data-remove-attachment]");

    if (removeButton) {
      removeButton.closest(".attachment-item")?.remove();
      showToast("첨부 목록에서 제거했습니다.");
    }
  });

  document.querySelectorAll("[data-add-comment]").forEach((button) => {
    button.addEventListener("click", () => {
      const input = document.querySelector(`[data-comment-input="${button.dataset.addComment}"]`);

      if (!input || input.value.trim().length === 0) {
        showToast("댓글 내용을 입력해 주세요.");
        input?.focus();
        return;
      }

      const list = document.querySelector(`[data-comment-list="${button.dataset.addComment}"]`);
      const item = document.createElement("article");
      item.className = "comment";
      item.innerHTML = `<div class="comment-author"><strong>김하늘</strong><span>학습자</span></div><div class="comment-body"><p></p><span class="comment-time">방금 전</span></div>`;
      item.querySelector("p").textContent = input.value.trim();
      list?.appendChild(item);
      input.value = "";
      showToast("댓글을 등록했습니다.");
    });
  });

  document.querySelectorAll("[data-notice-status]").forEach((select) => {
    const schedule = document.querySelector(`[data-schedule-fields="${select.dataset.noticeStatus}"]`);

    const update = () => {
      if (schedule) {
        schedule.hidden = select.value !== "future";
      }
    };

    select.addEventListener("change", update);
    update();
  });
})();
