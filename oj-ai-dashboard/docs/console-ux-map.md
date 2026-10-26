# 통합 콘솔 UX 맵

문서 상태: 3단계 완료, 4단계/5단계/6단계/7단계/8단계/9단계/10단계/11단계/12단계/13단계 반영
작성일: 2026-06-14
기준 문서: `docs/legacy-feature-lock.md`, `docs/integration-gap-map.md`
대상 절차: `docs/integration-completion-procedure.md` 3단계

## 1. 목적

이 문서는 기존 3개 플러그인의 기능을 `oj-ai-dashboard` 하나의 통합 콘솔에서 사용할 수 있도록 메뉴, 화면, 권한, 경로를 고정한다.

3단계의 완료 범위는 실제 기능 구현 완료가 아니라 모든 기존 기능을 최소 하나 이상의 통합 메뉴 경로에 배치하는 것이다. 4단계부터는 이 배치표를 기준으로 상태표 화면을 실제 업무 화면으로 교체한다.

## 2. 배치 원칙

| 원칙 | 적용 내용 |
|---|---|
| 교사 업무 흐름 우선 | `전체 -> 교사 대시보드 -> 활동지 -> AI 채점 -> 보고서 -> 데이터 가져오기 -> 학급/명단 연결 -> 학생 대시보드 -> 실행 환경 -> OJ 플랫폼 연결 -> 설정` 순서로 배치한다. |
| 같은 화면 객체 공유 | OJ 콘솔과 독립 콘솔은 `Admin_Module::all_pages()`의 페이지 객체와 `Admin_Shell::workflow_sections()`의 화면 배치표를 공유한다. |
| OJ 연결 우선 | OJ가 연결되어 있으면 그룹, 문제, 코드 제출, 채점 기록은 OJ 데이터를 우선 사용한다. |
| 독립 사용 보장 | OJ가 없으면 학년도, 학년, 반, 학생 명단, 활동지, 채점, 보고서를 자체 데이터로 운영한다. |
| 학생 화면 분리 | 학생은 학생 대시보드에서 배정 활동지, 제출 상태, 공개 피드백, 점수를 확인하고 활동지 작성 화면으로 이동한다. |
| 화면 설명 배제 | 실제 화면에는 개발자 설명 문구나 긴 교사용 설명 문구를 상시 노출하지 않는다. |

## 3. 최상위 메뉴와 경로

| 순서 | 메뉴 | OJ 콘솔 경로 | 독립 콘솔 경로 | 권한 | 페이지 객체 |
|---:|---|---|---|---|---|
| 0 | 전체 | `/oj-console/ai-dashboard` | `/oj-ai-dashboard/admin/` | `view_own_dashboard` | `Dashboard_Admin_Page` |
| 10 | 교사 대시보드 | `/oj-console/ai-dashboard/teacher` | `/oj-ai-dashboard/admin/teacher/` | `teach` | `Dashboard_Admin_Page` |
| 20 | 활동지 | `/oj-console/ai-dashboard/worksheets` | `/oj-ai-dashboard/admin/worksheets/` | `manage_worksheets` | `Worksheet_Admin_Page` |
| 30 | AI 채점 | `/oj-console/ai-dashboard/assessment` | `/oj-ai-dashboard/admin/assessment/` | `run_assessment` | `Admin_Workflow_Page` |
| 40 | 보고서 | `/oj-console/ai-dashboard/reports` | `/oj-ai-dashboard/admin/reports/` | `export_reports` | `Admin_Workflow_Page` |
| 50 | 데이터 가져오기 | `/oj-console/ai-dashboard/import` | `/oj-ai-dashboard/admin/import/` | `import_data` | `Admin_Workflow_Page` |
| 60 | 학급/명단 연결 | `/oj-console/ai-dashboard/academic` | `/oj-ai-dashboard/admin/academic/` | `manage_academic_context` | `Academic_Admin_Page` |
| 70 | 학생 대시보드 | `/oj-console/ai-dashboard/student` | `/oj-ai-dashboard/admin/student/` | `view_own_dashboard` | `Dashboard_Admin_Page` |
| 80 | 실행 환경 | `/oj-console/ai-dashboard/environment` | `/oj-ai-dashboard/admin/environment/` | `manage_settings` | `Environment_Admin_Page` |
| 90 | OJ 플랫폼 연결 | `/oj-console/ai-dashboard/connection` | `/oj-ai-dashboard/admin/connection/` | `manage_settings` | `Platform_Admin_Page` |
| 100 | 설정 | `/oj-console/ai-dashboard/settings` | `/oj-ai-dashboard/admin/settings/` | `manage_settings` | `Admin_Workflow_Page` |

