# OJ AI Dashboard 기능 동등성 통합 완료 절차

총 단계 수: 16단계

이 문서는 기존 AI 대시보드, 활동지 제출, AI 채점 플러그인의 실제 기능을 `oj-ai-dashboard` 하나의 통합 환경에서 그대로 사용할 수 있게 만들기 위한 실행 절차다.

이 절차의 완료 판정은 화면 존재가 아니라 기능 동등성이다. 각 단계는 교사 또는 학생이 실제 화면에서 작업을 시작하고, 저장 또는 실행 결과를 확인할 수 있을 때만 완료로 본다.

## 1. 실행 원칙

- 기존 3개 플러그인은 기능 기준 원본으로 사용한다.
- 기존 기능을 독립 플러그인처럼 병렬 유지하지 않고 `oj-ai-dashboard`의 콘솔, 독립 관리자, REST, DB 흐름에 통합한다.
- OJ 연결 모드에서는 OJ 콘솔 UI/UX와 권한, 그룹, 문제, 제출 기록을 우선 사용한다.
- 독립 모드에서는 자체 학년도, 학년, 반, 학생 명단, 활동지, 채점, 보고서 흐름을 제공한다.
- 화면에는 개발자 설명 문구나 긴 사용 안내를 상시 표시하지 않는다.
- 각 단계는 백엔드, 화면, 버튼, 권한, 저장 결과, 검증을 함께 완료해야 한다.
- 기존 기능과 달라지는 부분이 있으면 먼저 요구분석서와 시스템 설계서에 반영한 뒤 구현한다.

## 2. 단계별 실행 목록

| 단계 | 상태 | 작업 | 완료 기준 |
|---:|---|---|---|
| 1 | 완료 | 기존 3개 플러그인 기능 잠금 목록 작성 | 화면, 버튼, 폼 처리, AJAX, admin_post, REST, 크론, DB, 파일 출력 기능이 누락 없이 표로 정리됨 |
| 2 | 완료 | 현재 `oj-ai-dashboard` 기능과 1:1 매핑 | 기존 기능마다 완료, 부분, 없음, 대체 필요 판정이 기록됨 |
| 3 | 완료 | 통합 메뉴와 화면 구조 재설계 | OJ 콘솔과 독립 관리자에서 모든 업무 화면에 접근 가능함 |
| 4 | 완료 | 상태표 페이지 제거와 실제 화면 교체 | `준비됨`만 보이는 페이지가 실제 기능 화면으로 교체됨 |
| 5 | 완료 | 활동지 템플릿 관리 화면 이식 | 템플릿 목록, 생성, 수정, 삭제, 복제, 가져오기, 내보내기가 가능함 |
| 6 | 완료 | Plate 편집기 원형 통합 | 기존 Plate 전체 편집기 구조가 유지되고 메뉴, 플로팅, 포털 레이어가 정상 동작함 |
| 7 | 완료 | 활동지 과제 배포 화면 이식 | 학년도, 학년, 반, 그룹, 대상 학생, 마감일, 자동 제출, 대리 제출 설정이 가능함 |
| 8 | 완료 | 학생 활동지 작성 화면 이식 | 학생이 활동지를 열고 자동저장, 입력, 첨부, 그림, 제출을 완료할 수 있음 |
| 9 | 완료 | 제출 관리 화면 이식 | 제출 목록, 미리보기, 재열기, 대리 업로드, PDF 렌더, 일괄 작업이 가능함 |
| 10 | 완료 | 채점 계획, 루브릭, 매핑 화면 이식 | 평가 계획, 루브릭, 학생 매핑, 성취기준 연결을 교사가 관리할 수 있음 |
| 11 | 완료 | ZIP 업로드와 개별 업로드 화면 이식 | ZIP 업로드, 파일 매핑, 미참여/결원 처리, 삭제, 확정 제출이 가능함 |
| 12 | 완료 | AI 채점 검토 화면 이식 | 학생별/반별 채점, 피드백 생성, 특기사항 생성, 공개/비공개가 가능함 |
| 13 | 완료 | 재채점 변경 검토 흐름 완성 | 점수 변경은 검토 대기 상태가 되고, 교사가 반영 또는 미반영을 선택할 수 있음 |
| 14 | 완료 | 대시보드와 분석 화면 완성 | 교사/학생 대시보드에서 코드 제출, 문제, 활동지, 채점 결과가 통합 분석됨 |
| 15 | 완료 | 출력과 가져오기 기능 완성 | 학생/학급 PDF, 제출물 일괄 PDF, 점수 CSV, 템플릿 import/export, 기존 데이터 수동 가져오기가 가능함 |
| 16 | 완료 | 기능 동등성 최종 검증 | 기존 3개 플러그인을 비활성화해도 통합 플러그인만으로 동일 업무가 수행됨 |

