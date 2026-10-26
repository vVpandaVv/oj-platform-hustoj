# OJ AI Dashboard 설계서

문서 상태: 확정  
작성일: 2026-06-13  
대상 플러그인: `oj-ai-dashboard`

## 1. 설계 원칙

`oj-ai-dashboard`는 기존 AI 대시보드, 활동지 제출, AI 채점 기능을 하나의 플러그인으로 통합한다. 단순히 메뉴를 합치는 방식이 아니라, 학생 활동과 교사 평가 업무가 하나의 데이터 흐름으로 이어지도록 재설계한다.

핵심 원칙은 다음과 같다.

- OJ 플랫폼이 있으면 OJ 데이터를 기준으로 사용한다.
- OJ 플랫폼이 없으면 독립형 학년도, 학년, 반, 그룹 기능을 사용한다.
- OJ 문제 정보와 코드 제출 채점 기록은 읽기 중심으로 참조한다.
- 활동지, AI 채점, 피드백, 특기사항, 보고서는 새 플러그인이 저장하고 운영한다.
- 채점, 피드백, 특기사항 생성은 각각 별도 버튼과 별도 AI 요청으로 분리한다.
- 채점 기준은 snapshot으로 고정하여 재채점 시 점수 변동을 통제한다.
- 피드백은 교사 승인 후 공개한다.
- 재채점 변경은 검토 상태로 보관하고 교사 결정 후 반영한다.
- 학생용 API는 정답, 예시답안, 배점, 교사용 메모를 응답하지 않는다.
- 성취기준 본문은 채점 컨텍스트가 아니라 특기사항 생성 컨텍스트로 사용한다.
- 학생 공개 피드백과 공식 특기사항은 확정된 채점 결과를 기준으로 생성한다.

## 2. 전체 구조

```text
oj-ai-dashboard
├─ Core
├─ Environment
├─ Platform
├─ Users
├─ Academic
├─ Worksheet
├─ Submission
├─ Assessment
├─ Feedback
├─ SpecialNote
├─ Curriculum
├─ Dashboard
├─ Reports
├─ Export
├─ Import
├─ Compatibility
└─ Admin
```

## 3. 주요 모듈

### 3.1 Core

플러그인 부트스트랩과 공통 기반을 담당한다.

- `Plugin`
- `Autoloader`
- `Activation`
- `Deactivation`
- `Capabilities`
- `Module_Manager`
- `Assets`
- `Router`
- `Rest_Controller`
- `Job_Queue`
- `Logger`

### 3.2 Environment

실행 환경과 기능 플래그를 판단한다.

- `Platform_Detector`
- `Feature_Flags`
- `Mode_Resolver`
- `Security_Context`

주요 기능 플래그:

| 플래그 | OJ 연결 모드 | 독립 모드 |
|---|---:|---:|
| `oj_platform_available` | true | false |
| `oj_groups_enabled` | true | false |
| `local_groups_enabled` | false | true |
| `oj_code_grading_enabled` | true | false |
| `oj_problem_analysis_enabled` | true | false |
| `worksheet_enabled` | true | true |
| `ai_assessment_enabled` | true | true |
| `legacy_shortcode_compat_enabled` | true | true |

### 3.3 Platform

OJ 플랫폼 연결을 어댑터로 분리한다.

```text
Platform
├─ Oj_Connector
├─ Oj_User_Connector
├─ Oj_Group_Connector
├─ Oj_Problem_Connector
├─ Oj_Submission_Connector
└─ Oj_Menu_Connector
```

연결 원칙:

- OJ 플랫폼이 있으면 connector가 실제 데이터를 읽는다.
- OJ 플랫폼이 없으면 `Oj_Connector`가 각 읽기 connector의 비활성 상태를 조합해 빈 결과와 fallback 상태를 반환한다.
- 새 플러그인은 OJ 원본 문제, 제출, 채점 데이터를 불필요하게 복제하지 않는다.
- 분석과 보고서 생성을 위해 필요한 최소 연결 키만 저장한다.

### 3.4 Academic

학년도, 학년, 반, 그룹, 학생 소속을 관리한다.

