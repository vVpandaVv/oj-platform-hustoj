(() => {
  "use strict";

  const recommendationSets = [
    [
      ["1042", "문자열 압축", "최근 덜 다룬 문자열 주제"],
      ["1057", "최단 경로 입문", "그래프에서 다음 단계 도전"],
      ["1038", "두 포인터 합", "최근 미해결 문제와 비슷한 유형"],
      ["1061", "괄호 검사", "자료구조 주제 보완"]
    ],
    [
      ["1027", "빈도수 세기", "딕셔너리 활용 연습"],
      ["1049", "격자 이동", "탐색 주제 확장"],
      ["1034", "부분 수열", "난이도 3 문제에 다시 도전"],
      ["1064", "작업 순서", "그래프와 큐를 함께 연습"]
    ]
  ];

  document.querySelectorAll("[data-refresh-recommendations]").forEach((button) => {
    let setIndex = 0;
    const status = document.querySelector("[data-recommendation-status]");

    button.addEventListener("click", () => {
      setIndex = (setIndex + 1) % recommendationSets.length;
      const cards = Array.from(document.querySelectorAll("[data-rotating-recommendation]"));

      cards.forEach((card, index) => {
        const item = recommendationSets[setIndex][index];
        card.querySelector("[data-problem-number]").textContent = item[0];
        card.querySelector("[data-problem-title]").textContent = item[1];
        card.querySelector("[data-problem-reason]").textContent = item[2];
      });

      if (status) {
        status.textContent = "고정 추천 2개는 유지하고 오늘의 다른 추천 4개를 표시했습니다.";
      }
    });
  });

  document.querySelectorAll("[data-ranking-visibility]").forEach((select) => {
    const status = document.querySelector("[data-ranking-status]");

    select.addEventListener("change", () => {
      if (status) {
        status.textContent = select.value === "members"
          ? "전체 랭킹에 표시 이름으로 참여합니다."
          : "전체 랭킹에서 내 행을 숨깁니다.";
      }
    });
  });

  document.querySelectorAll("[data-feedback-action]").forEach((button) => {
    const panel = document.querySelector("[data-feedback-panel]");
    const state = document.querySelector("[data-feedback-state]");
    const detail = document.querySelector("[data-feedback-detail]");

    button.addEventListener("click", () => {
      if (!panel || !state || !detail) {
        return;
      }

      state.textContent = "생성 중";
      state.className = "badge wait";
      detail.textContent = "채점 결과와 최신 학습 분석을 확인하고 있습니다. 이 화면에서 자동으로 갱신됩니다.";
      button.disabled = true;
      button.textContent = "처리 중";

      window.setTimeout(() => {
        state.textContent = "완료";
        state.className = "badge good";
        detail.textContent = "결과 이해, 단계별 힌트와 다시 확인할 점을 준비했습니다.";
        panel.hidden = false;
        button.disabled = false;
        button.textContent = "다시 생성";
      }, 900);
    });
  });
})();
