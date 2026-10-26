# AI 채점, 피드백, 특기사항 구현 기준

문서 상태: 확정
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: assessment-scoring -->
<!-- HARNESS: feedback-special-note -->
<!-- HARNESS: assessment-plan-rubric-mapping -->
<!-- HARNESS: assessment-review-workbench -->
<!-- HARNESS: regrade-change-review -->

## 1. 채점 버튼

`채점` 버튼은 점수와 채점 검토의견만 만든다.

- 채점 입력 패키지는 질문, 학생 답변, 배점, 정답, 예시답안, 루브릭, 첨부 요약만 포함한다.
- 성취기준 본문과 교육과정 설명은 채점 입력에 포함하지 않는다.
- 루브릭은 snapshot으로 저장한다.
- grading fingerprint는 제출물, 문항, 답변, 첨부 요약, 루브릭 hash, 채점 정책, 모델 키를 기준으로 만든다.
- 같은 fingerprint의 결과가 있으면 기존 결과를 재사용한다.
- 총점은 서버에서 문항별 점수를 합산해 계산한다.
- 점수가 허용 범위를 벗어나면 정규화하고 검토 필요 상태로 둔다.

## 2. 재채점 변경 검토

accepted 결과가 있는 제출물을 다시 채점할 때 점수 변경이 있으면 즉시 공식 점수로 반영하지 않는다.

- 점수 변경 결과는 `change_pending`으로 저장한다.
- `regrade_reviews`에 기존 결과와 제안 결과를 연결한다.
- 교사가 반영하면 제안 결과를 `accepted`로 변경한다.
- 교사가 반영하지 않으면 제안 결과를 `rejected`로 변경하고 기존 accepted 결과를 유지한다.
- 점수가 같으면 기존 accepted 결과를 유지한다.

## 3. 피드백 버튼

`피드백` 버튼은 accepted 채점 결과만 공식 입력으로 사용한다.

- accepted 결과가 없으면 피드백을 생성하지 않는다.
- 생성 직후 상태는 `pending_approval`이며 학생에게 보이지 않는다.
- 승인하면 `published` 상태가 되고 학생 공개 대상이 된다.
- 반려는 `rejected`, 공개 취소는 `unpublished` 상태로 저장한다.
- 학생 공개 여부는 `published` 상태만 참으로 계산한다.

## 4. 특기사항 버튼

`특기사항` 버튼은 accepted 채점 결과, published 피드백, 성취기준, 교육과정 정보를 사용한다.

- accepted 결과가 없으면 생성하지 않는다.
- published 피드백이 없으면 생성하지 않는다.
- 성취기준 본문과 교육과정 설명은 특기사항 컨텍스트에 포함한다.
- context hash는 학생, 활동지, accepted 결과, published 피드백, 성취기준, 교육과정, 특기사항 프롬프트 버전으로 만든다.
- 같은 context hash가 있으면 기존 특기사항을 재사용한다.

## 5. REST 범위

- `GET /oj-ai/v1/assessment-plans`
- `POST /oj-ai/v1/assessment-plans`
- `GET /oj-ai/v1/assessment-plans/{id}`
- `POST /oj-ai/v1/assessment-plans/{id}/preflight`
- `POST /oj-ai/v1/assessment-plans/{id}/archive`
- `POST /oj-ai/v1/assessment-plans/{id}/achievement-links`
- `POST /oj-ai/v1/assessments/{submission_id}/score`
- `POST /oj-ai/v1/assessment-results/{id}/accept`
- `POST /oj-ai/v1/assessment-results/{id}/reject`
- `POST /oj-ai/v1/regrade-reviews/{id}/accept`
- `POST /oj-ai/v1/regrade-reviews/{id}/reject`
- `POST /oj-ai/v1/assessments/{submission_id}/feedback`
- `POST /oj-ai/v1/feedback/{id}/approve`
- `POST /oj-ai/v1/feedback/{id}/reject`
- `POST /oj-ai/v1/feedback/{id}/unpublish`
- `POST /oj-ai/v1/assessments/{submission_id}/special-note`

