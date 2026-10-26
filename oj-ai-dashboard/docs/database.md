# OJ AI Dashboard 데이터베이스 설계와 마이그레이션

문서 상태: 확정  
작성일: 2026-06-14  
대상 단계: 7단계

<!-- HARNESS: database-migration -->

## 1. 목적

7단계의 목적은 이후 기능 구현이 직접 SQL을 흩뿌리지 않도록 테이블 이름, schema, migration, repository 접근 방식을 먼저 고정하는 것이다.

## 2. 구현 클래스

| 클래스 | 책임 |
|---|---|
| `Database\Table_Names` | 논리 테이블 키와 고정 물리 테이블명 매핑 |
| `Database\Status_Values` | DB enum으로 보호할 상태값 목록 |
| `Database\Schema` | dbDelta 호환 CREATE TABLE SQL 생성 |
| `Database\Migrations\Migration_Interface` | migration 계약 |
| `Database\Migrations\Migration_202606140001_Create_Tables` | 초기 테이블 생성 migration |
| `Database\Migrator` | 설치된 migration 추적과 실행 |
| `Database\Abstract_Repository` | 공통 insert/update/get/soft delete 접근 |

활성화 시 `Core\Activation`이 `Database\Migrator::install()`을 실행한다.

## 3. Migration 정책

- schema version은 `202606140001`부터 시작한다.
- 실행된 migration id는 `oj_ai_dashboard_installed_migrations` option에 저장한다.
- 현재 schema version은 `oj_ai_dashboard_db_version` option에 저장한다.
- 각 migration은 한 번만 실행되지만, 테이블 생성 자체는 `dbDelta()`를 사용해 재실행 안전성을 유지한다.
- 후속 schema 변경은 기존 migration을 수정하지 않고 새 migration class를 추가한다.

## 4. 새 테이블 목록

| 영역 | 테이블 |
|---|---|
| 기본/학사 | `oj_ai_academic_years`, `oj_ai_classes`, `oj_ai_groups`, `oj_ai_group_members`, `oj_ai_academic_context_mappings`, `oj_ai_student_mappings`, `oj_ai_activity_events`, `oj_ai_audit_logs` |
| 활동지/제출 | `oj_ai_worksheet_templates`, `oj_ai_worksheet_questions`, `oj_ai_worksheet_assignments`, `oj_ai_worksheet_submissions`, `oj_ai_submission_revisions`, `oj_ai_submission_attachments`, `oj_ai_zip_uploads`, `oj_ai_zip_upload_items` |
| 교육과정 | `oj_ai_curriculums`, `oj_ai_achievement_standards`, `oj_ai_worksheet_achievement_links`, `oj_ai_question_achievement_links` |
| 채점 | `oj_ai_assessment_runs`, `oj_ai_grading_inputs`, `oj_ai_rubric_snapshots`, `oj_ai_grading_items`, `oj_ai_assessment_results`, `oj_ai_regrade_reviews` |
| 피드백/특기사항 | `oj_ai_feedback`, `oj_ai_feedback_approvals`, `oj_ai_special_notes` |
| 보고서/출력 | `oj_ai_reports`, `oj_ai_export_jobs`, `oj_ai_export_files` |
| 가져오기/호환 | `oj_ai_import_runs`, `oj_ai_import_items` |

실제 테이블명은 WordPress `$wpdb->prefix`를 붙이지 않은 고정 `oj_ai_*` 이름이다.

## 5. 기존 기능 테이블 매핑

기존 테이블은 실제 이름을 새 코드에 그대로 남기지 않고 source key와 logical table로만 다룬다.