OJ 연결 모드:

- OJ 그룹을 우선 사용
- 로컬 그룹 생성 UI 숨김
- OJ 그룹 또는 사용자와 학년도, 학년, 반, 번호를 연결하는 학사 컨텍스트 매핑 저장
- 명단 가져오기는 학생 매핑 보조로 제한

독립 모드:

- 자체 학년도, 학년, 반, 그룹 생성
- 학생 명단 CSV/XLSX 가져오기
- 교사 담당 반 지정

주요 클래스:

- `Academic_Year_Service`
- `Classroom_Service`
- `Group_Context_Service`
- `Academic_Context_Mapping_Service`
- `Local_Group_Repository`
- `Roster_Import_Service`
- `Student_Mapping_Service`

### 3.5 Worksheet

Plate 기반 활동지 제작과 템플릿 관리를 담당한다.

주요 클래스:

- `Worksheet_Template_Service`
- `Plate_Document_Service`
- `Plate_Editor_Integration_Service`
- `Plate_Extension_Registry`
- `Form_Schema_Extractor`
- `Worksheet_Assignment_Service`
- `Worksheet_Template_Import_Service`
- `Worksheet_Template_Export_Service`
- `Worksheet_Renderer`

Plate 통합 원칙:

- Plate 편집기는 기존 템플릿 빌더/편집기 구조를 통째로 가져와 확장한다.
- Plate의 provider, editor shell, toolbar, floating toolbar, slash menu, context menu, portal/root layer 구조를 유지한다.
- 개별 Plate 패키지나 개별 모듈을 새로 조립해서 유사 편집기를 만들지 않는다.
- 커스텀 입력 블록, schema 추출, 저장/자동저장, 채점 metadata만 extension 계층으로 추가한다.
- 편집기 외부 관리자 shell의 overflow, z-index, stacking context가 Plate floating layer를 가리지 않게 한다.
- floating layer는 가능하면 document/body 또는 전용 portal root에 렌더링하고, 부모 카드/패널의 `overflow: hidden` 안에 갇히지 않게 한다.

저장 구조:

```text
worksheet_template
├─ plate_value_json
├─ form_schema_json
├─ rubric_mode: worksheet | question
├─ worksheet_rubric_json
├─ curriculum_links_json
└─ settings_json
```

### 3.6 Submission

학생 작성, 자동저장, 제출, 마감 자동 제출, 대리 제출, ZIP 업로드를 담당한다.

주요 클래스:

- `Draft_Autosave_Service`
- `Submission_Service`
- `Submission_Lock_Service`
- `Deadline_Auto_Submit_Service`
- `Proxy_Submission_Service`
- `Zip_Upload_Service`
- `Zip_Classification_Service`
- `Zip_Safety_Validator`
- `Attachment_Service`
- `Attachment_Autosave_Service`
- `Submission_Pdf_Bulk_Service`

자동저장 설계:

```text
학생 입력 변경
-> 브라우저 임시 큐
-> REST autosave(idempotency_key, autosave_session_id, base_revision)
-> 서버 revision 저장
-> 첨부파일 상태와 본문 상태 분리 저장
-> 저장 성공 시 client_revision 갱신
-> 실패 시 브라우저 재시도 큐 유지
-> 복구 가능 상태 표시
```

충돌 방지:

- 제출물별 `server_revision` 저장
- 클라이언트 요청에 `base_revision` 포함
- 서버 revision과 다르면 충돌 응답
- 충돌 시 최신 데이터 병합 또는 교사/학생 선택 UI 제공
- 같은 `idempotency_key` 요청은 한 번만 처리
- 여러 탭은 `autosave_session_id`로 구분하고 최신 활성 세션을 표시
- 마감 자동 제출은 마지막 성공 저장본을 기준으로 트랜잭션 처리
- 미완료 첨부가 있으면 자동 제출 상태를 `submitted_with_pending_attachment_review`로 둔다

ZIP 업로드 분류:

```text
ZIP 업로드
-> 안전 검사(path traversal, 압축 크기, 파일 확장자, 파일 수)
-> 압축 해제
-> 학생 매칭 후보 생성
-> 신뢰도 계산
-> 교사 미리보기
-> 수동 매핑 보완
-> 확정 후 제출물 생성
```

