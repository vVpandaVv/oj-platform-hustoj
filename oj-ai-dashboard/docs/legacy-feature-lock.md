# 기존 3개 플러그인 기능 잠금 목록

문서 상태: 1단계 완료
작성일: 2026-06-14
대상 절차: `docs/integration-completion-procedure.md` 1단계

## 1. 목적

이 문서는 기존 3개 플러그인의 실제 기능, 진입 화면, 버튼, 폼, hook, AJAX, cron, DB 테이블, 출력 기능을 통합 개발의 기준선으로 잠그기 위한 목록이다.

이후 단계에서 `oj-ai-dashboard`가 기존 플러그인을 대체할 수 있는지 판단할 때 이 문서의 기능 잠금 항목을 기준으로 비교한다.

## 2. 원본 범위

| 구분 | 원본 폴더 | 원본 역할 | 통합 후 위치 원칙 |
|---|---|---|---|
| 기존 대시보드 플러그인 | `hustoj-ai-dashboard` | 코드 제출 기록, 문제 메타데이터, 교육과정 매핑, 학생/그룹 분석, 보고서, 특기사항 | 통합 대시보드, 분석, 보고서, 특기사항 기능으로 병합 |
| 기존 활동지 제출 플러그인 | `hustoj-ai-worksheet-submission` | 활동지 템플릿, 과제, 학생 작성/자동저장/제출, 교사 대리 제출, PDF/JSON 렌더링 | 통합 활동지 제작/배포/제출/출력 기능으로 병합 |
| 기존 AI 채점 플러그인 | `hustoj-ai-assessment` | 평가 계획, 루브릭, 자료 업로드, ZIP 채점, AI 채점/피드백/특기사항, 승인 공개 | 통합 평가/채점/피드백/특기사항 기능으로 병합 |

## 3. 관리자/콘솔 화면 잠금 목록