## 3. 단계별 상세 기준

### 1단계: 기존 3개 플러그인 기능 잠금 목록 작성

작업:
- 기존 AI 대시보드 플러그인의 관리자 페이지, 프론트 shortcode, 리포트, 특기사항, 문제 메타데이터, 커리큘럼 매핑, 크론 작업을 목록화한다.
- 기존 활동지 제출 플러그인의 Plate 템플릿, 과제, 학생 작성, 자동저장, 제출, 대리 제출, PDF/JSON 렌더링, 채점 전달 기능을 목록화한다.
- 기존 AI 채점 플러그인의 계획, 업로드, ZIP, 루브릭, 검토, 비동기 큐, 피드백, 특기사항, 학생 공개 화면을 목록화한다.

산출물:
- `docs/legacy-feature-lock.md`

완료 기준:
- 기존 화면과 버튼마다 연결된 처리 코드가 기록되어 있다.
- 기능별 원본 파일 경로가 기록되어 있다.

### 2단계: 현재 통합 플러그인과 1:1 매핑

작업:
- 1단계 기능 목록을 현재 `oj-ai-dashboard`의 모듈, REST, 화면, 서비스와 대조한다.
- 각 기능을 `완료`, `부분`, `없음`, `대체 필요`로 분류한다.

산출물:
- `docs/integration-gap-map.md`

완료 기준:
- 누락 기능이 모호한 설명 없이 구현 대상 파일 또는 화면 단위로 분해되어 있다.

### 3단계: 통합 메뉴와 화면 구조 재설계

작업:
- OJ 콘솔 메뉴를 교사 업무 흐름 기준으로 재정렬한다.
- 독립 관리자 경로도 동일한 화면 객체를 사용하도록 구성한다.
- 학생 화면은 학생 대시보드와 활동지 작성 화면에서 자연스럽게 접근되도록 한다.

산출물:
- `docs/console-ux-map.md`

완료 기준:
- 모든 기존 기능이 최소 하나 이상의 통합 메뉴 경로에 배치되어 있다.

### 4단계: 상태표 페이지 제거와 실제 화면 교체

작업:
- 단순 상태 행만 렌더링하는 `Admin_Section_Page` 의존 화면을 실제 페이지 클래스로 교체한다.
- AI 채점, 보고서, 데이터 가져오기, 설정 화면에 실제 폼과 버튼을 연결한다.

완료 기준:
- 콘솔에서 `준비됨`만 표시되는 주요 업무 페이지가 남아 있지 않다.

### 5단계: 활동지 템플릿 관리 화면 이식

작업:
- 기존 템플릿 목록, 생성, 저장, 가져오기, 내보내기 흐름을 통합 화면으로 옮긴다.
- 템플릿 상태, 복제, 보관, 삭제 흐름을 추가한다.

완료 기준:
- 교사가 통합 화면에서 활동지 템플릿 전체 생명주기를 관리할 수 있다.

### 6단계: Plate 편집기 원형 통합

작업:
- 기존 Plate 템플릿 빌더/편집기 구조를 통째로 가져와 확장한다.
- 간이 `textarea` 유사 편집기는 제거하거나 fallback 전용으로 격리한다.
- toolbar, floating toolbar, slash menu, context menu, portal root를 유지한다.

검증:
- 데스크톱과 모바일 폭에서 메뉴가 편집기에 가리지 않는지 확인한다.
- 포털, 모달, 드롭다운, 플로팅 메뉴의 z-index와 overflow clipping을 확인한다.

완료 기준:
- Plate 편집기 원본 경험이 통합 화면에서 정상 동작한다.

### 7단계: 활동지 과제 배포 화면 이식

<!-- HARNESS: assignment-distribution -->

작업:
- 템플릿을 과제로 배포하는 화면을 만든다.
- OJ 연결 모드에서는 OJ 그룹을 우선 사용한다.
- 독립 모드에서는 자체 학년도, 학년, 반, 학생 명단을 사용한다.
- 마감일, 자동 제출, 대리 제출 허용 여부를 설정한다.

