# 현재 통합 플러그인 기능 매핑표

문서 상태: 2단계 완료
작성일: 2026-06-14
기준 문서: `docs/legacy-feature-lock.md`
대상 절차: `docs/integration-completion-procedure.md` 2단계

> 2026-06-15 현재 10단계까지 구현이 진행되어 일부 판정은 당시 현황과 다르다. 최신 진행 점검은 `docs/current-development-review.md`를 기준으로 본다.

## 1. 판정 기준

| 상태 | 의미 |
|---|---|
| 구현됨 | 현재 통합 플러그인에서 저장, 실행, 조회까지 실제 코드가 연결되어 있다 |
| 부분 구현 | 백엔드 또는 일부 화면은 있으나 기존 사용자 경험을 그대로 대체하지 못한다 |
| 누락 | 기능, 화면, 저장소, 실행 경로가 현재 코드에서 확인되지 않는다 |
| 호환 필요 | 새 기능은 있으나 기존 shortcode, action, AJAX, 출력 경로 호환이 없다 |
| 대체 필요 | 상태표, 요약 행, 임시 경로를 실제 업무 화면으로 교체해야 한다 |
| 플랫폼 연결 필요 | OJ 콘솔 또는 OJ 데이터 어댑터와 실제 운영 연결 검증이 필요하다 |

## 2. 현재 코드 확인 요약

| 확인 항목 | 현재 상태 | 근거 |
|---|---|---|
| 모듈 등록 | 핵심 모듈은 등록됨 | `src/Core/Plugin.php` |
| 통합 메뉴 | 독립 관리자와 OJ 콘솔 연결 껍데기는 있음 | `src/Admin/Admin_Module.php`, `src/Platform/Oj_Console_Connector.php` |
| WordPress 관리자 메뉴 | 기본 비활성. 필터가 켜질 때만 등록 | `oj_ai_dashboard_register_wp_admin_pages` |
| REST API | 52개 `register_rest_route()` 호출 확인 | `src/*/*_Rest_Controller.php` |
| 기존 `admin_post` 호환 | 없음 | `rg` 결과 `admin_post` 등록 없음 |
| 기존 `wp_ajax` 호환 | 없음 | `rg` 결과 `wp_ajax` 등록 없음 |
| cron 스케줄 | 없음 | `wp_schedule_event`, `wp_schedule_single_event` 등록 없음 |
| 상태표 페이지 | AI 채점, 보고서, 데이터 가져오기, 설정은 상태표 중심 | `src/Admin/Admin_Module.php` |
| Plate 원형 소스 | 6단계에서 WordPress 화면에 실제 Plate 빌드 산출물과 mount 구조가 연결됨 | `resources/plate/whole-editor`, `resources/assets/builder`, `resources/js/worksheet-editor.js`, `src/Worksheet/Worksheet_Admin_Page.php` |
| 프론트 대시보드 shortcode | mount div만 출력하고 렌더링 스크립트 없음 | `src/Dashboard/Dashboard_Module.php`, `src/Compatibility/Shortcode_Compatibility_Service.php` |

## 3. 핵심 결론

현재 `oj-ai-dashboard`는 통합 DB, REST 서비스, 권한, 일부 분석/출력 백엔드는 많이 준비되어 있다. 그러나 기존 3개 플러그인의 교사/학생 경험을 그대로 대체하기에는 주요 업무 화면이 부족하다.

특히 다음 항목은 3단계 이후 우선 구현 대상이다.

| 우선순위 | 갭 | 이유 |
|---:|---|---|
| 1 | AI 채점, 보고서, 데이터 가져오기 상태표 페이지를 실제 화면으로 교체 | 메뉴에서 기능을 사용할 수 없다는 사용성 문제가 직접 발생 |
| 2 | 과제 배포, 학생 작성, 제출 관리 화면 구현 | 활동지 템플릿만으로는 기존 활동지 제출 플러그인을 대체할 수 없음 |
| 3 | 기존 `admin_post`, AJAX, shortcode 호환 레이어 추가 | 기존 페이지/버튼/shortcode가 통합 플러그인에서 동작하지 않음 |
| 4 | cron 스케줄러 추가 | 자동 제출, 렌더 작업, 분석 작업, 비동기 채점 큐가 자동 실행되지 않음 |
| 5 | OJ 문제 메타데이터와 교육과정 매핑 흐름 구현 | 코드 제출/문제 기반 대시보드 요구가 부분 분석에 머무름 |