WordPress 관리자 메뉴 등록은 기본값으로 사용하지 않는다. 필터로 명시적으로 켜진 경우에도 같은 페이지 객체를 임시 진입점으로만 사용한다.

## 4. 업무 화면 배치

| 메뉴 | 화면 키 | 사용자 흐름 | 배치 기능 ID | 구현 단계 |
|---|---|---|---|---|
| 전체 | `overview` | 전체 요약과 역할별 시작점 | UX-01, UX-02, UX-03 | 14단계 |
| 교사 대시보드 | `overview` | 학년도, 학년, 반, 그룹 필터와 학생 요약 | LD-DASH-01, LD-ACT-01, LD-ACT-02 | 14단계 |
| 교사 대시보드 | `code-activity` | OJ 코드 제출, 문제, 채점 기록 분석 | LD-DASH-01, LD-PMETA-01, LD-CRON-01, LD-CRON-02 | 14단계 |
| 교사 대시보드 | `problem-curriculum` | 문제 메타데이터와 성취기준 매핑 | LD-PMETA-01, LD-CURR-01, LD-ACT-06, LD-AJAX-01, LD-AJAX-02 | 14단계 |
| 교사 대시보드 | `student-reports` | 학생별 분석, 특기사항, 보고서 이동 | LD-ACT-03, LD-ACT-04, LD-ACT-05, AI-03, OUT-01 | 14, 15단계 |
| 활동지 | `templates` | 템플릿 목록, 생성, 수정, 복제, 가져오기, 내보내기 | LW-TPL-01, LW-ACT-01, LW-ACT-02, LW-ACT-03, LW-ACT-04, OUT-06 | 5단계 |
| 활동지 | `editor` | Plate 원형 편집기와 입력 블록 구성 | LW-TPL-01, LW-ACT-01, LW-ACT-02, LW-ACT-03 | 6단계 |
| 활동지 | `assignments` | 학년도, 학년, 반, 그룹, 학생 대상 배포 | LW-ASG-01, LW-ACT-05, UX-02, UX-03 | 7단계 |
| 활동지 | `student-writing` | 자동저장, 파일 첨부, 그림, 제출 | LW-AJAX-01, LW-AJAX-02, LW-AJAX-03, UX-05 | 8단계 |
| 활동지 | `submissions` | 제출 목록, 미리보기, 대리 제출, 대리 업로드, 재열기, PDF, 일괄 작업 | LW-SUB-01, LW-ACT-06, LW-ACT-07, LW-ACT-08, LW-ACT-09, LW-ACT-10, LW-ACT-11, LW-ACT-12, LW-ACT-13, OUT-03, OUT-04, OUT-05 | 9단계 |
| 활동지 | `logs` | 활동지 처리 로그와 설정 저장 | LW-LOG-01, LW-ACT-14 | 9단계 |
| AI 채점 | `plans` | 평가 계획 생성, 수정, 삭제 | LA-PLAN-01, LA-ACT-01, LA-ACT-02 | 10단계 |
| AI 채점 | `rubrics` | 활동지 전체 또는 문항별 루브릭 중 하나 선택 | LA-RUBRIC-01, LA-ACT-03 | 10단계 |
| AI 채점 | `uploads` | ZIP 업로드, 개별 업로드, 학생 매핑, 미제출 사유 | LA-UPLOAD-01, LA-ACT-04, LA-ACT-05, LA-ACT-06, LA-ACT-07, OUT-07 | 11단계 |
| AI 채점 | `review` | 질문, 답변, 정답, 예시, 루브릭을 함께 보고 채점 | LA-REVIEW-01, LA-ACT-08, LA-AJAX-01, LA-AJAX-02, LA-AJAX-03, LA-AJAX-04, LA-AJAX-05, LA-CRON-01, AI-01, AI-02, AI-03, AI-05 | 12단계 |
| AI 채점 | `regrade` | 재채점 변경 검토 후 반영 또는 미반영 | AI-04, UX-06 | 13단계 |
| AI 채점 | `student-release` | 승인된 피드백과 점수 공개 | LA-SC-01, LA-SC-02, UX-06 | 12, 13단계 |
| 보고서 | `student-pdf` | 학생 개별 PDF 출력 | OUT-01, LD-ACT-03, LD-ACT-04, LD-ACT-05 | 15단계 |
| 보고서 | `class-pdf` | 학급 전체 PDF 출력 | OUT-02, LD-ACT-01 | 15단계 |
| 보고서 | `bulk-submissions` | 제출물 일괄 PDF/ZIP 다운로드 | OUT-03, OUT-04, OUT-05 | 15단계 |
| 보고서 | `scores-csv` | 점수 CSV 출력 | OUT-08 | 15단계 |
| 보고서 | `report-options` | 피드백, 특기사항, 점수, 검토 의견, 제출물 포함 여부 선택 | OUT-01, OUT-02, OUT-03, OUT-08 | 15단계 |
| 데이터 가져오기 | `legacy-data` | 기존 데이터 preview/commit 수동 가져오기 | OUT-06, UX-04 | 15단계 |
| 데이터 가져오기 | `roster` | 학생 명단 가져오기와 계정 매핑 | OUT-09, LA-MAP-01, LA-ACT-09, LA-ACT-10, LA-ACT-11, UX-03 | 10, 15단계 |
| 데이터 가져오기 | `templates` | 템플릿 가져오기와 내보내기 | OUT-06, LW-TPL-01 | 5, 15단계 |
| 데이터 가져오기 | `runs` | 가져오기 실행 기록 | LW-LOG-01, OUT-06 | 15단계 |
| 학급/명단 연결 | `years-classes` | 연도별 학년, 반 조회와 운영 | UX-03, UX-08 | 7, 14단계 |
| 학급/명단 연결 | `groups` | OJ 연결 시 OJ 그룹 우선, 독립 시 로컬 그룹 | UX-02, UX-07 | 7단계 |
| 학급/명단 연결 | `students` | 학생 명단, 학번, 계정 매핑 | OUT-09, LA-MAP-01, LA-ACT-09, LA-ACT-10, LA-ACT-11, UX-03 | 10, 15단계 |
| 학급/명단 연결 | `curriculum` | 교육과정과 성취기준 관리 | LD-CURR-01, LD-ACT-06, LD-AJAX-01, LD-AJAX-02, LA-PLAN-01 | 10, 14단계 |
| 학생 대시보드 | `activity` | 본인 활동, 제출, 공개 피드백 요약 | LD-DASH-02, LA-SC-01, LA-SC-02 | 14단계 |
| 학생 대시보드 | `worksheets` | 배정 활동지 열기, 자동저장 복구, 제출 | LW-ASG-01, LW-AJAX-01, LW-AJAX-02, LW-AJAX-03, UX-05 | 8단계 |
| 학생 대시보드 | `feedback` | 승인 후 공개된 피드백 확인 | LA-SC-01 | 12, 13단계 |
| 학생 대시보드 | `scores` | 점수 확인 | LD-DASH-02, LA-SC-02 | 14단계 |
| 실행 환경 | `checks` | 스케줄러, 큐, 파일 출력 환경 점검 | UX-01, LA-CRON-01, LD-CRON-01, LD-CRON-02, LW-CRON-01, LW-CRON-02, LW-CRON-03 | 4, 16단계 |
| OJ 플랫폼 연결 | `connection` | OJ 콘솔, 그룹, 문제, 제출 기록 연결 상태 | UX-02, UX-04, UX-07, LD-PMETA-01 | 14, 16단계 |
| 설정 | `compatibility` | 기존 action, AJAX, shortcode 호환 설정 | LD-ACT-12, LA-ACT-12, LW-ACT-14, LD-SC-01, LD-SC-02, LW-SC-01, FRONT-01 | 4, 16단계 |
| 설정 | `ai-provider` | AI provider와 채점, 피드백, 특기사항 실행 설정 | LA-ACT-12, AI-01, AI-02, AI-03, AI-05 | 12단계 |
| 설정 | `queues` | 분석, 메타데이터, 렌더, AI 큐 상태와 즉시 실행 | LD-ACT-13, LD-ACT-14, LD-CRON-01, LD-CRON-02, LD-CRON-03, LD-CRON-04, LW-CRON-01, LW-CRON-02, LW-CRON-03, LA-CRON-01 | 12, 14, 16단계 |