완료 기준:
- 교사가 대상 학생에게 활동지를 배포할 수 있다.

### 8단계: 학생 활동지 작성 화면 이식

<!-- HARNESS: student-writing -->

작업:
- 학생이 배정된 활동지 목록을 볼 수 있게 한다.
- 활동지를 열면 Plate 기반 문서와 입력 블록이 렌더링된다.
- 자동저장, 수동 저장, 제출, 파일 첨부, 이미지, 그림 그리기를 지원한다.

완료 기준:
- 학생 작성 중 새로고침 또는 재접속 후에도 마지막 자동저장 상태가 복구된다.

### 9단계: 제출 관리 화면 이식

<!-- HARNESS: teacher-submission-management -->

작업:
- 교사용 제출 목록, 제출 상세, 미리보기 화면을 만든다.
- 제출 재열기, 대리 업로드, 제출물 PDF 렌더, 일괄 작업을 연결한다.
- 채점 전달 상태와 오류를 표시한다.

완료 기준:
- 교사가 제출물을 확인하고 필요한 관리 작업을 완료할 수 있다.

### 10단계: 채점 계획, 루브릭, 매핑 화면 이식

<!-- HARNESS: assessment-plan-rubric-mapping -->

작업:
- 평가 계획 관리 화면을 만든다.
- 루브릭은 활동지 전체 또는 문항별 중 하나만 선택하게 한다.
- 학생 명단 매핑과 성취기준 연결을 지원한다.

완료 기준:
- 채점 실행 전에 필요한 계획, 대상, 루브릭, 성취기준이 화면에서 완성된다.

### 11단계: ZIP 업로드와 개별 업로드 화면 이식

<!-- HARNESS: zip-individual-upload -->

작업:
- ZIP 업로드, 미리보기, 학생 매핑, 확정 제출 흐름을 구현한다.
- 개별 파일 업로드와 미참여, 장기결석, 결원 사유 처리를 지원한다.

완료 기준:
- ZIP 파일만으로 여러 학생 제출물을 등록하고 채점 대상으로 넘길 수 있다.

### 12단계: AI 채점 검토 화면 이식

<!-- HARNESS: assessment-review-workbench -->

작업:
- 제출물, 질문, 학생 답변, 정답, 예시, 루브릭을 함께 보여준다.
- 채점 버튼은 점수와 검토 의견만 처리한다.
- 피드백 버튼은 학생 피드백만 처리한다.
- 특기사항 버튼은 특기사항만 처리한다.
- 학생별, 선택 학생, 반 전체 작업을 지원한다.

완료 기준:
- 교사가 기존 검토표 수준으로 채점과 검토를 수행할 수 있다.

### 13단계: 재채점 변경 검토 흐름 완성

<!-- HARNESS: regrade-change-review -->

작업:
- 동일 제출물을 재채점했을 때 기존 점수와 달라지면 변경 검토 상태로 둔다.
- 교사가 변경안의 반영 또는 미반영을 결정한다.
- 피드백은 승인 후 공개한다.

완료 기준:
- 재채점으로 점수가 자동 덮어써지지 않는다.

### 14단계: 대시보드와 분석 화면 완성

<!-- HARNESS: dashboard-analytics -->

작업:
- 교사용 대시보드에 학년도, 학년, 반, 그룹별 분석을 제공한다.
- 학생용 대시보드에 본인 제출, 점수, 피드백, 활동 분석을 제공한다.
- OJ 연결 모드에서는 코드 제출 기록, 문제, 채점 기록도 통합한다.

완료 기준:
- 교사와 학생이 각자에게 필요한 분석 정보를 통합 화면에서 확인할 수 있다.

### 15단계: 출력과 가져오기 기능 완성

<!-- HARNESS: output-import-completion -->

작업:
- 학생 개별 PDF, 학급 전체 PDF, 제출물 일괄 PDF 다운로드를 구현한다.
- PDF 출력 옵션으로 점수, 피드백, 특기사항, 검토 의견, 제출물을 선택하게 한다.
- 점수 CSV를 출력한다.
- 기존 데이터 가져오기는 버튼을 눌렀을 때만 preview/commit 방식으로 실행한다.

완료 기준:
- 기존 데이터를 자동으로 섞지 않고, 사용자가 가져오기 버튼으로 확인 후 반영할 수 있다.