## 4. 관리자/콘솔 화면 매핑

| 잠금 ID | 판정 | 현재 구현 | 부족한 부분 | 다음 구현 대상 |
|---|---|---|---|---|
| LD-ADMIN-01 | 부분 구현 | 단일 관리자/콘솔 메뉴 존재 | 문제 메타데이터, 교육과정 매핑, 보고서 생성 화면이 원본 수준이 아님 | 통합 메뉴 재배치와 실제 페이지 연결 |
| LD-DASH-01 | 부분 구현 | 교사 대시보드가 요약 행을 출력 | 그룹 선택, 학생 상세, 보고서 버튼, 분석 테이블 부족 | 교사 대시보드 실제 업무 화면 |
| LD-DASH-02 | 부분 구현 | 학생 대시보드 REST와 shortcode mount 존재 | mount 렌더러와 학생 상세 화면 부족 | 학생 프론트 렌더러와 대시보드 화면 |
| LD-PMETA-01 | 누락 | 확인된 구현 없음 | 문제 메타데이터 생성/큐/취소 없음 | 문제 메타데이터 서비스와 화면 |
| LD-CURR-01 | 부분 구현 | 교육과정/성취기준 REST와 활동지 링크 존재 | 그룹별 교육과정 저장/매핑 작업 UI 없음 | 교육과정 관리 화면과 매핑 작업 |
| LW-ADMIN-01 | 부분 구현 | 활동지 메뉴와 템플릿 편집 화면 존재 | 과제, 제출물, 로그, 설정 화면 부족 | 활동지 하위 업무 화면 분리 |
| LW-TPL-01 | 구현됨 | 템플릿 저장, 목록, 가져오기, 내보내기, 삭제, 복제, 보관, 복원, 원형 Plate 편집기 연결 가능 | 렌더 작업은 제출 관리 단계에서 연결 필요 | 과제 배포와 제출 관리 화면 이식 |
| LW-ASG-01 | 누락 | 과제 repository만 확인 | 과제 생성/수정/배정 UI와 REST 없음 | 과제 배포 화면과 저장 API |
| LW-SUB-01 | 부분 구현 | autosave/submit/proxy/ZIP 백엔드 존재 | 제출 목록, 미리보기, 재열기, 개별 PDF, 일괄 작업 UI 부족 | 제출 관리 화면 이식 |
| LW-LOG-01 | 누락 | audit/activity 테이블은 있으나 로그 화면 없음 | 활동지 처리 로그 조회 화면 없음 | 로그 페이지와 기록 연결 |
| LA-ADMIN-01 | 부분 구현 | AI 채점 메뉴와 REST 서비스 존재 | 화면은 상태표 중심 | AI 채점 실제 검토 화면 |
| LA-PLAN-01 | 누락 | 평가 계획 화면/테이블 없음 | 계획 생성/삭제/화면 이동 없음 | 평가 계획 화면과 저장소 |
| LA-RUBRIC-01 | 부분 구현 | 활동지 루브릭 모드, 루브릭 스냅샷 존재 | 루브릭 전용 편집 화면 없음 | 루브릭 편집 UI |
| LA-UPLOAD-01 | 부분 구현 | ZIP preview/map/commit REST 존재 | ZIP 업로드 화면, 개별 업로드, 삭제, 사유 저장 UI 없음 | 자료 업로드 화면 |
| LA-REVIEW-01 | 부분 구현 | 단일 제출 채점/피드백/특기사항 REST 존재 | 검토 목록, 셀 저장, 일괄 큐, 공개/비공개 화면 없음 | 채점 검토 화면 |
| LA-MAP-01 | 부분 구현 | 학사/학생 명단 REST와 매핑 저장소 존재 | 계정 매핑 전용 화면과 가져오기 버튼 부족 | 학생 명단/매핑 화면 |

## 5. 버튼, 폼, action 매핑

