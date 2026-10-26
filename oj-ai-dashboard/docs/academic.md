# OJ AI Dashboard 학사 컨텍스트 설계

문서 상태: 확정
작성일: 2026-06-14
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: academic-context -->

## 1. 목적

9단계는 학년도, 학년, 반, 학생 명단, 그룹 원본을 하나의 학사 컨텍스트로 묶는 단계다. 이후 대시보드, 활동지 배정, 채점 결과, 보고서가 모두 같은 필터 기준을 사용한다.

## 2. 모드별 원본

독립 모드:

- 로컬 학년도, 학년, 반, 학생 명단을 사용한다.
- 반 단위 로컬 그룹을 만들고 학생 매핑과 연결한다.
- 학생이 WordPress 사용자와 연결되어 있으면 로컬 그룹 멤버도 저장한다.

OJ 연결 모드:

- OJ 그룹과 OJ 사용자를 우선 원본으로 사용한다.
- 로컬 그룹 생성은 비활성화한다.
- OJ 사용자 또는 OJ 그룹을 학년도, 학년, 반, 번호에 연결하는 `academic_context_mappings`를 저장한다.
- OJ 원본 테이블은 수정하지 않는다.

## 3. 명단 가져오기

가져오기는 preview와 commit을 분리한다.

preview:

- CSV 또는 XLSX 첫 번째 시트를 읽는다.
- 학년도, 학년, 반, 번호, 이름을 필수값으로 검사한다.
- OJ 연결 모드에서는 OJ 사용자 ID 또는 로그인 ID가 필요하다.
- 같은 학년도, 학년, 반, 번호가 중복되면 오류로 표시한다.
- 데이터베이스에는 쓰지 않는다.

commit:

- preview를 다시 실행한다.
- 오류가 있으면 기본적으로 반영하지 않는다.
- `allow_partial`이 켜진 경우 정상 행만 반영한다.
- 학년도, 학급, 학생 매핑, 원본 매핑을 저장한다.
- 독립 모드에서만 로컬 그룹과 멤버를 만든다.

권장 컬럼:

| 의미 | 지원 헤더 |
| --- | --- |
| 학년도 | `academic_year`, `year`, `학년도` |
| 학년 | `grade`, `학년` |
| 반 | `class_name`, `class`, `반`, `학급` |
| 번호 | `student_number`, `number`, `no`, `번호`, `출석번호` |
| 이름 | `student_name`, `name`, `display_name`, `이름`, `성명` |
| 로그인 | `login`, `login_id`, `username`, `로그인`, `아이디` |
| 이메일 | `email`, `mail`, `이메일` |
| WordPress 사용자 | `wp_user_id`, `wordpress_user_id` |
| OJ 사용자 | `oj_user_id`, `online_judge_id`, `source_user_id` |
| OJ 그룹 | `oj_group_id`, `source_group_id` |
| 담당 교사 | `teacher_user_id`, `teacher_id` |

## 4. REST

조회:

- `GET /oj-ai/v1/academic/status`
- `GET /oj-ai/v1/academic/years`
- `GET /oj-ai/v1/academic/classes`
- `GET /oj-ai/v1/academic/students`
- `GET /oj-ai/v1/academic/groups`
- `GET /oj-ai/v1/academic/mappings`

가져오기:

- `POST /oj-ai/v1/academic/roster/preview`
- `POST /oj-ai/v1/academic/roster/commit`

쓰기 요청은 로그인, `oj_ai_dashboard_manage_academic_context`, nonce 검사를 모두 통과해야 한다.

## 5. 범위 검사

`Academic_Scope_Provider`는 다음 기준을 제공한다.

- 교사가 담당 교사로 연결된 학급에 접근할 수 있는지 확인한다.
- 학생이 해당 학급 명단에 연결되어 있으면 교사가 학생 데이터에 접근할 수 있다.
- 관리자는 기존 `Scope_Checker`의 상위 권한으로 접근한다.

## 6. 완료 기준

- 모든 결과 조회가 학년도, 학년, 반 필터를 받을 수 있다.
- OJ 연결 모드에서 로컬 그룹 생성 경로가 비활성화된다.
- 독립 모드에서 로컬 그룹 원본은 `origin = local`로만 저장된다.
- 학생 명단 가져오기는 preview 없이 직접 쓰지 않는다.
- OJ 원본과 로컬 원본이 같은 그룹 저장소 안에서 섞이지 않는다.