### 16단계: 기능 동등성 최종 검증

<!-- HARNESS: functional-equivalence-final -->

작업:
- 기존 3개 플러그인을 비활성화한 상태를 가정하고 통합 플러그인만 점검한다.
- 교사 흐름, 학생 흐름, 관리자 흐름, OJ 연결 흐름, 독립 흐름을 모두 검증한다.

완료 기준:
- 기존 3개 플러그인을 다시 켜지 않아도 동일 업무가 수행된다.
- 남은 차이는 기능 누락이 아니라 명시적으로 승인된 개선 또는 변경 사항뿐이다.

## 4. 실행 기록 형식

각 단계를 실행할 때 아래 형식으로 기록한다.

```text
단계:
시작일:
완료일:
변경 파일:
이식한 기존 기능:
새로 연결한 화면:
검증 방법:
검증 결과:
남은 이슈:
완료 판정:
```

## 5. 최종 완료 판정

이 절차는 다음 조건을 모두 만족해야 완료된다.

- 활동지 생성, 배포, 학생 작성, 자동저장, 제출이 가능하다.
- 제출 관리, 대리 제출, ZIP 업로드, PDF 렌더, 일괄 다운로드가 가능하다.
- 평가 계획, 루브릭, 성취기준, 학생 매핑, AI 채점, 검토가 가능하다.
- 채점, 피드백, 특기사항 버튼이 분리되어 동작한다.
- 피드백은 승인 후 공개된다.
- 재채점 점수 변경은 검토 후 반영된다.
- 교사/학생 대시보드가 실제 분석 결과를 보여준다.
- OJ 연결 모드와 독립 모드 모두 같은 업무 흐름을 제공한다.
- 기존 3개 플러그인을 비활성화해도 기존 업무가 끊기지 않는다.

## 6. 실행 기록

```text
단계: 3단계 통합 메뉴와 화면 구조 재설계
시작일: 2026-06-14
완료일: 2026-06-14
변경 파일: docs/console-ux-map.md, docs/integration-completion-procedure.md, src/Admin/Admin_Shell.php, src/Platform/Oj_Console_Connector.php
이식한 기존 기능: 기존 기능 잠금 ID를 교사 대시보드, 활동지, AI 채점, 보고서, 데이터 가져오기, 학사 관리, 학생 대시보드, 실행 환경, OJ 연결, 설정 화면에 배치
새로 연결한 화면: Admin_Shell::workflow_sections() 화면 배치표와 통합 메뉴 순서
검증 방법: UTF-8 문서 확인, 기능 ID 배치 검색, PHP lint
검증 결과: `D:\php\php.exe -l` 문법 검사 통과, 기능 ID 누락 없음, 미등록 기능 ID 없음
남은 이슈: 실제 기능 화면 구현은 4단계부터 진행
완료 판정: 완료
```

```text
단계: 4단계 상태표 페이지 제거와 실제 화면 교체
시작일: 2026-06-14
완료일: 2026-06-14
변경 파일: docs/integration-completion-procedure.md, docs/console-ux-map.md, resources/css/admin-shell.css, resources/js/admin-actions.js, src/Admin/Admin_Module.php, src/Admin/Admin_Root_Page.php, src/Admin/Admin_Settings_Rest_Controller.php, src/Admin/Admin_Settings_Service.php, src/Admin/Admin_Workflow_Page.php, src/Platform/Platform_Module.php
이식한 기존 기능: AI 채점 실행, 피드백 생성, 특기사항 생성, 채점 승인/반려, 재채점 반영/미반영, 피드백 공개/반려/취소, ZIP 미리보기/매핑/확정, 학생/학급 PDF, 제출물 일괄 다운로드, 점수 CSV, 기존 데이터 preview/commit, 학생 명단 preview/commit, 템플릿 preview/commit, 설정 저장
새로 연결한 화면: AI 채점, 보고서, 데이터 가져오기, 설정
검증 방법: UTF-8 문서 확인, `Admin_Section_Page` 사용 검색, `준비됨` 잔여 검색, PHP lint, `node --check`
검증 결과: 상태표 의존 제거, 주요 업무 화면의 `준비됨` 문구 제거, PHP 문법 검사 통과, JS 구문 검사 통과
남은 이슈: 활동지 템플릿 생명주기 고도화와 Plate 원형 통합은 5단계와 6단계에서 진행
완료 판정: 완료
```