| 잠금 ID | 영역 | 기존 진입점 | 핵심 기능 | 근거 파일 |
|---|---|---|---|---|
| LD-ADMIN-01 | 대시보드 관리자 메뉴 | 메인 메뉴와 하위 메뉴 | 대시보드, 문제 메타데이터, 교육과정 매핑, 설정, 보고서 화면 진입 | `hustoj-ai-dashboard/src/Admin/Menu.php` |
| LD-DASH-01 | 교사용 그룹 대시보드 | 관리자 대시보드 페이지 | 그룹 선택, 학생별 분석, 그룹 요약, 보고서 생성 | `hustoj-ai-dashboard/src/Admin/Pages/Dashboard_Page.php`, `hustoj-ai-dashboard/templates/manager-dashboard.php` |
| LD-DASH-02 | 학생 대시보드 | 프론트 shortcode/가상 페이지 | 학생 본인 코드 제출/문제 활동 분석 확인 | `hustoj-ai-dashboard/src/Frontend/Shortcodes/Student_Dashboard_Shortcode.php`, `hustoj-ai-dashboard/src/Frontend/Frontend_Router.php` |
| LD-PMETA-01 | 문제 메타데이터 | 문제 메타데이터 페이지 | 문제 메타데이터 생성, 대량 작업 큐, 즉시 실행, 취소 | `hustoj-ai-dashboard/src/Admin/Pages/Problem_Metadata_Page.php` |
| LD-CURR-01 | 교육과정 매핑 | 그룹 교육과정 페이지 | 그룹 교육과정 저장, 매핑 작업 시작/상태 조회 | `hustoj-ai-dashboard/src/Admin/Pages/Group_Curriculum_Page.php` |
| LW-ADMIN-01 | 활동지 관리자 메뉴 | 메인 메뉴와 하위 메뉴 | 템플릿, 과제, 제출물, 로그, 설정 화면 진입 | `hustoj-ai-worksheet-submission/src/Admin/Menu.php` |
| LW-TPL-01 | 활동지 템플릿 | 템플릿 페이지 | 템플릿 생성/수정, 템플릿 가져오기/내보내기, 렌더 작업 등록 | `hustoj-ai-worksheet-submission/src/Admin/Pages/Templates_Page.php`, `hustoj-ai-worksheet-submission/templates/admin/templates/page.php` |
| LW-ASG-01 | 활동지 과제 | 과제 페이지 | 과제 생성/수정, 학습자 배정, shortcode 확인 | `hustoj-ai-worksheet-submission/src/Admin/Pages/Assignments_Page.php`, `hustoj-ai-worksheet-submission/templates/admin/assignments/page.php` |
| LW-SUB-01 | 제출물 관리 | 제출물 페이지 | 제출 목록, 미리보기, 대리 업로드/제출, 재열기, 인계 취소/재전송, 일괄 작업 | `hustoj-ai-worksheet-submission/src/Admin/Pages/Submissions_Page.php`, `hustoj-ai-worksheet-submission/templates/admin/submissions/page.php` |
| LW-LOG-01 | 활동지 로그 | 로그 페이지 | 활동지 처리 로그 확인 | `hustoj-ai-worksheet-submission/src/Admin/Pages/Logs_Page.php` |
| LA-ADMIN-01 | AI 채점 관리자 메뉴 | 메인 메뉴와 하위 메뉴 | 평가 준비, 평가 설계, 자료 업로드, 채점 검토, 계정 매핑, 설정 | `hustoj-ai-assessment/src/Admin/Menu.php` |
| LA-PLAN-01 | 평가 계획 | 평가 준비 페이지 | 계획 생성/수정/삭제, 설계/업로드/검토/출력 화면 이동 | `hustoj-ai-assessment/src/Admin/Pages/Plans_Page.php`, `hustoj-ai-assessment/templates/admin/plans/page.php` |
| LA-RUBRIC-01 | 루브릭 | 평가 설계 페이지 | 루브릭 저장, 요소/기준 추가 삭제, 전체 또는 문항 단위 기준 관리 | `hustoj-ai-assessment/src/Admin/Pages/Rubrics_Page.php`, `hustoj-ai-assessment/templates/admin/rubrics/page.php` |
| LA-UPLOAD-01 | 자료 업로드 | 자료 업로드 페이지 | ZIP 업로드, 개별 파일 업로드, 파일 삭제, 미제출/사유 저장 | `hustoj-ai-assessment/src/Admin/Pages/Uploads_Page.php`, `hustoj-ai-assessment/templates/admin/uploads/page.php` |
| LA-REVIEW-01 | 채점 검토 | 채점 검토 페이지 | 채점, 피드백, 특기사항, 공개/비공개, CSV/출력, 비동기 큐 상태 | `hustoj-ai-assessment/src/Admin/Pages/Reviews_Page.php`, `hustoj-ai-assessment/templates/admin/reviews/page.php` |
| LA-MAP-01 | 계정 매핑 | 계정 학번 매핑 페이지 | 매핑 저장/삭제, 명단 가져오기 | `hustoj-ai-assessment/src/Admin/Pages/Mappings_Page.php`, `hustoj-ai-assessment/templates/admin/mappings/page.php` |

## 4. 버튼/폼/action 잠금 목록

### 4.1 기존 대시보드 플러그인