자동 매칭 우선순위:

```text
1. 로그인 ID
2. 번호+이름
3. 번호
4. 이름
```

동명이인, 중복 번호, 낮은 신뢰도, 매칭 실패 파일은 `needs_manual_mapping` 상태로 두고 제출물로 확정하지 않는다.

### 3.7 Assessment

AI 채점과 재채점 변경 검토를 담당한다.

주요 클래스:

- `Assessment_Run_Service`
- `Grading_Input_Builder`
- `Rubric_Snapshot_Service`
- `Grading_Fingerprint_Service`
- `Ai_Scoring_Service`
- `Scoring_Normalizer`
- `Score_Calculator`
- `Regrade_Review_Service`
- `Assessment_State_Service`
- `Assessment_Result_Repository`

채점 버튼의 책임:

- 질문과 답변을 함께 구성
- 루브릭 snapshot 고정
- 점수 산출
- 채점 검토의견 생성
- 변경 검토 상태 생성
- 성취기준 연결 ID는 결과 메타데이터로 저장하되 본문은 기본 채점 컨텍스트에 포함하지 않음

채점 버튼이 하지 않는 일:

- 학생 피드백 생성
- 특기사항 생성
- 피드백 공개

채점 결과 상태:

```text
draft -> review_required -> accepted
draft -> accepted
accepted -> change_pending -> accepted
accepted -> change_pending -> rejected
```

공식 피드백, 공식 특기사항, 학생 공개, 확정 보고서에는 `accepted` 결과만 사용한다.

### 3.8 Feedback

학생용 피드백 생성과 승인 공개를 담당한다.

주요 클래스:

- `Feedback_Generation_Service`
- `Feedback_Approval_Service`
- `Feedback_Publication_Service`
- `Feedback_Repository`

피드백 입력 조건:

- `accepted` 채점 결과
- 교사 확정 검토의견
- 학생 답변 요약

미확정 채점 결과를 사용한 피드백은 교사용 초안으로만 저장하고 공개할 수 없다.

피드백 상태:

```text
teacher_draft -> pending_approval -> approved -> published
                              -> rejected
published -> unpublished
```

### 3.9 SpecialNote

특기사항 생성을 담당한다.

주요 클래스:

- `Special_Note_Generation_Service`
- `Special_Note_Repository`
- `Curriculum_Context_Builder`

특기사항 입력:

- `accepted` 채점 결과
- 교사 검토의견
- 승인된 피드백
- 활동지 단위 성취기준
- 문항별 성취기준
- 교육과정 설명

특기사항은 학생 생활기록 또는 보고서에 쓰일 수 있으므로, 미확정 재채점 결과나 승인되지 않은 피드백을 공식 입력으로 사용하지 않는다.

### 3.10 Curriculum

교육과정과 성취기준을 관리한다.

주요 클래스:

- `Curriculum_Service`
- `Achievement_Standard_Service`
- `Worksheet_Curriculum_Link_Service`
- `Question_Curriculum_Link_Service`

성취기준 연결 방식:

- 활동지 전체 연결
- 문항별 연결

문제 메타데이터 관리는 제공하지 않는다.

### 3.11 Dashboard

교사용, 학생용 대시보드를 제공한다.

주요 클래스:

- `Teacher_Dashboard_Service`
- `Student_Dashboard_Service`
- `Dashboard_Query_Service`
- `Activity_Event_Service`
- `Activity_Aggregator`

교사용 대시보드:

- 학년도, 학년, 반 필터
- 학생별 현황
- 제출률
- 채점 완료율
- 피드백 승인 현황
- 특기사항 생성 현황
- OJ 연결 모드의 코드 제출/문제 풀이 분석
- 보고서 출력 진입점

학생용 대시보드:

- 배정 활동지
- 작성 중 활동지
- 제출 현황
- 점수
- 승인된 피드백
- 본인 보고서
- OJ 연결 모드의 코드 제출 학습 흐름

### 3.12 Reports와 Export