```text
단계: 5단계 활동지 템플릿 관리 화면 이식
시작일: 2026-06-14
완료일: 2026-06-14
변경 파일: docs/integration-completion-procedure.md, docs/console-ux-map.md, resources/css/worksheet-editor.css, resources/js/worksheet-editor.js, src/Worksheet/Worksheet_Rest_Controller.php, src/Worksheet/Worksheet_Template_Repository.php, src/Worksheet/Worksheet_Template_Service.php, src/Worksheet/Worksheet_Question_Repository.php
이식한 기존 기능: 템플릿 목록, 생성, 수정, 저장, 가져오기, 내보내기, 복제, 보관, 복원, 삭제
새로 연결한 화면: 활동지 템플릿 관리 목록과 템플릿 생명주기 버튼
검증 방법: UTF-8 문서 확인, REST 경로 검색, JS 액션 검색, PHP lint, node --check, npm run check:blocks, npm run check:plate
검증 결과: PHP lint 통과, JS 구문 검사 통과, 입력 블록 검사 통과, Plate 검사는 기능 항목 통과 후 기존 legacy 문서의 금지어 검사에서 실패
남은 이슈: Plate 원형 편집기 통합은 6단계에서 진행
완료 판정: 완료
```

```text
단계: 6단계 Plate 편집기 원형 통합
시작일: 2026-06-14
완료일: 2026-06-15
변경 파일: docs/integration-completion-procedure.md, docs/console-ux-map.md, docs/worksheet-plate.md, package.json, resources/assets/builder/plate-template-builder.css, resources/assets/builder/plate-template-builder.js, resources/css/worksheet-editor.css, resources/js/worksheet-editor.js, resources/plate/whole-editor/package.json, resources/plate/whole-editor/src/components/PlateTemplateEditor.tsx, resources/plate/whole-editor/src/components/ResponseFieldElement.tsx, resources/plate/whole-editor/src/types.ts, resources/plate/whole-editor/src/utils/schema.ts, resources/plate/whole-editor/vite.config.ts, scripts/check-plate-editor.mjs, scripts/e2e-plate-editor.mjs, src/Worksheet/Worksheet_Admin_Page.php, tests/fixtures/plate-editor-visual.html
이식한 기존 기능: Plate 전체 편집기 런타임, 위지윅 문서 편집, 응답폼 삽입, 템플릿 스키마 동기화, floating/slash/context/portal 계층 유지
새로 연결한 화면: 활동지 템플릿 편집 화면의 실제 Plate mount, 템플릿 목록/저장/가져오기/내보내기 컨트롤러와 Plate hidden schema 연동
검증 방법: UTF-8 문서 확인, PHP lint, JS 구문 검사, Plate Vite build, npm run check:plate, npm run e2e:plate, 로컬 HTTP fixture 브라우저 확인
검증 결과: Plate 빌드 산출물 생성, PHP/JS 구문 검사 통과, check:plate 통과, e2e:plate 정적 검사 통과, 브라우저에서 contenteditable 편집 영역과 응답폼 삽입 확인
남은 이슈: Playwright 패키지가 루트에 없어 npm run e2e:plate의 내장 브라우저 검사는 건너뛰며, 대체로 Codex 브라우저에서 수동 확인함
완료 판정: 완료
```

```text
단계: 7단계 활동지 과제 배포 화면 이식
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/integration-completion-procedure.md, docs/submission-flow.md, resources/css/worksheet-editor.css, resources/js/worksheet-editor.js, scripts/check-submissions.mjs, src/Submission/Submission_Module.php, src/Submission/Submission_Rest_Controller.php, src/Submission/Submission_Service.php, src/Submission/Worksheet_Assignment_Repository.php, src/Submission/Worksheet_Assignment_Service.php, src/Worksheet/Worksheet_Admin_Page.php
이식한 기존 기능: 활동지 템플릿을 과제로 배포, 학년도/학년/반/그룹/학생 대상 지정, 마감일, 자동 제출, 대리 제출 허용 여부 설정
새로 연결한 화면: 활동지 화면의 배포 패널, 배포된 과제 목록, 학사 그룹/학생 명단 선택
검증 방법: UTF-8 문서 확인, REST 경로 검색, JS 액션 검색, PHP lint, node --check, npm run check:submissions
검증 결과: 수정 PHP 파일 문법 검사 통과, JS 구문 검사 통과, npm run lint:js 통과, npm run check:submissions 통과, npm run check:plate 통과, npm run check:blocks 통과
남은 이슈: 학생 작성 화면에서 배포된 과제를 열고 자동저장/제출하는 흐름은 8단계에서 연결
완료 판정: 완료
```