| 잠금 ID | action 또는 조작 | 유지해야 할 동작 | 근거 |
|---|---|---|---|
| LD-ACT-01 | `hustoj_ai_dashboard_generate_group_report` | 선택 그룹의 전체 보고서 생성 | `src/Admin/Pages/Dashboard_Page.php` |
| LD-ACT-02 | `hustoj_ai_dashboard_generate_student_report` | 선택 학생의 개별 보고서 생성 | `src/Admin/Pages/Dashboard_Page.php` |
| LD-ACT-03 | `hustoj_ai_dashboard_generate_student_note_ai` | AI 특기사항 생성 | `src/Admin/Pages/Dashboard_Page.php` |
| LD-ACT-04 | `hustoj_ai_dashboard_save_student_note` | 교사 수정 특기사항 저장 | `src/Admin/Pages/Dashboard_Page.php` |
| LD-ACT-05 | `hustoj_ai_dashboard_print_student_report` | 학생 보고서 출력 화면 제공 | `src/Admin/Pages/Dashboard_Page.php`, `templates/manager-student-report-print.php` |
| LD-ACT-06 | `hustoj_ai_dashboard_save_group_curriculum` | 그룹별 교육과정 저장 | `src/Admin/Pages/Group_Curriculum_Page.php` |
| LD-AJAX-01 | `hustoj_ai_dashboard_start_curriculum_mapping` | 교육과정 매핑 작업 시작 | `src/Admin/Pages/Group_Curriculum_Page.php` |
| LD-AJAX-02 | `hustoj_ai_dashboard_get_mapping_status` | 교육과정 매핑 상태 조회 | `src/Admin/Pages/Group_Curriculum_Page.php` |
| LD-ACT-07 | `hustoj_ai_dashboard_generate_problem_metadata` | 개별 문제 메타데이터 생성 | `src/Admin/Pages/Problem_Metadata_Page.php` |
| LD-ACT-08 | `hustoj_ai_dashboard_enqueue_problem_metadata_bulk` | 문제 메타데이터 대량 큐 등록 | `src/Admin/Pages/Problem_Metadata_Page.php` |
| LD-ACT-09 | `hustoj_ai_dashboard_run_metadata_jobs_now` | 메타데이터 작업 즉시 실행 | `src/Admin/Pages/Problem_Metadata_Page.php` |
| LD-ACT-10 | `hustoj_ai_dashboard_cancel_metadata_job` | 메타데이터 작업 취소 | `src/Admin/Pages/Problem_Metadata_Page.php` |
| LD-ACT-11 | `hustoj_ai_dashboard_cancel_all_metadata_jobs` | 메타데이터 전체 작업 취소 | `src/Admin/Pages/Problem_Metadata_Page.php` |
| LD-ACT-12 | `hustoj_ai_dashboard_save_settings` | 대시보드 설정 저장 | `src/Admin/Pages/Settings_Page.php` |
| LD-ACT-13 | `hustoj_ai_dashboard_run_now` | 분석 작업 즉시 실행 | `src/Admin/Pages/Settings_Page.php` |
| LD-ACT-14 | `hustoj_ai_dashboard_run_level_analysis_now` | 수준 분석 즉시 실행 | `src/Admin/Pages/Settings_Page.php` |

### 4.2 기존 활동지 제출 플러그인

| 잠금 ID | action 또는 조작 | 유지해야 할 동작 | 근거 |
|---|---|---|---|
| LW-ACT-01 | `hustoj_ai_worksheet_save_template` | 활동지 템플릿 저장 | `src/Admin/Pages/Templates_Page.php` |
| LW-ACT-02 | `hustoj_ai_worksheet_import_template` | 템플릿 가져오기 | `src/Admin/Pages/Templates_Page.php` |
| LW-ACT-03 | `hustoj_ai_worksheet_export_template` | 템플릿 내보내기 | `src/Admin/Pages/Templates_Page.php` |
| LW-ACT-04 | `hustoj_ai_worksheet_enqueue_template_render` | 템플릿 렌더 작업 등록 | `src/Admin/Pages/Templates_Page.php` |
| LW-ACT-05 | `hustoj_ai_worksheet_save_assignment` | 과제 저장 | `src/Admin/Pages/Assignments_Page.php` |
| LW-AJAX-01 | `hustoj_ai_worksheet_save_draft` | 학생 작성 중 자동저장/임시저장 | `src/Frontend/Shortcodes/Student_Assignment_Shortcode.php` |
| LW-AJAX-02 | `hustoj_ai_worksheet_submit_assignment` | 학생 제출 | `src/Frontend/Shortcodes/Student_Assignment_Shortcode.php` |
| LW-AJAX-03 | `hustoj_ai_worksheet_upload_media` | 이미지/파일/그림 자료 업로드 | `src/Frontend/Shortcodes/Student_Assignment_Shortcode.php` |
| LW-ACT-06 | `hustoj_ai_worksheet_teacher_upload` | 교사 대리 업로드/제출 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-07 | `hustoj_ai_worksheet_run_jobs_now` | 렌더 작업 즉시 실행 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-08 | `hustoj_ai_worksheet_reopen_submission` | 제출물 재열기 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-09 | `hustoj_ai_worksheet_cancel_handoff` | 인계 취소 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-10 | `hustoj_ai_worksheet_resend_handoff` | 인계 재전송 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-11 | `hustoj_ai_worksheet_render_pdf` | 제출물 PDF 생성 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-12 | `hustoj_ai_worksheet_download_pdf` | 제출물 PDF 다운로드 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-13 | `hustoj_ai_worksheet_bulk_action` | 제출물 일괄 작업 | `src/Admin/Pages/Submissions_Page.php` |
| LW-ACT-14 | `hustoj_ai_worksheet_save_settings` | 활동지 설정 저장 | `src/Admin/Pages/Settings_Page.php` |