## 5. 기존 기능 그룹별 접근 경로

| 기능 그룹 | 포함 ID | 기본 메뉴 경로 |
|---|---|---|
| 통합 메뉴 진입 | LD-ADMIN-01, LW-ADMIN-01, LA-ADMIN-01 | 전체, 교사 대시보드, 활동지, AI 채점 |
| 교사 분석 | LD-DASH-01, LD-ACT-01, LD-ACT-02, LD-ACT-03, LD-ACT-04, LD-ACT-05 | 교사 대시보드, 보고서 |
| 학생 분석 | LD-DASH-02, LD-SC-02, LA-SC-01, LA-SC-02 | 학생 대시보드 |
| 문제 메타데이터 | LD-PMETA-01, LD-ACT-07, LD-ACT-08, LD-ACT-09, LD-ACT-10, LD-ACT-11 | 교사 대시보드, OJ 플랫폼 연결, 설정 |
| 교육과정과 성취기준 | LD-CURR-01, LD-ACT-06, LD-AJAX-01, LD-AJAX-02 | 교사 대시보드, 학급/명단 연결 |
| 활동지 템플릿 | LW-TPL-01, LW-ACT-01, LW-ACT-02, LW-ACT-03, LW-ACT-04 | 활동지, 데이터 가져오기 |
| 활동지 과제 | LW-ASG-01, LW-ACT-05 | 활동지, 학급/명단 연결 |
| 학생 작성과 저장 | LW-AJAX-01, LW-AJAX-02, LW-AJAX-03, LW-SC-01 | 학생 대시보드, 활동지 |
| 제출 관리 | LW-SUB-01, LW-ACT-06, LW-ACT-07, LW-ACT-08, LW-ACT-09, LW-ACT-10, LW-ACT-11, LW-ACT-12, LW-ACT-13 | 활동지, 보고서 |
| 활동지 로그와 설정 | LW-LOG-01, LW-ACT-14 | 활동지, 설정 |
| 평가 계획과 루브릭 | LA-PLAN-01, LA-RUBRIC-01, LA-ACT-01, LA-ACT-02, LA-ACT-03 | AI 채점 |
| 업로드와 ZIP 채점 | LA-UPLOAD-01, LA-ACT-04, LA-ACT-05, LA-ACT-06, LA-ACT-07, LA-ACT-08, OUT-07 | AI 채점 |
| 채점 검토 | LA-REVIEW-01, LA-AJAX-01, LA-AJAX-02, LA-AJAX-03, LA-AJAX-04, LA-AJAX-05, AI-01, AI-02, AI-03, AI-04, AI-05 | AI 채점 |
| 학생 명단과 매핑 | LA-MAP-01, LA-ACT-09, LA-ACT-10, LA-ACT-11, OUT-09 | 데이터 가져오기, 학급/명단 연결 |
| 출력 | OUT-01, OUT-02, OUT-03, OUT-04, OUT-05, OUT-08 | 보고서 |
| 템플릿 가져오기/내보내기 | OUT-06 | 활동지, 데이터 가져오기 |
| 크론과 비동기 작업 | LD-CRON-01, LD-CRON-02, LD-CRON-03, LD-CRON-04, LW-CRON-01, LW-CRON-02, LW-CRON-03, LA-CRON-01 | 실행 환경, 설정 |
| 통합 UX 조건 | UX-01, UX-02, UX-03, UX-04, UX-05, UX-06, UX-07, UX-08 | 전체 메뉴 전반 |