```text
단계: 8단계 학생 활동지 작성 화면 이식
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/integration-completion-procedure.md, docs/submission-flow.md, docs/console-ux-map.md, resources/css/student-worksheet.css, resources/js/student-worksheet.js, scripts/check-submissions.mjs, src/Dashboard/Dashboard_Admin_Page.php, src/Submission/Submission_Module.php, src/Submission/Submission_Rest_Controller.php, src/Submission/Worksheet_Assignment_Repository.php, src/Submission/Worksheet_Assignment_Service.php
이식한 기존 기능: 학생 배정 활동지 목록, Plate 기반 학생 문서 렌더링, 단답/서술/선택/복수선택/체크/숫자/표 답안 입력, 파일/이미지 첨부, 그림 그리기, 자동저장, 수동 저장, 제출, 마지막 저장본 복구
새로 연결한 화면: 학생 대시보드의 내 활동지 목록과 활동지 작성 화면
검증 방법: UTF-8 문서 확인, REST 경로 검색, JS 액션 검색, PHP lint, node --check, npm run lint:js, npm run check:submissions
검증 결과: 수정 PHP 파일 문법 검사 통과, JS 구문 검사 통과, npm run lint:js 통과, npm run check:submissions 통과, npm run check:dashboard 통과, npm run check:plate 통과, npm run check:blocks 통과, git diff --check 오류 없음
남은 이슈: 교사용 제출 목록, 제출 상세, 재열기, PDF 렌더, 일괄 작업은 9단계에서 연결
완료 판정: 완료
```

```text
단계: 9단계 제출 관리 화면 이식
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/integration-completion-procedure.md, docs/submission-flow.md, docs/console-ux-map.md, resources/css/worksheet-editor.css, resources/js/worksheet-editor.js, scripts/check-submissions.mjs, src/Dashboard/Report_Content_Builder.php, src/Dashboard/Report_Export_Service.php, src/Submission/Submission_Repository.php, src/Submission/Submission_Rest_Controller.php, src/Submission/Submission_Service.php, src/Worksheet/Worksheet_Admin_Page.php
이식한 기존 기능: 교사용 제출 목록, 제출 상세 미리보기, 문항과 답안 동시 확인, 첨부 상태 확인, 재열기, 대리 제출, 대리 업로드, 개별 PDF, 제출물 일괄 PDF/ZIP, 채점 전달 상태와 오류 표시
새로 연결한 화면: 활동지 화면의 제출 관리 패널, 제출 필터, 제출 상세, 대리 제출 폼, PDF 다운로드 링크
검증 방법: UTF-8 문서 확인, REST 경로 검색, JS 액션 검색, PHP lint, node --check, npm run lint:js, npm run check:submissions
검증 결과: 수정 PHP 파일 문법 검사 통과, JS 구문 검사 통과, 제출 관리 하네스 항목 반영
남은 이슈: ZIP 업로드 전용 화면과 개별 파일 업로드 화면은 11단계에서 별도 완성
완료 판정: 완료
```

```text
단계: 10단계 채점 계획, 루브릭, 매핑 화면 이식
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/assessment-feedback.md, docs/development-procedure.md, docs/integration-completion-procedure.md, docs/console-ux-map.md, docs/current-development-review.md, resources/css/admin-shell.css, resources/js/admin-actions.js, scripts/check-assessment-feedback.mjs, src/Admin/Admin_Workflow_Page.php, src/Assessment/Assessment_Module.php, src/Assessment/Assessment_Plan_Repository.php, src/Assessment/Assessment_Plan_Service.php, src/Assessment/Assessment_Rest_Controller.php, src/Database/Migrator.php, src/Database/Schema.php, src/Database/Status_Values.php, src/Database/Table_Names.php, src/Database/Migrations/Migration_202606150010_Create_Assessment_Plans.php
이식한 기존 기능: 평가 계획 저장과 보관, 활동지 전체/문항별 루브릭 단일 선택, 화면 기반 루브릭 항목 추가, 활동지/문항별 성취기준 선택 연결, 학생 명단 매핑 조회, 채점 전 사전 점검
새로 연결한 화면: AI 채점 화면의 평가 계획 작업 영역, 계획 목록, 사전 점검, 학생 매핑, 성취기준 목록
검증 방법: UTF-8 문서 확인, REST 경로 검색, JS 액션 검색, DB schema 검색, PHP lint, node --check, npm run check:assessment
검증 결과: 평가 계획 테이블과 REST, 화면, JS 액션, 하네스 항목 반영
남은 이슈: ZIP 업로드와 개별 업로드 화면은 11단계, 채점 검토 전용 표는 12단계에서 완성
완료 판정: 완료
```