### 4.3 기존 AI 채점 플러그인

| 잠금 ID | action 또는 조작 | 유지해야 할 동작 | 근거 |
|---|---|---|---|
| LA-ACT-01 | `hustoj_ai_assessment_save_plan` | 평가 계획 저장 | `src/Admin/Pages/Plans_Page.php` |
| LA-ACT-02 | `hustoj_ai_assessment_delete_plan` | 평가 계획 삭제 | `src/Admin/Pages/Plans_Page.php` |
| LA-ACT-03 | `hustoj_ai_assessment_save_rubric` | 루브릭 저장 | `src/Admin/Pages/Rubrics_Page.php` |
| LA-ACT-04 | `hustoj_ai_assessment_upload_batch_zip` | ZIP 업로드 채점 자료 등록 | `src/Admin/Pages/Uploads_Page.php` |
| LA-ACT-05 | `hustoj_ai_assessment_upload_student_file` | 학생별 개별 파일 업로드 | `src/Admin/Pages/Uploads_Page.php` |
| LA-ACT-06 | `hustoj_ai_assessment_delete_student_file` | 학생 파일 삭제 | `src/Admin/Pages/Uploads_Page.php` |
| LA-ACT-07 | `hustoj_ai_assessment_save_empty_reason` | 미제출/빈 제출 사유 저장 | `src/Admin/Pages/Uploads_Page.php` |
| LA-ACT-08 | `hustoj_ai_assessment_review_action` | 채점 검토 화면의 동기 처리 action | `src/Admin/Pages/Reviews_Page.php` |
| LA-AJAX-01 | `hustoj_ai_assessment_save_cell` | 채점 검토 셀 저장 | `src/Admin/Pages/Reviews_Page.php` |
| LA-AJAX-02 | `hustoj_ai_assessment_enqueue_async` | 채점/피드백/특기사항/공개 작업 큐 등록 | `src/Admin/Pages/Reviews_Page.php` |
| LA-AJAX-03 | `hustoj_ai_assessment_kick_async` | 비동기 작업 즉시 진행 | `src/Admin/Pages/Reviews_Page.php` |
| LA-AJAX-04 | `hustoj_ai_assessment_cancel_async` | 비동기 작업 취소 | `src/Admin/Pages/Reviews_Page.php` |
| LA-AJAX-05 | `hustoj_ai_assessment_async_status` | 비동기 작업 상태 조회 | `src/Admin/Pages/Reviews_Page.php` |
| LA-ACT-09 | `hustoj_ai_assessment_save_mapping` | 사용자/학번 매핑 저장 | `src/Admin/Pages/Mappings_Page.php` |
| LA-ACT-10 | `hustoj_ai_assessment_delete_mapping` | 사용자/학번 매핑 삭제 | `src/Admin/Pages/Mappings_Page.php` |
| LA-ACT-11 | `hustoj_ai_assessment_import_mappings` | 학생 명단/매핑 가져오기 | `src/Admin/Pages/Mappings_Page.php` |
| LA-ACT-12 | `hustoj_ai_assessment_save_settings` | AI 채점 설정 저장 | `src/Admin/Pages/Settings_Page.php` |

## 5. shortcode, frontend, 학생 경험 잠금 목록