### 5.1 대시보드 계열

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| LD-ACT-01 | 부분 구현 | `POST /oj-ai/v1/reports/class` | 그룹 보고서 버튼과 기존 action 호환 |
| LD-ACT-02 | 부분 구현 | `POST /oj-ai/v1/reports/student` | 학생 보고서 버튼과 기존 action 호환 |
| LD-ACT-03 | 부분 구현 | `POST /oj-ai/v1/assessments/{submission_id}/special-note` | 학생 단위 특기사항 생성 화면과 승인/수정 흐름 |
| LD-ACT-04 | 누락 | 특기사항 생성 저장만 있음 | 교사 수동 저장/수정/승인 API와 화면 |
| LD-ACT-05 | 부분 구현 | PDF 생성 REST 존재 | 인쇄 화면 또는 다운로드 버튼 UX |
| LD-ACT-06 | 부분 구현 | 교육과정 REST 존재 | 그룹별 교육과정 저장 화면 |
| LD-AJAX-01 | 누락 | 확인된 구현 없음 | 교육과정 매핑 시작 REST/AJAX |
| LD-AJAX-02 | 누락 | 확인된 구현 없음 | 매핑 상태 조회 REST/AJAX |
| LD-ACT-07 | 누락 | 확인된 구현 없음 | 문제 메타데이터 생성 |
| LD-ACT-08 | 누락 | 확인된 구현 없음 | 문제 메타데이터 대량 큐 |
| LD-ACT-09 | 누락 | 확인된 구현 없음 | 메타데이터 작업 즉시 실행 |
| LD-ACT-10 | 누락 | 확인된 구현 없음 | 메타데이터 작업 취소 |
| LD-ACT-11 | 누락 | 확인된 구현 없음 | 메타데이터 전체 취소 |
| LD-ACT-12 | 부분 구현 | 실행 환경/OJ 연결 REST와 페이지 존재 | 통합 설정 저장 화면 |
| LD-ACT-13 | 누락 | 확인된 구현 없음 | 분석 작업 즉시 실행 |
| LD-ACT-14 | 누락 | 확인된 구현 없음 | 수준 분석 즉시 실행 |

### 5.2 활동지 제출 계열

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| LW-ACT-01 | 구현됨 | `POST /oj-ai/v1/worksheets`, `Worksheet_Template_Service::save()` | 기존 action 호환 추가 |
| LW-ACT-02 | 구현됨 | `POST /oj-ai/v1/worksheets/template-import/preview`, `/commit` | 기존 action 호환 추가 |
| LW-ACT-03 | 구현됨 | `POST /oj-ai/v1/worksheets/{id}/template-export` | 기존 action 호환 추가 |
| LW-ACT-04 | 누락 | 확인된 구현 없음 | 템플릿 렌더 작업 등록 |
| LW-ACT-05 | 누락 | 과제 repository만 있음 | 과제 저장 API와 화면 |
| LW-AJAX-01 | 부분 구현 | `POST /oj-ai/v1/assignments/{id}/autosave` | 학생 작성 화면, 기존 AJAX 호환 |
| LW-AJAX-02 | 부분 구현 | `POST /oj-ai/v1/assignments/{id}/submit` | 학생 작성 화면, 기존 AJAX 호환 |
| LW-AJAX-03 | 누락 | 첨부 메타 저장은 있으나 실제 업로드 endpoint 없음 | 이미지/파일/그림 업로드 API |
| LW-ACT-06 | 부분 구현 | `POST /oj-ai/v1/assignments/{id}/proxy-submit` | 대리 업로드 UI |
| LW-ACT-07 | 누락 | 렌더 작업 스케줄 없음 | 렌더 작업 즉시 실행 |
| LW-ACT-08 | 누락 | 확인된 구현 없음 | 제출물 재열기 |
| LW-ACT-09 | 누락 | 확인된 구현 없음 | 인계 취소 |
| LW-ACT-10 | 누락 | 확인된 구현 없음 | 인계 재전송 |
| LW-ACT-11 | 부분 구현 | 보고서 PDF 생성 서비스 존재 | 제출물 개별 PDF 렌더 API |
| LW-ACT-12 | 부분 구현 | export file metadata REST 존재 | 제출물 PDF 다운로드 버튼 |
| LW-ACT-13 | 부분 구현 | `POST /oj-ai/v1/reports/submissions` | 제출물 일괄 작업 UI와 원본 bulk action 호환 |
| LW-ACT-14 | 부분 구현 | 설정/환경 모듈 존재 | 활동지 전용 설정 화면 |

