# OJ AI Dashboard 입력 블록 설계

문서 상태: 확정
작성일: 2026-06-14
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: worksheet-input-blocks -->

## 1. 지원 입력 타입

활동지 입력 블록은 Plate 문서 안에서 `ojai_response` 노드로 저장하고, 서버 변환 단계에서 `teacher_schema`, `student_schema`, `grading_schema`로 분리한다.

- `checkbox`: 참/거짓 단일 체크
- `single_choice`: 단일 선택
- `multiple_choice`: 복수 선택
- `short_text`: 단답
- `long_text`: 서술
- `number`: 숫자
- `table`: 행/열 제목이 있는 표 입력
- `image_upload`: 이미지 업로드
- `file_upload`: 파일 첨부
- `drawing`: 그림 그리기 결과 첨부

`image`, `file`, `select`, `radio`, `checklist`, `multi_select` 같은 별칭은 저장 시 표준 타입으로 정규화한다.

## 2. 교사용 전용 필드

다음 필드는 교사용 편집/채점 스키마에만 남기고 학생 스키마에서는 제외한다.

- `points`: 배점
- `correct_answer`: 정답
- `example_answer`: 예시 답안
- `teacher_note`: 교사용 메모

학생 스키마에는 답안 입력에 필요한 타입, 선택지, 표 구조, 업로드 제한, 기본 답안 형태만 포함한다.

## 3. 표 입력 구조

표 입력은 행과 열을 각각 키/라벨로 저장한다.

- `rows`: 행 목록
- `columns`: 열 목록
- `row_headers`: 학생 화면과 출력용 행 제목
- `column_headers`: 학생 화면과 출력용 열 제목
- `cells`: 행/열 조합별 셀 스키마

표 셀 타입은 `text`, `number`, `checkbox`, `single_choice`, `multiple_choice`를 지원한다. 선택형 셀은 셀 단위 선택지를 가질 수 있다.

## 4. 답안 스키마

각 학생 문항은 `answer_schema`를 가진다.

- 체크박스 기본값: `false`
- 단일 선택 기본값: 빈 문자열
- 복수 선택 기본값: 빈 배열
- 단답/서술 기본값: 빈 문자열
- 숫자 기본값: `null`
- 표 기본값: 행/열 키 기반 객체
- 이미지/파일 기본값: `{ "files": [] }`
- 그림 기본값: `{ "drawing": null }`

서버의 답안 정규화는 학생 스키마를 기준으로 허용되지 않은 선택값과 정의되지 않은 표 셀 값을 제외한다.

## 5. 업로드 구조

업로드형 블록은 `upload` 설정을 가진다.

- `kind`: `image`, `file`, `drawing`
- `max_files`: 허용 파일 수
- `max_file_size_mb`: 파일 크기 제한
- `accepted_file_types`: 허용 MIME 타입

그림 그리기는 최종 결과 파일과 그리기 원본 데이터를 분리해 저장할 수 있도록 `drawing`, `data_json`, `attachment_id`, `url`, `status` 형태로 정규화한다.

## 6. 완료 기준

- 모든 지원 입력 타입을 편집기에서 만들 수 있다.
- 모든 지원 입력 타입이 Plate 문서와 로컬 스키마에 저장된다.
- 학생 스키마에는 교사용 전용 필드가 노출되지 않는다.
- 표 입력은 행/열 제목과 셀 타입별 기본 답안을 가진다.
- 이미지, 파일, 그림 입력은 업로드 제한과 답안 기본값을 가진다.
- REST 답안 정규화가 학생 스키마를 기준으로 동작한다.