| 잠금 ID | 항목 | 유지해야 할 동작 | 근거 |
|---|---|---|---|
| LD-SC-01 | `hustoj_ai_manager_dashboard` | 교사용 대시보드를 shortcode로 표시 | `hustoj-ai-dashboard/src/Frontend/Shortcodes/Manager_Dashboard_Shortcode.php` |
| LD-SC-02 | `hustoj_ai_student_dashboard` | 학생용 대시보드를 shortcode로 표시 | `hustoj-ai-dashboard/src/Frontend/Shortcodes/Student_Dashboard_Shortcode.php` |
| LW-SC-01 | `hustoj_ai_worksheet_assignment` | 학생 활동지 작성 화면 표시 | `hustoj-ai-worksheet-submission/src/Frontend/Shortcodes/Student_Assignment_Shortcode.php` |
| LA-SC-01 | `hustoj_ai_assessment_feedback` | 학생 피드백 화면 표시 | `hustoj-ai-assessment/src/Frontend/Shortcodes/Student_Feedback_Shortcode.php` |
| LA-SC-02 | `hustoj_ai_assessment_scores` | 학생 점수 화면 표시 | `hustoj-ai-assessment/src/Frontend/Shortcodes/Student_Scores_Shortcode.php` |
| FRONT-01 | 프론트 가상 페이지 | 독립 사용 시 학생/교사 화면 접근 경로 제공 | `src/Frontend/Frontend_Router.php` 계열 |

통합 후 shortcode 명칭은 새 플러그인 네임스페이스로 제공하되, 기존 shortcode와 action은 호환 레이어로 유지한다.

## 6. cron과 비동기 작업 잠금 목록

| 잠금 ID | 작업 | 유지해야 할 동작 | 근거 |
|---|---|---|---|
| LD-CRON-01 | 대시보드 분석 스케줄러 | 제출/문제 활동 분석 주기 실행 | `hustoj-ai-dashboard/src/Cron/Scheduler.php` |
| LD-CRON-02 | 문제 메타데이터 작업 | 문제 메타데이터 큐 처리 | `hustoj-ai-dashboard/src/Cron/Metadata_Job_Runner.php` |
| LD-CRON-03 | 교육과정 매핑 작업 | 그룹/문제 교육과정 매핑 처리 | `hustoj-ai-dashboard/src/Cron/Curriculum_Mapping_Job_Runner.php` |
| LD-CRON-04 | 학생 수준 분석 | 학생별 수준 스냅샷 생성 | `hustoj-ai-dashboard/src/Cron/Student_Level_Analysis_Runner.php` |
| LW-CRON-01 | 활동지 마감 자동 제출 | 마감일 경과 시 자동 제출 | `hustoj-ai-worksheet-submission/src/Cron/Deadline_Auto_Submit_Service.php` |
| LW-CRON-02 | 활동지 렌더 작업 | 제출물 HTML/JSON/PDF 렌더링 | `hustoj-ai-worksheet-submission/src/Cron/Render_Job_Processor.php` |
| LW-CRON-03 | 활동지 스케줄러 | 주기적 렌더/자동 제출 실행 | `hustoj-ai-worksheet-submission/src/Cron/Scheduler.php` |
| LA-CRON-01 | AI 채점 비동기 큐 | 채점, 피드백, 특기사항, 공개/비공개 작업 처리 | `hustoj-ai-assessment/src/Assessment/Reviews/Async_Review_Queue.php` |

## 7. DB 테이블 잠금 목록

### 7.1 기존 대시보드 플러그인

| 잠금 ID | 테이블 | 역할 |
|---|---|---|
| LD-DB-01 | `hustoj_ai_problem_metadata` | 문제 메타데이터 |
| LD-DB-02 | `hustoj_ai_group_curriculums` | 그룹별 교육과정 |
| LD-DB-03 | `hustoj_ai_group_curriculum_items` | 그룹 교육과정 세부 항목 |
| LD-DB-04 | `hustoj_ai_reports` | 분석 보고서 |
| LD-DB-05 | `hustoj_ai_jobs` | 분석/메타데이터 작업 큐 |
| LD-DB-06 | `hustoj_ai_student_notes` | 학생 특기사항 |
| LD-DB-07 | `hustoj_ai_student_level_snapshots` | 학생 수준 스냅샷 |
| LD-DB-08 | `hustoj_ai_curriculum_standards` | 교육과정 성취기준 |
| LD-DB-09 | `hustoj_ai_curriculum_standard_levels` | 성취기준 수준 |
| LD-DB-10 | `hustoj_ai_curriculum_standard_problems` | 성취기준-문제 연결 |

근거: `hustoj-ai-dashboard/src/Database/Tables.php`, `hustoj-ai-dashboard/src/Database/Schema.php`

### 7.2 기존 활동지 제출 플러그인