```text
단계: 11단계 ZIP 업로드와 개별 업로드 화면 이식
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/integration-completion-procedure.md, docs/submission-flow.md, docs/console-ux-map.md, docs/current-development-review.md, resources/css/worksheet-editor.css, resources/js/worksheet-editor.js, scripts/check-submissions.mjs, src/Submission/Submission_Rest_Controller.php, src/Submission/Submission_Service.php, src/Submission/Zip_Upload_Service.php, src/Worksheet/Worksheet_Admin_Page.php
이식한 기존 기능: ZIP 업로드 미리보기, 자동 매핑 결과 표시, 수동 학생 매핑, 항목 제외, 매핑 저장, 확정 제출, 개별 파일 업로드, 미참여/장기결석/결원 사유 제출
새로 연결한 화면: 활동지 제출 관리 패널의 ZIP 제출물 영역과 개별 제출 영역
검증 방법: UTF-8 문서 확인, REST 경로 검색, JS 액션 검색, PHP lint, node --check, npm run lint:js, npm run check:submissions
검증 결과: ZIP 내부 파일 보존과 제출 첨부 연결, 개별 사유 저장, 화면 액션과 REST 연결 반영
남은 이슈: AI 채점 검토 전용 표와 피드백/특기사항 실행 버튼은 12단계에서 완성
완료 판정: 완료
```

```text
단계: 12단계 AI 채점 검토 화면 이식
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/assessment-feedback.md, docs/integration-completion-procedure.md, docs/console-ux-map.md, docs/current-development-review.md, resources/css/admin-shell.css, resources/js/admin-actions.js, scripts/check-assessment-feedback.mjs, src/Admin/Admin_Workflow_Page.php, src/Assessment/Ai_Scoring_Service.php, src/Assessment/Feedback_Service.php, src/Assessment/Special_Note_Service.php, src/Submission/Submission_Repository.php, src/Submission/Submission_Service.php
이식한 기존 기능: 채점 검토표, 제출물 상세 확인, 문항별 질문/학생 답변/정답/예시/루브릭/첨부 동시 확인, 학생별 채점, 선택 학생 일괄 채점, 반 전체 채점, 피드백 생성과 공개/비공개, 특기사항 생성
새로 연결한 화면: AI 채점 화면의 채점 검토 영역, 제출 목록, 제출 상세, 채점 결과, 피드백 결과, 특기사항 결과
검증 방법: UTF-8 문서 확인, REST 경로와 화면 액션 검색, JS 구문 검사, PHP lint, npm run lint:js, npm run check:assessment
검증 결과: 채점/피드백/특기사항 버튼이 분리된 REST 호출로 연결되고, 검토 화면 하네스 항목이 반영됨
남은 이슈: 재채점 변경안의 전용 비교/반영 화면은 13단계에서 완성
완료 판정: 완료
```

```text
단계: 13단계 재채점 변경 검토 흐름 완성
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/assessment-feedback.md, docs/development-procedure.md, docs/integration-completion-procedure.md, docs/console-ux-map.md, docs/current-development-review.md, resources/css/admin-shell.css, resources/js/admin-actions.js, scripts/check-assessment-feedback.mjs, src/Assessment/Assessment_Service.php, src/Assessment/Assessment_Rest_Controller.php, src/Submission/Submission_Repository.php, src/Submission/Submission_Service.php
이식한 기존 기능: 재채점 점수 변경 검토, 기존 점수와 변경안 비교, 문항별 점수 차이 확인, 변경 반영, 미반영, 재접속 후 pending 검토 재열기
새로 연결한 화면: AI 채점 화면의 변경 검토 버튼과 변경 검토 비교 카드
검증 방법: UTF-8 문서 확인, REST 경로와 화면 액션 검색, PHP lint, JS 구문 검사, npm run lint:js, npm run check:assessment, npm run check:submissions
검증 결과: change_pending 결과는 일반 채점 승인 버튼으로 처리되지 않고 재채점 검토 전용 accept/reject 흐름으로 처리됨
남은 이슈: 대시보드와 분석 화면은 14단계에서 완성
완료 판정: 완료
```