PDF와 CSV 출력을 담당한다.

주요 클래스:

- `Student_Report_Service`
- `Class_Report_Service`
- `Report_Renderer`
- `Pdf_Export_Service`
- `Csv_Score_Export_Service`
- `Bulk_Pdf_Download_Service`

PDF 포함 옵션:

- 점수
- 문항별 답안
- 채점 검토의견
- 승인된 학생 피드백
- 특기사항
- 성취기준
- 첨부파일 요약
- OJ 코드 제출 요약

PDF 기본 프리셋:

```text
student: 점수, 승인된 피드백, 학생용 보고서
teacher_detail: 점수, 문항별 답안, 채점 검토의견, 첨부 요약
class_official: 점수, 특기사항, 성취기준, 학급 요약
```

CSV 출력:

- 학년도
- 학년
- 반
- 번호
- 학생명
- 활동지
- 문항별 점수
- 총점
- 채점 상태
- 피드백 공개 상태
- 특기사항 생성 상태

### 3.13 Import

기존 데이터 가져오기와 학생 명단 가져오기를 담당한다.

주요 클래스:

- `Legacy_Import_Controller`
- `Legacy_Dashboard_Importer`
- `Legacy_Worksheet_Importer`
- `Legacy_Assessment_Importer`
- `Import_Run_Repository`
- `Import_Item_Repository`
- `Roster_Import_Service`
- `Roster_Preview_Service`
- `Roster_Commit_Service`

가져오기 정책:

- 자동 가져오기 없음
- 관리자 수동 실행
- 원본 데이터 수정 없음
- 중복 기본 skip
- overwrite 옵션 제공
- `source_type`, `source_key`, `source_id` 저장

학생 명단 가져오기 정책:

- 독립 모드 필수 컬럼: 학년도, 학년, 반, 번호, 이름
- 선택 컬럼: 로그인 ID, 이메일
- OJ 연결 모드에서는 OJ 사용자와 학사 컨텍스트를 매핑하는 용도로 사용
- 가져오기 전 미리보기, 오류 행 표시, 중복 행 표시, 취소를 제공

### 3.14 Compatibility

기존 shortcode와 일부 기존 데이터 구조 호환을 담당한다.

주요 클래스:

- `Legacy_Shortcode_Registry`
- `Legacy_Route_Mapper`
- `Legacy_Data_Mapper`

## 4. 데이터 모델 초안

### 4.1 기본 테이블

```text
oj_ai_academic_years
oj_ai_classes
oj_ai_groups
oj_ai_group_members
oj_ai_academic_context_mappings
oj_ai_student_mappings
oj_ai_activity_events
oj_ai_audit_logs
```

OJ 연결 모드에서는 `groups`와 `group_members`를 로컬 원본으로 쓰지 않는다. 필요한 경우 매핑과 캐시성 데이터만 저장한다.

`oj_ai_academic_context_mappings`는 OJ 원본을 로컬 학사 컨텍스트에 연결한다.

핵심 필드:

- `source_type`: `oj_group`, `oj_user`, `local_group`, `local_user`
- `source_id`
- `academic_year`
- `grade`
- `class_name`
- `student_number`
- `teacher_user_id`
- `active`
- `created_at`
- `updated_at`

### 4.2 활동지

```text
oj_ai_worksheet_templates
oj_ai_worksheet_questions
oj_ai_worksheet_assignments
oj_ai_worksheet_submissions
oj_ai_submission_revisions
oj_ai_submission_attachments
oj_ai_zip_uploads
oj_ai_zip_upload_items
```

제출 상태:

```text
draft
autosave_conflict
submitted
auto_submitted
proxy_submitted
submitted_with_pending_attachment_review
locked
```

첨부 상태:

```text
pending_upload
uploaded
linked
failed
removed
```

ZIP 항목 상태:

```text
uploaded
auto_matched
needs_manual_mapping
mapped
imported
rejected
```

### 4.3 교육과정

```text
oj_ai_curriculums
oj_ai_achievement_standards
oj_ai_worksheet_achievement_links
oj_ai_question_achievement_links
```

### 4.4 채점