| 잠금 ID | 테이블 | 역할 |
|---|---|---|
| LW-DB-01 | `hustoj_ai_worksheet_assignments` | 활동지 과제 |
| LW-DB-02 | `hustoj_ai_worksheet_assignment_members` | 과제 배정 대상 |
| LW-DB-03 | `hustoj_ai_worksheet_templates` | 활동지 템플릿 |
| LW-DB-04 | `hustoj_ai_worksheet_drafts` | 작성 중 임시저장 |
| LW-DB-05 | `hustoj_ai_worksheet_submissions` | 최종 제출 |
| LW-DB-06 | `hustoj_ai_worksheet_render_jobs` | 렌더 작업 |
| LW-DB-07 | `hustoj_ai_worksheet_action_logs` | 활동 로그 |

근거: `hustoj-ai-worksheet-submission/src/Database/Tables.php`, `hustoj-ai-worksheet-submission/src/Database/Schema.php`

### 7.3 기존 AI 채점 플러그인

| 잠금 ID | 테이블 | 역할 |
|---|---|---|
| LA-DB-01 | `hustoj_ai_eval_plans` | 평가 계획 |
| LA-DB-02 | `hustoj_ai_eval_submissions` | 평가 제출 단위 |
| LA-DB-03 | `hustoj_ai_eval_artifacts` | 평가 첨부/산출물 |
| LA-DB-04 | `hustoj_ai_eval_feedback` | 점수, 피드백, 특기사항 |
| LA-DB-05 | `hustoj_ai_eval_user_mappings` | 사용자/학번 매핑 |

근거: `hustoj-ai-assessment/src/Database/Tables.php`, `hustoj-ai-assessment/src/Database/Schema.php`

통합 DB 설계는 새 테이블명을 사용하되, 기존 테이블에서 가져오기 버튼을 통해 마이그레이션할 수 있어야 한다. OJ 플랫폼이 연결된 경우 그룹/문제/제출 기록은 플랫폼 데이터를 우선 사용한다.

## 8. 출력, 가져오기, 내보내기 잠금 목록

| 잠금 ID | 기능 | 유지해야 할 동작 | 근거 |
|---|---|---|---|
| OUT-01 | 학생 개별 보고서 출력 | 학생 보고서를 인쇄/PDF 대상으로 출력 | `hustoj-ai-dashboard/templates/manager-student-report-print.php` |
| OUT-02 | 학급/그룹 보고서 생성 | 그룹 단위 분석 보고서 생성 | `hustoj-ai-dashboard/templates/manager-dashboard.php` |
| OUT-03 | 활동지 PDF 렌더 | 제출물 PDF 생성/다운로드 | `hustoj-ai-worksheet-submission/src/Rendering/Pdf_Render_Service.php` |
| OUT-04 | 활동지 HTML/JSON 렌더 | 제출물 HTML/JSON 산출물 생성 | `hustoj-ai-worksheet-submission/src/Rendering/Html_Export_Service.php`, `src/Rendering/Json_Export_Service.php` |
| OUT-05 | 제출물 일괄 처리 | 제출물 일괄 PDF/작업 처리 | `hustoj-ai-worksheet-submission/src/Admin/Pages/Submissions_Page.php` |
| OUT-06 | 템플릿 가져오기/내보내기 | 템플릿 JSON 기반 import/export | `hustoj-ai-worksheet-submission/src/Admin/Pages/Templates_Page.php` |
| OUT-07 | ZIP 업로드 채점 | 압축 파일을 평가 자료로 일괄 등록 | `hustoj-ai-assessment/src/Ingestion/Uploads/Zip_Import_Service.php` |
| OUT-08 | 점수 CSV 출력 | 평가 결과 CSV 다운로드 | `hustoj-ai-assessment/src/Admin/Pages/Reviews_Page.php` |
| OUT-09 | 학생 명단 가져오기 | 계정/학번 매핑 CSV 가져오기 | `hustoj-ai-assessment/src/Admin/Pages/Mappings_Page.php` |

## 9. AI 처리 절차 잠금 목록