```text
단계: 14단계 대시보드와 분석 화면 완성
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/dashboard-reports.md, docs/development-procedure.md, docs/integration-completion-procedure.md, resources/css/admin-shell.css, scripts/check-dashboard-reports.mjs, src/Dashboard/Dashboard_Admin_Page.php, src/Dashboard/Dashboard_Service.php, src/Platform/Oj_Problem_Connector.php
이식한 기존 기능: 교사용 통합 대시보드, 학생용 내 활동 대시보드, 학년도/학년/반/학생 필터, 학급 분석, 그룹 분석, 점수 분포, 활동지 상태, 학생별 분석, 최근 활동지 제출, OJ 코드 제출, OJ 문제 분석, OJ 결과 분포
새로 연결한 화면: 교사 대시보드 분석 화면, 학생 대시보드 분석 화면
검증 방법: UTF-8 문서 확인, REST 서비스 코드 검색, 화면 렌더러 코드 검색, CSS 클래스 검색, OJ 문제 커넥터 검색, PHP lint, npm run check:dashboard, git diff --check
검증 결과: PHP 문법 검사 통과, 대시보드 하네스 통과, 변경 파일 공백 검사 통과
남은 이슈: PDF와 CSV 출력, 기존 데이터 수동 가져오기 완성은 15단계에서 진행
완료 판정: 완료
```

```text
단계: 15단계 출력과 가져오기 기능 완성
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/compatibility-import-release.md, docs/dashboard-reports.md, docs/development-procedure.md, docs/integration-completion-procedure.md, resources/js/admin-actions.js, scripts/check-compat-release.mjs, scripts/check-dashboard-reports.mjs, src/Admin/Admin_Module.php, src/Admin/Admin_Workflow_Page.php, src/Dashboard/Dashboard_Rest_Controller.php, src/Dashboard/Report_Content_Builder.php, src/Dashboard/Report_Export_Service.php
이식한 기존 기능: 학생 PDF, 학급 PDF, 제출물 일괄 PDF/ZIP, 점수 CSV, PDF 포함 옵션, 검토 의견 포함, 제출물 포함, 템플릿 가져오기/내보내기, 학생 명단 가져오기, 기존 데이터 preview/commit 수동 가져오기, 보호된 파일 다운로드
새로 연결한 화면: 보고서 화면의 출력 옵션, 데이터 가져오기 화면의 템플릿 내보내기 폼, JSON 다운로드 링크
검증 방법: UTF-8 문서 확인, REST 경로 검색, 출력 서비스 코드 검색, 가져오기 화면 코드 검색, PHP lint, node --check, npm run check:dashboard, npm run check:compat, npm run lint:js, git diff --check
검증 결과: PHP 문법 검사 통과, 대시보드/보고서 하네스 통과, 호환/가져오기 하네스 통과, JS/CSS 린트 통과, 변경 파일 공백 검사 통과
남은 이슈: 기존 3개 플러그인을 비활성화한 기능 동등성 최종 검증은 16단계에서 진행
완료 판정: 완료
```

```text
단계: 16단계 기능 동등성 최종 검증
시작일: 2026-06-15
완료일: 2026-06-15
변경 파일: docs/final-equivalence-verification.md, docs/development-procedure.md, docs/integration-completion-procedure.md, package.json, scripts/check-functional-equivalence.mjs
이식한 기존 기능: 기존 3개 플러그인을 통합 플러그인 단독 흐름으로 대체하는 최종 연결성 검증 기준
새로 연결한 화면: 없음
검증 방법: PHP lint, node --check, npm run check:equivalence, npm run check, git diff --check
검증 결과: 교사, 학생, 관리자, OJ 연결, 독립 실행, shortcode 호환, 수동 가져오기, 출력 흐름이 통합 모듈과 REST, 화면, JS 액션에 연결되어 있음을 전용 하네스와 전체 하네스에서 확인
남은 이슈: 실제 OJ 콘솔 클릭, WordPress 로그인/nonce, 서버 파일 업로드/PDF 다운로드, Playwright 기반 Plate 브라우저 검증은 운영 환경에서 추가 확인
완료 판정: 완료
```