| 기존 source key | 기존 logical table | 새 대상 |
|---|---|---|
| `legacy-dashboard` | `problem_metadata` | OJ 원본 문제 참조 또는 `oj_ai_activity_events`; 문제 메타데이터 테이블은 새로 만들지 않음 |
| `legacy-dashboard` | `group_curriculums`, `group_curriculum_items` | `oj_ai_curriculums`, `oj_ai_achievement_standards`, `oj_ai_worksheet_achievement_links` |
| `legacy-dashboard` | `reports`, `jobs` | `oj_ai_reports`, `oj_ai_export_jobs`, `oj_ai_export_files` |
| `legacy-dashboard` | `student_notes` | `oj_ai_special_notes` |
| `legacy-dashboard` | `student_level_snapshots` | `oj_ai_activity_events`, `oj_ai_reports` |
| `legacy-worksheet-submission` | `templates` | `oj_ai_worksheet_templates`, `oj_ai_worksheet_questions` |
| `legacy-worksheet-submission` | `assignments`, `assignment_members` | `oj_ai_worksheet_assignments`, `oj_ai_group_members` |
| `legacy-worksheet-submission` | `drafts`, `submissions` | `oj_ai_worksheet_submissions`, `oj_ai_submission_revisions`, `oj_ai_submission_attachments` |
| `legacy-worksheet-submission` | `render_jobs` | `oj_ai_export_jobs`, `oj_ai_export_files` |
| `legacy-worksheet-submission` | `action_logs` | `oj_ai_audit_logs`, `oj_ai_activity_events` |
| `legacy-assessment` | `plans` | `oj_ai_worksheet_templates`, `oj_ai_rubric_snapshots` |
| `legacy-assessment` | `submissions`, `artifacts` | `oj_ai_worksheet_submissions`, `oj_ai_submission_attachments`, `oj_ai_zip_upload_items` |
| `legacy-assessment` | `feedback` | `oj_ai_assessment_results`, `oj_ai_feedback`, `oj_ai_special_notes` |
| `legacy-assessment` | `user_mappings` | `oj_ai_student_mappings`, `oj_ai_academic_context_mappings` |

기존 데이터 가져오기는 자동 migration이 아니라 18단계의 preview/commit 가져오기 흐름에서 실행한다.

## 6. 상태값 보호

상태값은 `Database\Status_Values`에 정의하고 schema에서는 DB enum으로 선언한다.

보호 대상:

- 제출 상태
- 첨부 상태
- ZIP 항목 상태
- job 상태
- 채점 결과 상태
- 재채점 검토 상태
- 피드백 상태
- 특기사항 상태
- 보고서 상태
- 가져오기 항목 상태

## 7. Unique key 기준

중복 방지를 위해 다음 식별자는 DB unique key로 보호한다.

- 학년도: `tenant_context + academic_year`
- 학급: `tenant_context + academic_year + grade + class_name`
- 학생 명단 번호: `tenant_context + academic_year + grade + class_name + student_number`
- 활동지 문항: `template_id + question_id`
- 제출물: `assignment_id + student_user_id`
- 제출 revision: `submission_id + revision`
- idempotency key: autosave, export, import 요청
- ZIP 항목 경로: `zip_upload_id + original_path`
- 성취기준 연결: 활동지/문항별 중복 연결 방지
- 채점 fingerprint: 같은 조건의 채점 결과 중복 생성 방지
- 재채점 검토: `current_result_id + proposed_result_id`

## 8. Repository 원칙

- 기능 모듈은 직접 테이블명을 만들지 않고 `Table_Names`를 사용한다.
- 기능 모듈은 직접 `$wpdb` 호출을 흩뿌리지 않고 repository class를 통해 접근한다.
- 기능별 전용 repository를 사용하고, generic repository는 두지 않는다.
- 삭제는 기본적으로 `deleted_at`을 기록하는 soft delete를 사용한다.
- 학생 API와 학생 화면용 repository는 학생 공개 schema만 반환하도록 별도 구현한다.
## 9. 2026-08-09 테이블명 결정

물리 테이블명에는 WordPress `$wpdb->prefix`를 붙이지 않는다. 모든 애드온 테이블은 `oj_ai_*` 고정 이름을 사용한다.

`Database\Table_Names::table()`은 logical table key를 받아 고정 물리 테이블명을 반환해야 한다. `Table_Names::prefix()`는 호환을 위해 남기더라도 빈 문자열을 반환한다.

과거 버전이 생성했을 수 있는 `{당시 WordPress prefix}oj_ai_*` 테이블은 읽기 전용 레거시 source다. 기본 `wp_oj_ai_*`뿐 아니라 멀티사이트의 `wp_2_oj_ai_*`와 사용자 정의 prefix 변형도 포함한다. 자동 삭제·rename·adoption하지 않고 별도 migration 또는 가져오기 도구에서 owner·version을 확인한 뒤 감지, 미리보기, 명시 승인, 복사 순서로 전환한다.