| 잠금 ID | 영역 | 기존 절차 | 통합 시 유지 조건 |
|---|---|---|---|
| AI-01 | 채점 | 제출물과 문항/루브릭 기준으로 점수 생성 | 채점 버튼은 점수와 검토 의견만 생성한다 |
| AI-02 | 피드백 | 점수와 제출 근거를 바탕으로 학생 피드백 생성 | 피드백 버튼은 승인 전 비공개 상태로 생성한다 |
| AI-03 | 특기사항 | 교육과정/성취기준/결과를 참고해 특기사항 생성 | 특기사항 버튼은 별도 컨텍스트로 실행한다 |
| AI-04 | 재채점 | 기존 결과와 새 결과를 비교 | 점수/피드백 변경은 변경 검토 상태에 두고 교사 승인 후 반영한다 |
| AI-05 | 응답 정규화 | AI 응답을 구조화된 점수/피드백/근거로 정규화 | 정규화 실패 시 원본 응답과 오류를 보존한다 |

근거: `hustoj-ai-assessment/src/AI/Providers/Assessment_Ai_Service.php`, `hustoj-ai-assessment/src/AI/Normalization`, `hustoj-ai-assessment/src/Assessment/Reviews/Async_Review_Queue.php`

## 10. 통합 UX 유지 조건

| 잠금 ID | 조건 | 완료 기준 |
|---|---|---|
| UX-01 | 메뉴는 OJ 콘솔에 붙어야 한다 | 플랫폼 연결 시 별도 WordPress 관리자 메뉴가 아니라 콘솔 메뉴에서 주요 기능 사용 가능 |
| UX-02 | 독립 사용 시에도 관리자 화면이 필요하다 | 플랫폼이 없을 때 자체 콘솔형 관리자 화면에서 동일 기능 사용 가능 |
| UX-03 | 기능은 보기 전용이 아니어야 한다 | 목록, 생성, 수정, 업로드, 제출, 채점, 승인, 출력, 가져오기/내보내기가 실제 저장소와 연결됨 |
| UX-04 | 기존 플러그인의 버튼 경험을 잃지 않는다 | 원본의 핵심 버튼/action이 통합 화면에 동등 기능으로 존재 |
| UX-05 | 학생 작성 화면은 안정적으로 저장된다 | 자동저장, 임시저장, 최종 제출, 마감 자동 제출이 모두 동작 |
| UX-06 | 채점 결과는 검토 가능한 상태를 갖는다 | 채점, 피드백, 특기사항, 재채점 변경 검토, 승인 공개 흐름이 분리됨 |
| UX-07 | 그룹 우선순위가 명확하다 | 플랫폼 연결 시 플랫폼 그룹을 우선 사용하고 자체 로컬 그룹은 사용하지 않음 |
| UX-08 | 연도/학년/반 조회가 가능하다 | 독립 사용 시 연도별 학년/반 그룹으로 결과 조회 가능 |

## 11. 1단계 완료 판정

| 확인 항목 | 결과 |
|---|---|
| 기존 3개 플러그인의 관리자 화면을 식별했는가 | 완료 |
| 기존 3개 플러그인의 학생/프론트 화면을 식별했는가 | 완료 |
| 기존 버튼, form action, AJAX action을 통합 기준으로 잠갔는가 | 완료 |
| cron과 비동기 작업을 식별했는가 | 완료 |
| 기존 DB 테이블과 저장 책임을 식별했는가 | 완료 |
| 출력, 가져오기, 내보내기, ZIP 업로드, CSV 기능을 식별했는가 | 완료 |
| 통합 후 UX 유지 조건을 명시했는가 | 완료 |

## 12. 다음 단계로 넘길 입력

2단계에서는 이 문서의 잠금 ID를 기준으로 `oj-ai-dashboard` 현재 구현과 대조하여 기능 매핑표를 작성한다. 매핑표에는 다음 상태 중 하나를 기록한다.

| 상태 | 의미 |
|---|---|
| 구현됨 | 현재 통합 플러그인에서 실제 저장/처리까지 동작 |
| 부분 구현 | 화면 또는 일부 서비스만 있고 기존 기능을 완전히 대체하지 못함 |
| 누락 | 기능 또는 진입점이 없음 |
| 호환 필요 | 새 기능은 있으나 기존 action/shortcode/API 호환이 필요 |
| 플랫폼 연결 필요 | OJ 콘솔 또는 플랫폼 데이터 어댑터와 연결되어야 완료 |
