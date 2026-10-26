# OJ AI Dashboard 제출 흐름 설계

문서 상태: 확정
작성일: 2026-06-14
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: submission-autosave-zip -->
<!-- HARNESS: assignment-distribution -->
<!-- HARNESS: student-writing -->
<!-- HARNESS: teacher-submission-management -->
<!-- HARNESS: zip-individual-upload -->

## 1. 범위

7단계부터 이어지는 제출 흐름은 활동지 과제 배포, 학생 작성, 자동저장, 직접 제출, 마감 자동 제출, 대리 제출, ZIP 업로드 미리보기/매핑/확정을 구현한다.

## 2. 과제 배포

교사는 템플릿을 선택해 학년도, 학년, 반, 그룹, 개별 학생을 대상으로 과제를 배포한다.

- OJ 연결 모드에서는 OJ 그룹 원본 ID를 보존한다.
- 독립 모드에서는 자체 학년도, 학년, 반, 학생 명단을 사용한다.
- 시작일은 `settings_json.starts_at`, 마감일은 `due_at`에 저장한다.
- 자동 제출과 대리 제출 허용 여부는 `settings_json`에 고정한다.

## 3. 학생 작성

학생 대시보드는 현재 로그인한 학생에게 배정된 과제만 표시한다.

- 직접 학생 지정, 학년도/학년/반 지정, 로컬 그룹 멤버십을 기준으로 배정 여부를 판정한다.
- 활동지를 열면 학생용 Plate 문서와 학생 스키마만 내려주며, 정답, 예시답안, 배점, 루브릭, 교사용 메모는 제외한다.
- 기존 제출물이 있으면 `content_json`과 `server_revision`을 함께 내려주어 새로고침 후 마지막 자동저장 상태를 복구한다.
- 파일과 이미지는 먼저 첨부 업로드 REST로 저장하고, 반환된 파일 메타데이터를 답안 본문에 포함한다.
- 그림 답안은 캔버스에서 작성한 뒤 이미지 첨부로 저장하고 답안 본문에 연결한다.

## 4. 자동저장

자동저장은 `assignment_id + student_user_id` 단위로 하나의 제출물을 유지한다.

- 클라이언트는 `autosave_session_id`, `base_revision`, `client_revision`, `idempotency_key`를 보낸다.
- 서버는 `server_revision`이 `base_revision`과 같을 때만 새 revision을 저장한다.
- 같은 `idempotency_key` 요청은 기존 revision 결과를 반환한다.
- revision 충돌 시 본문을 덮어쓰지 않고 `autosave_conflict` 응답을 반환한다.
- 첨부 답안 상태는 제출 본문과 별도 `submission_attachments` 행으로 분리한다.

## 5. 제출

학생 제출은 마지막 저장본 또는 요청 본문을 기준으로 `submitted` 상태가 된다. 미완료 첨부가 있으면 `submitted_with_pending_attachment_review` 상태로 둔다.

교사 대리 제출은 `proxy_submitted` 상태로 저장하며, 요청의 `student_user_id`가 필요하다.

마감 자동 제출은 마감이 지난 배정 중 자동 제출이 켜진 과제의 `draft`, `autosave_conflict` 제출물을 마지막 성공 저장본 기준으로 `auto_submitted` 상태로 전환한다.

## 6. ZIP 업로드

ZIP 업로드는 확정 전에 반드시 preview를 거친다.

1. ZIP 항목 목록 또는 업로드 파일을 검사한다.
2. path traversal, 절대 경로, 허용되지 않은 확장자, 파일 수, 파일 크기, 총 크기를 차단한다.
3. 파일명을 학생 명단과 자동 매칭한다. 파일명 정규화는 한글을 포함한 유니코드 문자와 숫자를 보존한다.
4. 교사가 미리보기에서 수동 매핑을 보완한다.
5. `auto_matched`, `mapped` 상태의 항목만 제출물로 확정한다.
6. 업로드된 ZIP 내부 파일은 안전 경로로 복사되어 제출 첨부 메타데이터에 연결된다.

자동 매칭 우선순위:

1. 로그인 ID
2. 번호+이름
3. 번호
4. 이름

동명이인, 중복 후보, 낮은 신뢰도 항목은 `needs_manual_mapping` 상태로 둔다. 안전 검사에 실패한 항목은 `rejected` 상태로 둔다.