## 6. 평가 계획

- 평가 계획은 과제, 활동지 템플릿, 학년도, 학년, 반, 모델, 채점 정책을 함께 저장한다.
- 루브릭은 활동지 전체 또는 문항별 중 하나만 저장한다.
- 활동지 단위 성취기준과 문항별 성취기준을 평가 계획 화면에서 연결한다.
- 학생 매핑, 대상, 루브릭, 성취기준이 준비되어야 채점 전 상태가 `준비 완료`가 된다.
- 계획 저장, 사전 점검, 성취기준 저장, 학생 매핑 조회는 AI 채점 화면에서 수행한다.
- 루브릭 항목과 성취기준은 목록/버튼으로 추가할 수 있고, JSON 입력은 보정용으로 남긴다.

## 7. 채점 검토 화면

- AI 채점 화면에는 평가 계획 아래에 채점 검토 영역을 둔다.
- 제출 조회는 평가 계획의 과제, 학년도, 학년, 반, 제출 상태, 학생 ID를 기준으로 수행한다.
- 제출 목록은 학생, 제출 상태, 저장된 accepted 점수, 저장된 피드백 상태, 열기/채점/피드백/특기사항 버튼을 제공한다.
- 제출 상세는 질문, 학생 답변, 정답, 예시, 루브릭, 첨부를 같은 문항 영역에서 보여준다.
- 제출 상세의 루브릭 점수표는 등급/배점을 클릭해 해당 학생의 점수를 선택할 수 있고, `점수 기록`은 선택 점수를 `manual_items`로 `/assessments/{submission_id}/score`에 저장한다.
- `manual_items` 점수는 기존 채점 결과와 같은 검토/승인/변경 검토 절차를 따른다.
- `채점` 버튼은 `/assessments/{submission_id}/score`만 호출해 점수와 검토 의견을 만든다.
- `피드백` 버튼은 accepted 채점 결과가 있을 때 `/assessments/{submission_id}/feedback`만 호출한다.
- `특기사항` 버튼은 accepted 채점 결과와 published 피드백이 있을 때 `/assessments/{submission_id}/special-note`만 호출한다.
- 학생별 단일 실행, 체크한 학생 일괄 실행, 현재 목록의 반 전체 채점을 지원한다.
- 피드백은 승인 후 공개하고 공개 취소할 수 있다.

## 8. 재채점 변경 검토

- accepted 결과가 있는 제출물을 다시 채점해 점수가 달라지면 새 결과는 `change_pending`으로 저장한다.
- 기존 accepted 결과와 변경안은 재채점 검토 행으로 연결한다.
- 제출 목록과 제출 상세는 pending 재채점 검토가 있으면 `변경 검토` 버튼을 제공한다.
- 변경 검토 화면은 기존 점수, 변경안, 문항별 점수 차이를 함께 보여준다.
- `변경 반영` 버튼은 `/regrade-reviews/{id}/accept`만 호출하고, 변경안을 accepted 결과로 만든다.
- `미반영` 버튼은 `/regrade-reviews/{id}/reject`만 호출하고, 기존 accepted 결과를 유지한다.
- pending 재채점 검토가 있는 제출물은 피드백과 특기사항을 만들기 전에 변경 검토를 먼저 완료해야 한다.
## 9. 2026-08-09 활동지 자체 평가 분리

이 문서는 학생 제출물 채점, 피드백, 특기사항 생성을 다룬다. 활동지 자체 AI 평가는 제출물 채점과 분리된 1급 기능으로 설계하며, 최신 기준은 `docs/ai-assessment/redesign.html`를 따른다.

활동지 자체 평가는 Plate 원문에서 생성한 `teacher_schema`, `grading_schema`, 성취기준, 대상 학년/반 정보, OJ 문제 참조를 입력으로 사용한다. 학생 답안은 포함하지 않는다.

제출물 채점 결과는 `oj_ai_assessment_results`에 저장하고, 활동지 자체 평가 결과는 `oj_ai_worksheet_evaluations` 계열 테이블에 저장한다.