### 5.3 AI 채점 계열

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| LA-ACT-01 | 누락 | 확인된 구현 없음 | 평가 계획 저장 화면/API |
| LA-ACT-02 | 누락 | 확인된 구현 없음 | 평가 계획 삭제 |
| LA-ACT-03 | 부분 구현 | 활동지 루브릭 모드와 루브릭 스냅샷 존재 | 루브릭 전용 저장 화면 |
| LA-ACT-04 | 부분 구현 | `POST /oj-ai/v1/zip-uploads/preview`, `/map`, `/commit` | ZIP 업로드 화면 |
| LA-ACT-05 | 누락 | 확인된 구현 없음 | 학생별 개별 파일 업로드 |
| LA-ACT-06 | 누락 | 확인된 구현 없음 | 학생 파일 삭제 |
| LA-ACT-07 | 누락 | 확인된 구현 없음 | 미제출/빈 제출 사유 저장 |
| LA-ACT-08 | 부분 구현 | score/accept/reject/regrade REST 존재 | 검토 화면의 동기 action과 버튼 |
| LA-AJAX-01 | 누락 | 확인된 구현 없음 | 채점 검토 셀 저장 |
| LA-AJAX-02 | 누락 | 비동기 큐 없음 | 채점/피드백/특기사항 큐 등록 |
| LA-AJAX-03 | 누락 | 비동기 큐 없음 | 큐 즉시 진행 |
| LA-AJAX-04 | 누락 | 비동기 큐 없음 | 큐 취소 |
| LA-AJAX-05 | 누락 | 비동기 큐 없음 | 큐 상태 조회 |
| LA-ACT-09 | 부분 구현 | 학생 명단/매핑 REST 존재 | 계정 매핑 저장 화면 |
| LA-ACT-10 | 부분 구현 | 매핑 저장소 존재 | 계정 매핑 삭제 화면/API |
| LA-ACT-11 | 부분 구현 | `POST /oj-ai/v1/academic/roster/preview`, `/commit` | 학생 명단 가져오기 화면 |
| LA-ACT-12 | 부분 구현 | 실행 환경/플랫폼 설정 일부 존재 | AI 채점 전용 설정 화면 |

## 6. shortcode, frontend, 학생 경험 매핑

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| LD-SC-01 | 호환 필요 | 새 `oj_ai_teacher_dashboard`, legacy alias 일부만 존재 | 기존 교사용 shortcode alias와 렌더링 스크립트 |
| LD-SC-02 | 호환 필요 | 새 `oj_ai_student_dashboard`, legacy alias 일부만 존재 | 기존 학생용 shortcode alias와 렌더링 스크립트 |
| LW-SC-01 | 누락 | 확인된 구현 없음 | 학생 활동지 작성 shortcode 또는 독립 학생 경로 |
| LA-SC-01 | 누락 | 확인된 구현 없음 | 학생 피드백 공개 화면 |
| LA-SC-02 | 누락 | 확인된 구현 없음 | 학생 점수 공개 화면 |
| FRONT-01 | 부분 구현 | 독립 관리자 경로는 있음 | 학생 활동지/피드백/점수 가상 페이지 |

## 7. cron과 비동기 작업 매핑

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| LD-CRON-01 | 누락 | 스케줄 등록 없음 | 대시보드 분석 스케줄러 |
| LD-CRON-02 | 누락 | 문제 메타데이터 작업 없음 | 메타데이터 큐 처리 |
| LD-CRON-03 | 누락 | 교육과정 매핑 작업 없음 | 매핑 큐 처리 |
| LD-CRON-04 | 누락 | 학생 수준 스냅샷 없음 | 수준 분석 작업 |
| LW-CRON-01 | 부분 구현 | `auto_submit_due()` REST/서비스 존재 | WP-Cron 등록과 주기 실행 |
| LW-CRON-02 | 누락 | 렌더 작업 처리기 없음 | HTML/JSON/PDF 렌더 작업 큐 |
| LW-CRON-03 | 누락 | 활동지 스케줄러 없음 | 자동 제출/렌더 통합 스케줄 |
| LA-CRON-01 | 누락 | 비동기 채점 큐 없음 | 채점/피드백/특기사항 async queue |

## 8. DB 테이블 매핑

### 8.1 기존 대시보드 DB