## 7. 교사용 제출 관리

교사는 활동지 화면의 제출 관리 패널에서 제출물을 조회하고 상세를 확인한다.

- 제출 목록은 과제, 학년도, 학년, 반, 제출 상태로 필터링한다.
- 제출 상세는 과제 정보, 학생 정보, 문항, 학생 답안, 첨부 상태, 서버 리비전을 함께 표시한다.
- 제출 완료, 자동 제출, 대리 제출, 첨부 검토 상태는 채점 대기 또는 검토 보류 상태로 표시한다.
- 자동저장 충돌 상태는 채점 전달 보류로 표시한다.
- 재열기는 제출 상태를 `draft`로 되돌리고 `submitted_at`, `locked_at`을 비워 다시 작성할 수 있게 한다.
- 대리 제출은 교사가 학생 ID와 과제를 지정해 `proxy_submitted` 상태 제출물을 만든다.
- 대리 업로드는 교사가 파일을 선택하면 `proxy_upload_submission` 본문을 만들고 `proxy_submitted` 상태 제출물로 확정한다.
- ZIP 제출물 패널은 ZIP 파일 미리보기, 학생 매핑, 항목 제외, 매핑 저장, 확정 제출을 수행한다.
- 개별 제출 패널은 학생별 파일 업로드와 미참여, 장기결석, 결원, 기타 사유 제출을 수행한다.
- 개별 PDF는 학생 보고서 출력 흐름을 사용하되 선택 제출의 과제와 학생 필터를 함께 전달한다.
- 일괄 PDF/ZIP은 제출물 일괄 보고서 흐름을 사용하며 과제와 학급 필터를 반영한다.

## 8. REST

- `GET /oj-ai/v1/student/assignments`
- `GET /oj-ai/v1/student/assignments/{id}`
- `GET /oj-ai/v1/submissions`
- `GET /oj-ai/v1/submissions/{id}`
- `POST /oj-ai/v1/submissions/{id}/reopen`
- `GET /oj-ai/v1/assignments`
- `POST /oj-ai/v1/assignments`
- `GET /oj-ai/v1/assignments/{id}`
- `POST /oj-ai/v1/assignments/{id}/attachments`
- `POST /oj-ai/v1/assignments/{id}/autosave`
- `POST /oj-ai/v1/assignments/{id}/submit`
- `POST /oj-ai/v1/assignments/{id}/proxy-submit`
- `POST /oj-ai/v1/assignments/{id}/proxy-upload`
- `POST /oj-ai/v1/assignments/{id}/auto-submit`
- `POST /oj-ai/v1/assignments/auto-submit-due`
- `POST /oj-ai/v1/zip-uploads/preview`
- `POST /oj-ai/v1/zip-uploads/{id}/map`
- `POST /oj-ai/v1/zip-uploads/{id}/commit`

모든 쓰기 요청은 로그인과 nonce 검사를 통과해야 한다. 과제 배포는 과제 배포 권한, 대리 제출과 ZIP 업로드는 제출물 관리 권한을 요구한다.

## 9. 완료 기준

- 교사가 템플릿을 대상으로 선택해 과제를 배포할 수 있다.
- 학생이 배정 활동지를 열고 마지막 저장본을 복구할 수 있다.
- 학생이 텍스트, 선택형, 표, 첨부, 그림 답안을 저장하고 제출할 수 있다.
- 자동저장 revision 충돌을 감지한다.
- 같은 idempotency key 재요청은 중복 revision을 만들지 않는다.
- 제출과 대리 제출 상태가 구분된다.
- 교사가 제출 목록, 상세 미리보기, 재열기, 대리 제출, 개별 PDF, 일괄 PDF/ZIP을 실행할 수 있다.
- 마감 자동 제출이 기존 draft 제출물을 전환한다.
- ZIP preview/map/commit 흐름이 분리된다.
- 안전하지 않은 ZIP 항목은 제출물로 확정되지 않는다.
- ZIP 확정 제출은 실제 파일 첨부 메타데이터와 함께 제출 목록 및 채점 대기 상태로 전달된다.
- 개별 업로드와 사유 제출은 제출 상세에서 확인할 수 있다.
