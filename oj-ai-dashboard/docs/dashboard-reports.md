# 대시보드와 보고서 출력 구현 기준
문서 상태: 구현 반영  
작성일: 2026-06-14  
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: dashboard-reports-export -->
<!-- HARNESS: dashboard-analytics -->
<!-- HARNESS: output-import-completion -->

## 1. 범위

17단계는 교사용 대시보드, 학생용 대시보드, 학생별 PDF, 학급별 PDF, 제출물 일괄 PDF, 점수 CSV를 하나의 흐름으로 구현한다.

## 2. 대시보드

교사용 대시보드는 `GET /oj-ai/v1/dashboard/teacher`로 조회한다. 학년도, 학년, 반, 학생 필터를 받으며 활동지 제출, accepted 채점 결과, published 피드백, 승인 또는 보고서 포함 특기사항을 집계한다. OJ 연결이 있을 때만 `Oj_Connector`의 `list_problems`와 `list_submissions` 결과를 `oj_activity`에 포함한다. OJ 제출 기록은 가져온 학생 명단의 `oj_user` 매핑을 우선 사용해 학년도, 학년, 반 범위로 제한한다.

학생용 대시보드는 `GET /oj-ai/v1/dashboard/student`로 조회한다. 현재 로그인 사용자의 활동지 제출, accepted 채점 결과, published 피드백, 특기사항, OJ 제출 기록을 표시한다. 학생별 OJ 제출 기록은 명단의 `oj_roster`/`oj_user` 매핑을 우선 사용한다. 요청자가 임의 학생 ID를 넘겨도 학생용 대시보드는 현재 사용자 기준으로 동작한다.

교사용 화면은 학년도, 학년, 반, 학생 ID, 표시 수 필터를 제공하고 학생 수, 과제 수, 제출 완료 학생, 활동지 제출, accepted 채점, 평균, published 피드백, OJ 코드 제출을 지표 카드로 표시한다. 학급 분석, 그룹 분석, 점수 분포, 활동지 상태, 학생별 분석, 최근 활동지 제출, OJ 결과 분포, 문제 분석, 최근 OJ 제출을 같은 화면에서 확인한다.

그룹 분석은 배포 과제의 `group_id`를 기준으로 한다. OJ 연결 모드에서는 과제 배포 단계에서 OJ 그룹이 우선 반영되므로, 대시보드는 그룹 원본을 섞지 않고 배포 결과를 집계한다. 독립 모드에서는 로컬 그룹을 같은 `group_id` 축으로 집계한다.

학생용 화면은 본인 제출 수, 제출 완료 수, accepted 채점 수, 점수, published 피드백, 승인된 특기사항, OJ 코드 제출, OJ 정답을 지표 카드로 표시한다. 본인 활동지 제출, 피드백 공개 상태, 특기사항 작성 상태, OJ 결과 분포, OJ 제출 기록을 함께 보여준다.

## 3. 보고서 출력

보고서 출력은 `oj_ai_reports`, `oj_ai_export_jobs`, `oj_ai_export_files`를 사용한다. 출력 요청은 nonce와 `oj_ai_dashboard_export_reports` 권한을 통과해야 한다.

PDF 포함 옵션:

- `include_feedback`
- `include_review_comments`
- `include_special_notes`
- `include_scores`
- `include_submissions`
- `include_answers`
- `include_attachments`
- `include_standards`

성취기준은 활동지 단위 링크와 문항별 링크를 모두 읽는다. 공식 피드백과 특기사항은 accepted 결과와 published 피드백을 기준으로 한다.
검토 의견은 accepted 채점 결과의 `review_comment`를 기준으로 하며, 제출물 포함 옵션은 학생 제출 본문을 보고서에 포함한다.

## 4. REST 경로

```text
GET  /oj-ai/v1/dashboard/teacher
GET  /oj-ai/v1/dashboard/student
POST /oj-ai/v1/reports/student
POST /oj-ai/v1/reports/class
POST /oj-ai/v1/reports/submissions
POST /oj-ai/v1/exports/scores-csv
POST /oj-ai/v1/reports/scores-csv
GET  /oj-ai/v1/export-files/{id}/download
```

## 5. 출력 파일

학생별 PDF와 학급별 PDF는 `application/pdf`로 저장한다. PDF 텍스트는 한국어 출력을 위해 Korean CID font와 UTF-16BE 텍스트 스트림으로 작성한다. 제출물 일괄 출력은 `ZipArchive`가 있으면 개별 PDF를 ZIP에 담아 `application/zip`으로 저장하고, 서버에 ZIP 확장이 없으면 하나의 PDF로 대체한다.

점수 CSV는 `text/csv`로 저장하며 `academic_year`, `grade`, `class_name`, `student_number`, `student_user_id`, `display_name`, `assignment_id`, `submission_id`, `assessment_result_id`, `total_score`, `max_score`, `result_status`를 포함한다.

출력 생성 응답의 `download_url`은 `GET /oj-ai/v1/export-files/{id}/download`를 가리킨다. 이 다운로드 경로는 권한을 확인한 뒤 생성 파일을 attachment로 내려보낸다.