```text
oj_ai_assessment_runs
oj_ai_grading_inputs
oj_ai_rubric_snapshots
oj_ai_grading_items
oj_ai_assessment_results
oj_ai_regrade_reviews
```

핵심 필드:

- `grading_fingerprint`
- `rubric_snapshot_id`
- `question_fingerprint`
- `answer_fingerprint`
- `model_key`
- `policy_version`
- `raw_ai_response`
- `normalized_result`
- `review_status`

채점 결과 상태:

```text
draft
review_required
accepted
change_pending
rejected
```

재채점 검토 상태:

```text
pending
accepted
rejected
superseded
```

### 4.5 피드백과 특기사항

```text
oj_ai_feedback
oj_ai_feedback_approvals
oj_ai_special_notes
```

피드백 상태:

```text
teacher_draft
pending_approval
approved
published
rejected
unpublished
```

특기사항 상태:

```text
draft
reviewed
approved
included_in_report
```

### 4.6 보고서와 출력

```text
oj_ai_reports
oj_ai_export_jobs
oj_ai_export_files
```

### 4.7 가져오기

```text
oj_ai_import_runs
oj_ai_import_items
```

공통 권장 컬럼:

- `id`
- `tenant_context`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`
- `deleted_at`
- `source_type`
- `source_key`
- `source_id`

긴 작업 공통 상태:

```text
queued
running
succeeded
failed
cancelled
```

## 5. 활동지 스키마

### 5.1 Schema 분리

활동지는 저장 시 하나의 원본을 가지지만, 사용 목적에 따라 세 가지 schema를 생성한다.

```text
teacher_schema: 교사용 편집/검토 schema
student_schema: 학생 작성 화면과 학생 REST API용 schema
grading_schema: 채점 입력 패키지 생성용 schema
```

`student_schema`에는 다음 필드를 절대 포함하지 않는다.

- `points`
- `answer_key`
- `sample_answer`
- `teacher_note`
- `rubric`
- `grading_policy`

이 필드는 UI에서 숨기는 것이 아니라 서버 응답 생성 단계에서 제거한다.

### 5.2 문항과 입력 블록

채점 단위는 `question_id`이고, 하나의 문항은 하나 이상의 입력 블록을 포함할 수 있다.

```json
{
  "question_id": "question_001",
  "title": "질문",
  "points": 5,
  "answer_key": "정답",
  "sample_answer": "예시답안",
  "teacher_note": "교사용 메모",
  "grading_enabled": true,
  "blocks": [
    {
      "block_id": "block_001",
      "type": "short_text",
      "label": "답안"
    }
  ]
}
```

`teacher_schema`와 `grading_schema`는 위 전체 정보를 사용할 수 있다. `student_schema`는 문항 제목, 학생에게 보일 안내, 입력 블록 구조만 포함한다.

### 5.3 지원 입력 타입

```text
checkbox
single_choice
multiple_choice
short_text
long_text
number
table
image_upload
file_upload
drawing
```

### 5.4 표 입력

표 입력은 다음 구조를 가진다.

```json
{
  "question_id": "question_table_001",
  "block_id": "block_table_001",
  "type": "table",
  "row_headers": ["1행", "2행"],
  "column_headers": ["A", "B"],
  "cells": [
    {
      "row": 0,
      "column": 0,
      "input_type": "number",
      "answer_key": "10",
      "student_value": null
    }
  ]
}
```

지원 셀 타입:

- `text`
- `number`
- `checkbox`
- `single_choice`
- `multiple_choice`

## 6. 루브릭 설계

루브릭 모드는 활동지마다 하나만 선택한다.

```text
rubric_mode = worksheet | question
```

### 6.1 활동지 전체 루브릭 모드

```text
활동지 전체 루브릭
-> 문항 배점/정답/예시답안
-> 기본 채점 정책
```

### 6.2 문항별 루브릭 모드

```text
question_id별 루브릭
-> 문항 배점/정답/예시답안
-> 기본 채점 정책
```

문항별 루브릭 모드에서 채점 대상 `question_id`에 루브릭이 없으면 채점 전 경고하고, 기본값으로 자동 채점하지 않는다.

### 6.3 금지 사항

- 활동지 전체 루브릭과 문항별 루브릭의 혼합 사용 금지
- 입력 블록별 루브릭 금지

## 7. AI 실행 분리

AI 요청은 컨텍스트를 줄이기 위해 3개 버튼으로 분리한다.

### 7.1 채점 버튼

입력:

- 질문
- 학생 답변
- 첨부파일 요약
- 배점
- 정답
- 예시답안
- 루브릭
- 채점 정책
- 성취기준 연결 ID

출력:

- 문항별 점수
- 감점 사유
- 채점 검토의견
- 검토 필요 여부

### 7.2 피드백 버튼

입력:

- 확정된 채점 결과
- 채점 검토의견
- 학생 답변 요약

출력:

- 학생용 피드백 초안

### 7.3 특기사항 버튼

입력:

- 확정된 채점 결과
- 승인된 학생 피드백
- 성취기준
- 교육과정
- 교사 검토의견

출력:

- 학생별 특기사항 초안

## 8. 채점 안정성 설계

채점 절차:

```text
1. Plate 문서에서 질문 구조 추출
2. block_id와 question_id 고정
3. 루브릭 모드 확인
4. 루브릭 snapshot 생성
5. 학생 답변 snapshot 생성
6. grading_fingerprint 계산
7. 기존 결과 재사용 가능 여부 확인
8. AI 채점 실행
9. 결과 정규화
10. 서버에서 총점 계산
11. 결과 저장
12. 재채점 변경 여부 확인
13. 변경 시 regrade_review 생성
```

`grading_fingerprint` 구성 요소:

- worksheet template id
- assignment id
- submission id
- question ids
- question content hash
- answer content hash
- attachment hash
- rubric snapshot hash
- grading policy version
- model key

재채점 정책:

```text
기존 점수와 동일 -> 기존 결과 유지
기존 점수와 다름 -> 변경 검토 필요
교사 반영 선택 -> 새 점수 적용
교사 반영 안 함 선택 -> 기존 점수 유지
```

특기사항 생성 fingerprint 구성 요소:

- student id
- worksheet id
- accepted assessment result id
- approved feedback id
- achievement standard hash
- curriculum version
- special note prompt version
- model key

## 9. 관리자 메뉴

단일 관리자 메뉴를 제공한다.

```text
OJ AI Dashboard
├─ 대시보드
├─ 학년도/학급/학생
├─ 활동지
├─ 제출물
├─ AI 채점
├─ 피드백 승인
├─ 특기사항
├─ 교육과정/성취기준
├─ 보고서/내보내기
├─ 기존 데이터 가져오기
└─ 설정
```

OJ 연결 모드에서는 AI 대시보드 루트와 하위 화면을 `/oj-console` 메뉴와 라우트에 직접 등록한다. OJ 콘솔 레지스트리를 사용할 수 없을 때만 독립 관리자 경로를 fallback으로 열고, 설정 화면에 연결 상태를 표시한다.

## 10. 화면 문구 정책

실제 플러그인 화면은 반복 사용 업무에 맞게 구성한다. 화면 본문에는 개발자용 설명 문구, 기능 설명 문단, 교사용 사용법 안내 문단을 배치하지 않는다.

허용되는 화면 문구:

- 메뉴명
- 필드 라벨
- 버튼명
- 탭명
- 상태값
- 오류 메시지
- 확인 모달의 짧은 문장
- 짧은 툴팁

화면에 넣지 않는 문구:

- 구현 의도 설명
- 개발자 메모
- 기능 소개 문단
- 교사를 위한 긴 사용 절차 설명
- 대시보드 의미를 설명하는 안내 카드
- 반복 업무 화면의 튜토리얼성 문구

자세한 사용법, 운영 절차, 예외 처리 안내는 별도 도움말 문서나 관리자 문서로 분리한다. 첫 사용자를 위한 안내가 필요하면 기본 화면에 상시 노출하지 않고, 닫을 수 있는 도움말 패널이나 별도 문서 링크로 제공한다.

## 11. Shortcode

새 shortcode:

```text
[oj_ai_student_dashboard]
[oj_ai_teacher_dashboard]
[oj_ai_worksheet_assignment]
[oj_ai_assessment_feedback]
[oj_ai_assessment_scores]
```

기존 shortcode는 `Compatibility` 모듈에서 새 shortcode 또는 새 컨트롤러로 매핑한다.

## 12. REST API 초안

```text
GET    /oj-ai/v1/environment
GET    /oj-ai/v1/dashboard/teacher
GET    /oj-ai/v1/dashboard/student
POST   /oj-ai/v1/worksheets
GET    /oj-ai/v1/worksheets/{id}
POST   /oj-ai/v1/worksheets/{id}/template-export
POST   /oj-ai/v1/worksheets/template-import/preview
POST   /oj-ai/v1/worksheets/template-import/commit
POST   /oj-ai/v1/assignments/{id}/autosave
POST   /oj-ai/v1/assignments/{id}/attachments
POST   /oj-ai/v1/assignments/{id}/submit
POST   /oj-ai/v1/assignments/{id}/proxy-submit
POST   /oj-ai/v1/zip-uploads/preview
POST   /oj-ai/v1/zip-uploads/{id}/map
POST   /oj-ai/v1/zip-uploads/{id}/commit
POST   /oj-ai/v1/assessments/{submission_id}/score
POST   /oj-ai/v1/assessments/{submission_id}/feedback
POST   /oj-ai/v1/assessments/{submission_id}/special-note
POST   /oj-ai/v1/regrade-reviews/{id}/accept
POST   /oj-ai/v1/regrade-reviews/{id}/reject
POST   /oj-ai/v1/feedback/{id}/approve
POST   /oj-ai/v1/feedback/{id}/reject
POST   /oj-ai/v1/feedback/{id}/unpublish
POST   /oj-ai/v1/reports/student
POST   /oj-ai/v1/reports/class
GET    /oj-ai/v1/jobs/{id}
GET    /oj-ai/v1/export-files/{id}/download
POST   /oj-ai/v1/exports/scores-csv
POST   /oj-ai/v1/import/legacy/preview
POST   /oj-ai/v1/import/legacy/commit
POST   /oj-ai/v1/import/roster/preview
POST   /oj-ai/v1/import/roster/commit
```

REST 공통 규칙:

- 모든 쓰기 요청은 nonce/capability 검사를 수행한다.
- 긴 작업은 즉시 결과 파일을 반환하지 않고 job id를 반환한다.
- 중복 실행 방지를 위해 지원 가능한 쓰기 요청은 `Idempotency-Key`를 받는다.
- 학생용 endpoint는 `student_schema`만 반환한다.

## 13. 구현 단계

상세 개발 절차와 총 단계 수는 `docs/development-procedure.md`를 기준으로 한다. 본 문서의 구현 단계는 기능 묶음 관점의 요약이다.

### 13.1 1단계: 기반 통합

- 새 플러그인 구조 생성
- 기존 3개 플러그인 코드 복사
- namespace, prefix, option, table 이름 충돌 정리
- 단일 관리자 메뉴 구성
- OJ 감지와 독립 모드 감지

### 13.2 2단계: 학급/사용자/OJ 연결

- OJ connector 구현
- 독립형 학년도/학년/반/학생 관리 구현
- 학생 명단 가져오기
- OJ 학사 컨텍스트 매핑 구현
- 역할과 권한 정리

### 13.3 3단계: 활동지 통합

- 기존 Plate 템플릿 빌더/편집기 구조를 통째로 가져와 통합
- Plate provider, toolbar, floating layer, portal 구조 유지
- teacher/student/grading schema 분리
- question_id와 block_id 안정화
- 입력 블록 schema 확장
- 템플릿 가져오기/내보내기
- 자동저장 안정화
- 제출, 마감 자동 제출, 대리 제출
- ZIP 업로드 분류

### 13.4 4단계: AI 채점 통합

- 루브릭 모드 구현
- 성취기준 연결
- 채점 버튼 구현
- fingerprint와 snapshot 저장
- 재채점 변경 검토 구현
- accepted 결과 기준 공개 흐름 구현

### 13.5 5단계: 피드백과 특기사항

- 피드백 버튼 구현
- 피드백 승인 공개 구현
- 특기사항 버튼 구현
- 교육과정/성취기준 컨텍스트 연결

### 13.6 6단계: 대시보드와 보고서

- 교사용 대시보드
- 학생용 대시보드
- 학생별 PDF
- 학급별 PDF
- 제출물 일괄 PDF
- 점수 CSV

### 13.7 7단계: 기존 데이터 가져오기와 호환

- 기존 데이터 가져오기 UI
- 데이터 매핑
- 중복 처리
- 기존 shortcode 호환

## 14. 검증 계획

### 14.1 기능 검증

- OJ 연결 모드에서 OJ 그룹과 제출 기록이 조회되는지 확인
- OJ 학사 컨텍스트 매핑으로 학년도, 학년, 반 필터가 동작하는지 확인
- 독립 모드에서 로컬 학급과 학생을 생성할 수 있는지 확인
- 학생 API에 정답, 예시답안, 배점, 교사용 메모가 포함되지 않는지 확인
- Plate 편집기의 상단 메뉴, 블록 메뉴, 플로팅 메뉴, 선택 영역 메뉴가 본문에 가리지 않는지 확인
- Plate 플로팅 레이어가 데스크톱과 모바일 폭에서 잘못 잘리거나 화면 밖으로 밀리지 않는지 확인
- 활동지 작성 중 자동저장이 실패 후 재시도되는지 확인
- 첨부파일 업로드 실패 후 본문 자동저장 복구가 가능한지 확인
- 마감 후 자동 제출되는지 확인
- ZIP 업로드가 학생별로 분류되는지 확인
- 낮은 신뢰도 ZIP 매칭이 수동 확인 상태로 남는지 확인
- 채점, 피드백, 특기사항이 별도 버튼으로 실행되는지 확인
- 재채점 변경이 즉시 반영되지 않고 검토 상태가 되는지 확인
- 승인된 피드백만 학생에게 공개되는지 확인
- 피드백과 특기사항이 accepted 채점 결과만 공식 입력으로 사용하는지 확인
- PDF 포함 옵션이 동작하는지 확인
- CSV 점수 출력이 동작하는지 확인

### 14.2 안정성 검증

- 같은 답안과 같은 루브릭으로 재채점 시 기존 결과를 재사용하는지 확인
- AI가 허용 범위 밖 점수를 반환할 때 정규화 또는 검토 필요 처리되는지 확인
- 여러 탭 작성 충돌을 감지하는지 확인
- 대량 PDF 생성이 백그라운드 작업으로 처리되는지 확인
- Plate 편집기 관리자 shell에 `overflow: hidden`, 낮은 `z-index`, 잘못된 stacking context가 생겨 floating layer를 가리지 않는지 확인

## 15. 구현 전 확인 항목

상세 체크리스트는 `docs/implementation-precheck.md`에서 추적한다.

| 항목 | 결정 필요 |
|---|---|
| OJ 메뉴 hook | OJ 플랫폼 내부 메뉴 hook 이름 확인 |
| OJ 사용자 매핑 | WordPress 사용자와 OJ 사용자의 기존 연결 구조 확인 |
| OJ 그룹 구조 | OJ 그룹명과 학년도/학년/반 필드 구조 확인 |
## 16. 2026-08-09 애드온 평가 재설계 기준

최신 평가 애드온 설계 기준은 `docs/ai-assessment/redesign.html`를 따른다.

- `oj-platform` 본체는 수정하지 않는다.
- 물리 테이블명은 WordPress prefix 없이 `oj_ai_*` 고정 이름을 사용한다.
- 활동지 제작은 Plate 기반 구조화 문서 에디터를 사용한다.
- AI 평가는 활동지 자체 평가와 학생 제출물 평가로 분리한다.
- 활동지 자체 평가 결과는 `oj_ai_worksheet_evaluations` 계열 테이블에 저장한다.
- 학생 제출물 평가 결과는 `oj_ai_assessment_results` 계열 테이블에 저장한다.