| 잠금 ID | 판정 | 현재 대응 테이블 | 비고 |
|---|---|---|---|
| LD-DB-01 | 누락 | 없음 | 문제 메타데이터 저장소 없음 |
| LD-DB-02 | 대체 필요 | `oj_ai_curriculums`, `oj_ai_worksheet_achievement_links` | 그룹별 교육과정과 직접 대응 아님 |
| LD-DB-03 | 대체 필요 | `oj_ai_worksheet_achievement_links`, `oj_ai_question_achievement_links` | 그룹 교육과정 item과 직접 대응 아님 |
| LD-DB-04 | 구현됨 | `oj_ai_reports` | 보고서 저장소 존재 |
| LD-DB-05 | 부분 구현 | `oj_ai_export_jobs`, `oj_ai_import_runs`, `oj_ai_assessment_runs` | 분석/메타데이터 통합 job은 없음 |
| LD-DB-06 | 부분 구현 | `oj_ai_special_notes` | 평가 결과 기반 특기사항으로 대체 |
| LD-DB-07 | 누락 | 없음 | 학생 수준 스냅샷 없음 |
| LD-DB-08 | 구현됨 | `oj_ai_achievement_standards` | 성취기준 저장소 존재 |
| LD-DB-09 | 누락 | 없음 | 성취기준 수준 테이블 없음 |
| LD-DB-10 | 부분 구현 | `oj_ai_question_achievement_links` | OJ 문제 연결은 아직 없음 |

### 8.2 기존 활동지 제출 DB

| 잠금 ID | 판정 | 현재 대응 테이블 | 비고 |
|---|---|---|---|
| LW-DB-01 | 구현됨 | `oj_ai_worksheet_assignments` | 과제 테이블 존재 |
| LW-DB-02 | 부분 구현 | `oj_ai_student_mappings`, 과제 scope 컬럼 | 과제별 개별 member 테이블 없음 |
| LW-DB-03 | 구현됨 | `oj_ai_worksheet_templates` | 템플릿 테이블 존재 |
| LW-DB-04 | 구현됨 | `oj_ai_worksheet_submissions`, `oj_ai_submission_revisions` | draft를 제출/리비전으로 대체 |
| LW-DB-05 | 구현됨 | `oj_ai_worksheet_submissions` | 최종 제출 저장소 존재 |
| LW-DB-06 | 부분 구현 | `oj_ai_export_jobs` | 활동지 렌더 전용 job은 없음 |
| LW-DB-07 | 부분 구현 | `oj_ai_audit_logs`, `oj_ai_activity_events` | 활동지 action log 연결은 아직 부족 |

### 8.3 기존 AI 채점 DB

| 잠금 ID | 판정 | 현재 대응 테이블 | 비고 |
|---|---|---|---|
| LA-DB-01 | 누락 | 없음 | 평가 계획 테이블 없음 |
| LA-DB-02 | 부분 구현 | `oj_ai_worksheet_submissions`, `oj_ai_assessment_results` | 평가 제출 단위는 제출/결과로 분산 |
| LA-DB-03 | 부분 구현 | `oj_ai_submission_attachments`, `oj_ai_zip_uploads`, `oj_ai_zip_upload_items` | artifacts와 직접 대응 아님 |
| LA-DB-04 | 구현됨 | `oj_ai_feedback`, `oj_ai_assessment_results` | 점수/피드백 저장소 존재 |
| LA-DB-05 | 구현됨 | `oj_ai_student_mappings`, `oj_ai_academic_context_mappings` | 사용자/학번 매핑 저장소 존재 |

## 9. 출력, 가져오기, 내보내기 매핑

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| OUT-01 | 부분 구현 | `Report_Export_Service::student_report()` | 학생 보고서 화면 버튼과 한글 PDF 품질 개선 |
| OUT-02 | 부분 구현 | `Report_Export_Service::class_report()` | 학급/그룹 보고서 화면 버튼 |
| OUT-03 | 부분 구현 | `submission_bulk_report()` 또는 PDF renderer | 제출물 개별 PDF 렌더 API |
| OUT-04 | 누락 | 확인된 구현 없음 | 제출물 HTML/JSON export |
| OUT-05 | 부분 구현 | 제출물 일괄 ZIP/PDF 생성 서비스 | 제출 관리 화면의 일괄 다운로드 |
| OUT-06 | 구현됨 | 템플릿 import/export REST와 JS 버튼 | 기존 action 호환 |
| OUT-07 | 부분 구현 | ZIP preview/map/commit REST | ZIP 업로드 화면 |
| OUT-08 | 부분 구현 | `scores_csv()` | CSV 다운로드 화면 |
| OUT-09 | 부분 구현 | roster preview/commit REST | 학생 명단 가져오기 화면 |