## 6. 학생 접근 흐름

| 상황 | 진입점 | 후속 화면 |
|---|---|---|
| 배정 활동지 작성 | 학생 대시보드 `worksheets` | 활동지 작성 화면, 자동저장, 수동 제출 |
| 마감 후 자동 제출 | 학생 대시보드 `worksheets` | 제출 상태 표시, 교사용 제출 관리 반영 |
| 피드백 공개 후 확인 | 학생 대시보드 `feedback` | 승인된 피드백만 표시 |
| 점수 확인 | 학생 대시보드 `scores` | 공개 가능한 점수만 표시 |
| 독립 shortcode 호환 | 설정 `compatibility`에서 활성화한 학생 경로 | 기존 shortcode와 새 경로 모두 연결 |

## 7. 코드 반영 위치

| 파일 | 반영 내용 |
|---|---|
| `src/Admin/Admin_Shell.php` | 최상위 메뉴 순서와 `workflow_sections()` 화면 배치표를 정의한다. |
| `src/Platform/Oj_Console_Connector.php` | OJ 콘솔 메뉴 순서를 `Admin_Shell::menu_order_for_slug()`에서 가져온다. |
| `src/Admin/Admin_Module.php` | OJ 콘솔과 독립 콘솔이 같은 페이지 객체를 공유한다. |
| `src/Admin/Admin_Workflow_Page.php` | AI 채점, 보고서, 데이터 가져오기, 설정의 REST 기반 업무 폼과 채점 검토 화면을 렌더링한다. |
| `resources/js/admin-actions.js` | 업무 폼의 REST 요청, 파일 업로드, 결과 표시, 다운로드 링크, 채점 검토 목록/상세/채점/피드백/특기사항 실행, 재채점 변경 검토 반영/미반영을 처리한다. |
| `resources/css/admin-shell.css` | 독립 콘솔과 OJ 콘솔에서 AI 채점 계획, 채점 검토, 재채점 비교, 결과 패널의 레이아웃을 정의한다. |
| `src/Worksheet/Worksheet_Admin_Page.php` | 활동지 화면에 실제 Plate mount, hidden schema, 템플릿 생명주기 컨트롤, Plate 빌드 산출물 enqueue, 과제 배포 패널, 제출 관리 패널, ZIP 제출물 패널, 개별 제출 패널을 연결한다. |
| `resources/assets/builder/plate-template-builder.js` | 물리 병합한 Plate 원형 편집기의 WordPress 로드용 빌드 산출물이다. |
| `resources/assets/builder/plate-template-builder.css` | Plate 원형 편집기와 응답폼 블록의 스타일 산출물이다. |
| `resources/js/worksheet-editor.js` | 활동지 템플릿 목록, 열기, 저장, 복제, 보관, 복원, 삭제, 가져오기, 내보내기, 과제 배포, 제출 목록, 제출 상세, 재열기, 대리 제출, 대리 업로드, ZIP 미리보기/매핑/확정, 개별 업로드, 사유 제출, PDF 출력 액션을 처리하고 Plate hidden schema와 REST payload를 동기화한다. |
| `resources/css/worksheet-editor.css` | 템플릿 관리 목록, 생명주기 버튼, Plate mount 영역, 배포 패널, 제출 관리 패널, ZIP 매핑 표, 개별 제출 패널의 콘솔 화면 스타일을 정의한다. |
| `src/Submission/Submission_Rest_Controller.php` | 과제 배포 목록/저장 REST와 자동저장, 제출, 대리 제출, 대리 업로드, 개별 사유 메타데이터, 마감 자동 제출, ZIP 업로드, 교사용 제출 목록/상세/재열기 REST를 연결한다. |
| `src/Submission/Submission_Service.php` | 자동저장, 제출, 대리 제출, ZIP 제출 확정과 첨부 연결, 교사용 제출 조회, 제출 재열기, 채점 전달 상태 형식을 조정한다. |
| `src/Submission/Zip_Upload_Service.php` | ZIP 미리보기, 안전 검사 결과 저장, 내부 파일 보존, 수동 매핑, 항목 제외, 확정 제출을 담당한다. |
| `src/Submission/Submission_Repository.php` | 제출과 배포 과제, 학생 사용자 정보를 결합해 교사용 목록과 상세 데이터를 조회한다. |
| `src/Dashboard/Report_Export_Service.php` | 제출물 PDF/ZIP과 학생 보고서 출력 시 과제 필터를 전달한다. |
| `src/Dashboard/Report_Content_Builder.php` | 제출물 보고서와 점수 CSV 조회에 과제 필터를 반영한다. |
| `src/Assessment/Assessment_Plan_Service.php` | 평가 계획 저장, 루브릭 모드 단일 선택, 성취기준 링크, 학생 매핑 사전 점검을 담당한다. |
| `src/Assessment/Assessment_Plan_Repository.php` | 평가 계획 목록과 저장을 담당한다. |
| `src/Assessment/Assessment_Rest_Controller.php` | 평가 계획 목록/저장/조회/사전 점검/성취기준 저장, 채점, 재채점 변경 검토 REST 경로를 연결한다. |
| `src/Assessment/Assessment_Service.php` | 채점 fingerprint, accepted 결과, change_pending 결과, 재채점 변경 검토 반영/미반영을 담당한다. |
| `src/Database/Schema.php` | 평가 계획 테이블과 루브릭/성취기준/대상 JSON 저장 필드를 정의한다. |
| `src/Submission/Worksheet_Assignment_Service.php` | 템플릿 배포 대상, 마감일, 자동 제출, 대리 제출 설정을 저장 가능한 과제 데이터로 정규화한다. |
| `src/Submission/Worksheet_Assignment_Repository.php` | 활동지 과제 저장, 목록, 마감 자동 제출 대상 조회를 담당한다. |
| `src/Dashboard/Dashboard_Admin_Page.php` | 학생 대시보드에 내 활동지 목록과 작성 화면 mount를 렌더링하고 작성 자산을 enqueue한다. |
| `resources/js/student-worksheet.js` | 학생 배정 활동지 목록, Plate 학생 문서 렌더링, 자동저장, 수동 저장, 제출, 첨부, 그림 저장을 처리한다. |
| `resources/css/student-worksheet.css` | 학생 활동지 목록, 작성 화면, 표 입력, 첨부, 그림 캔버스의 콘솔 화면 스타일을 정의한다. |
| `resources/plate/whole-editor/src/components/PlateTemplateEditor.tsx` | Plate 원형 편집기, 위지윅 탭, 미리보기, 소스 탭, 응답폼 삽입, 외부 템플릿 로드를 담당한다. |
| `resources/plate/whole-editor/src/components/ResponseFieldElement.tsx` | Plate 문서 안의 학생 입력폼 블록과 표 셀 입력 미리보기를 담당한다. |
| `src/Worksheet/Worksheet_Rest_Controller.php` | 템플릿 저장, 가져오기, 내보내기, 복제, 보관, 복원, 삭제 REST 경로를 연결한다. |
| `src/Worksheet/Worksheet_Template_Service.php` | 템플릿 생명주기 처리를 저장소와 질문, 성취기준 연결 정리에 위임한다. |
| `src/Worksheet/Worksheet_Template_Repository.php` | 템플릿 상태 변경과 soft delete 저장 처리를 담당한다. |

## 8. 3단계 완료 판정

| 확인 항목 | 결과 |
|---|---|
| OJ 콘솔 메뉴가 교사 업무 흐름 기준으로 재정렬되었는가 | 완료 |
| 독립 콘솔과 OJ 콘솔이 같은 화면 객체와 경로 체계를 공유하는가 | 완료 |
| 학생 화면이 학생 대시보드와 활동지 작성 흐름에 배치되었는가 | 완료 |
| 기존 기능 잠금 ID가 최소 하나 이상의 메뉴 경로에 배치되었는가 | 완료 |
| 실제 기능 구현으로 남은 항목이 다음 단계에 연결되었는가 | 완료 |