## 10. AI 처리 절차 매핑

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| AI-01 | 부분 구현 | score endpoint, rubric hash, fingerprint, normalizer | 실제 AI provider 연결과 채점 검토 UI |
| AI-02 | 부분 구현 | 피드백 생성, 승인, 거절, 비공개 REST | 피드백 버튼과 승인 공개 화면 |
| AI-03 | 부분 구현 | 특기사항 생성과 교육과정 context 사용 | 특기사항 수정/승인/보고서 반영 화면 |
| AI-04 | 구현됨 | `change_pending`, `Regrade_Review_Repository`, accept/reject REST | 검토 화면 연결 |
| AI-05 | 부분 구현 | 정규화와 raw 응답 저장 | 정규화 실패 보존과 오류 표시 |

## 11. 통합 UX 유지 조건 매핑

| 잠금 ID | 판정 | 현재 구현 | 필요한 조치 |
|---|---|---|---|
| UX-01 | 부분 구현 | OJ 콘솔 connector 존재 | 모든 업무 화면을 콘솔에 실제 연결 |
| UX-02 | 부분 구현 | 독립 관리자 shell 존재 | 상태표를 실제 화면으로 교체 |
| UX-03 | 부분 구현 | 일부 REST 저장/처리 존재 | 메뉴에서 생성/수정/업로드/채점/출력을 직접 수행하게 개선 |
| UX-04 | 부분 구현 | 일부 버튼은 새 REST/JS로 존재 | 원본 핵심 버튼/action 동등 기능 연결 |
| UX-05 | 부분 구현 | autosave backend 존재 | 학생 작성 UI, 미디어 업로드, cron 자동 제출 |
| UX-06 | 부분 구현 | 결과/피드백/재채점 백엔드 존재 | 교사용 검토 화면 |
| UX-07 | 구현됨 | OJ 연결 시 OJ 그룹 우선, 독립 시 로컬 그룹 | 실제 플랫폼 데이터 검증 |
| UX-08 | 부분 구현 | 학년도/학년/반 DB와 REST 존재 | 조회/필터 UI 강화 |

## 12. 3단계로 넘길 구현 대상

3단계는 메뉴와 화면 구조 재설계 단계이므로 다음 화면을 우선 배치해야 한다.

| 메뉴 | 배치할 실제 화면 | 관련 잠금 ID |
|---|---|---|
| 교사 대시보드 | 학년도/학년/반 필터, 학생 목록, OJ 제출/문제/활동지/채점 요약, 보고서 버튼 | LD-DASH-01, LD-ACT-01, LD-ACT-02 |
| 학생 대시보드 | 제출 현황, 공개 피드백, 점수, 활동지 바로가기 | LD-DASH-02, LA-SC-01, LA-SC-02 |
| 활동지 | 템플릿, 과제 배포, 제출 관리, 로그 탭 | LW-TPL-01, LW-ASG-01, LW-SUB-01, LW-LOG-01 |
| AI 채점 | 평가 계획, 루브릭, 자료 업로드, 채점 검토, 재채점 검토 | LA-PLAN-01, LA-RUBRIC-01, LA-UPLOAD-01, LA-REVIEW-01 |
| 보고서 | 학생/학급 PDF, 제출물 일괄 PDF/ZIP, 점수 CSV | OUT-01, OUT-02, OUT-05, OUT-08 |
| 데이터 가져오기 | 기존 데이터 preview/commit, 학생 명단 가져오기, 템플릿 import/export | OUT-06, OUT-09 |
| 설정 | 실행 환경, 플랫폼 연결, AI provider, 호환 action/shortcode 설정 | LD-ACT-12, LA-ACT-12 |

## 13. 2단계 완료 판정

| 확인 항목 | 결과 |
|---|---|
| 1단계 잠금 ID를 현재 코드와 대조했는가 | 완료 |
| 화면, 버튼, shortcode, cron, DB, 출력 기능별 상태를 기록했는가 | 완료 |
| 누락 기능을 다음 구현 대상 화면/서비스 단위로 분해했는가 | 완료 |
| 상태표 페이지와 기존 사용자 경험 미대체 문제를 표시했는가 | 완료 |
| 3단계에서 재설계할 메뉴/화면 입력을 정리했는가 | 완료 |
