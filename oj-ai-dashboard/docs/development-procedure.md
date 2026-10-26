# OJ AI Dashboard 플러그인 개발 절차

문서 상태: 확정  
작성일: 2026-06-13  
총 단계 수: 18단계  
대상 플러그인: `oj-ai-dashboard`

## 1. 개발 기준

이 플러그인은 최신 WordPress 플러그인 개발 관행을 기준으로 개발한다.

- 메인 플러그인 파일에는 WordPress 플러그인 헤더를 둔다.
- PHP 코드는 namespace 기반으로 구성한다.
- 클래스 자동 로딩은 Composer PSR-4 또는 자체 PSR-4 호환 autoloader를 사용한다.
- REST API는 controller class 단위로 구성한다.
- 모든 쓰기 요청은 nonce, capability, 담당 범위 검사를 수행한다.
- 입력은 검증과 sanitize를 거치고, 출력은 escape한다.
- DB 생성과 변경은 activation/migration 계층에서 관리한다.
- JavaScript와 CSS는 WordPress enqueue hook으로 필요한 화면에만 로드한다.
- 긴 작업은 job queue로 처리하고, REST API는 job id를 반환한다.
- 실제 화면에는 개발자 설명 문구나 교사용 긴 안내 문구를 넣지 않는다.
- 기존 3개 플러그인의 기능은 새 플러그인 안에서 하나의 업무 흐름으로 통합하고, 독립 플러그인처럼 병렬 유지하지 않는다.

## 2. 목표 구조

```text
oj-ai-dashboard/
├─ oj-ai-dashboard.php
├─ composer.json
├─ package.json
├─ phpcs.xml.dist
├─ phpunit.xml.dist
├─ .gitattributes
├─ .gitignore
├─ config/
├─ database/
│  ├─ migrations/
│  └─ schema/
├─ docs/
├─ languages/
├─ resources/
│  ├─ js/
│  ├─ css/
│  └─ plate/
├─ src/
│  ├─ Admin/
│  ├─ AI/
│  ├─ Assessment/
│  ├─ Compatibility/
│  ├─ Core/
│  ├─ Curriculum/
│  ├─ Dashboard/
│  ├─ Environment/
│  ├─ Export/
│  ├─ Feedback/
│  ├─ Import/
│  ├─ Platform/
│  ├─ Reports/
│  ├─ Rest/
│  ├─ Security/
│  ├─ SpecialNote/
│  ├─ Submission/
│  └─ Worksheet/
├─ templates/
├─ tests/
│  ├─ unit/
│  ├─ integration/
│  └─ e2e/
└─ vendor/
```

`vendor/`와 빌드 산출물은 배포 정책이 정해질 때까지 git에 포함하지 않는다.

## 3. 개발 절차

### 1단계: 기준 문서 확정

- 요구 분석서와 설계서를 최신 상태로 유지한다.
- 남은 구현 전 확인 항목을 별도 이슈 또는 체크리스트로 분리한다.
- 화면 문구 정책을 UI 구현 기준으로 고정한다.

완료 기준:

- `docs/requirements-analysis.md`
- `docs/system-design.md`
- `docs/development-procedure.md`
- `docs/development-harness.md`
- `docs/implementation-precheck.md`
- `docs/database.md`
- `docs/platform.md`
- `docs/academic.md`
- `docs/admin-shell.md`
- `docs/legacy-plugin-integration.md`
- `AGENTS.md`
- `_notes/agent-harness.md`
- `scripts/validate-plugin-harness.ps1` 통과

### 2단계: 플러그인 기본 뼈대 생성

- `oj-ai-dashboard.php`를 생성한다.
- 플러그인 헤더를 작성한다.
- `ABSPATH` 직접 접근 방지 코드를 둔다.
- 플러그인 경로, URL, 버전 상수를 정의한다.

완료 기준:

- WordPress 관리자 플러그인 목록에 표시 가능
- 활성화 시 치명 오류 없음

### 3단계: autoload와 Core 부트스트랩 구성

- Composer PSR-4 또는 자체 PSR-4 autoloader를 구성한다.
- `Core\Plugin`을 진입점으로 둔다.
- `Module_Manager`로 모듈 등록 순서를 제어한다.
- activation, deactivation hook을 연결한다.

완료 기준:

- Core 클래스 로딩 가능
- 모듈 등록 순서가 한 곳에서 관리됨

### 4단계: 개발 도구와 코드 표준 구성

- PHP lint, PHPCS, PHPStan 또는 Psalm 기준을 둔다.
- JS lint와 build script를 둔다.
- 테스트 실행 명령을 정한다.
- Windows 로컬 PHP 경로를 고려한다.

완료 기준:

- PHP 문법 검사 가능
- JS/CSS build 가능
- 기본 테스트 명령 정의
- `docs/tooling.md`에 로컬 실행 명령 기록

### 5단계: 보안과 권한 기반 구축

- 관리자, 교사, 학생 capability를 정의한다.
- 보조교사는 설정으로 켜는 확장 역할로 둔다.
- REST permission callback을 공통화한다.
- nonce, capability, 담당 학급 범위 검사를 분리한다.

완료 기준:

- 역할별 접근 matrix 구현
- 학생이 타 학생 데이터를 볼 수 없음
- 교사는 담당 범위 밖 데이터를 볼 수 없음
- `docs/security-permissions.md`에 권한 기준 기록

### 6단계: 환경 감지와 실행 모드 구현

- OJ 플랫폼 감지기를 구현한다.
- OJ 연결 모드와 독립 모드를 결정한다.
- feature flag를 제공한다.
- OJ가 없어도 플러그인 활성화가 실패하지 않도록 한다.

완료 기준:

- OJ 있음/없음 상태를 REST와 관리자 화면에서 확인 가능
- 독립 모드에서 OJ 기능 비활성화

### 7단계: DB 마이그레이션과 저장소 계층 구현

- `docs/legacy-plugin-integration.md`의 소스 인벤토리를 기준으로 기존 테이블과 새 테이블의 매핑을 작성한다.
- 테이블 schema를 migration 단위로 작성한다.
- `dbDelta()` 또는 migration service를 통해 테이블을 생성/변경한다.
- repository class를 통해 DB 접근을 캡슐화한다.
- 상태값과 unique key를 DB 수준에서 보호한다.

완료 기준:

- 활성화 시 필요한 테이블 생성
- 버전별 migration 재실행 안전성 확보

### 8단계: OJ connector 구현

- OJ 사용자, 그룹, 문제, 제출, 메뉴 connector를 구현한다.
- OJ 원본 데이터는 읽기 중심으로 사용한다.
- OJ 메뉴 hook이 있으면 교사용 대시보드를 OJ 메뉴에 연결한다.
- hook이 없으면 독립 메뉴로 fallback한다.

완료 기준:

- OJ 그룹 조회 가능
- OJ 문제와 제출 기록 조회 가능
- OJ 메뉴 연결 실패 시 fallback 표시

### 9단계: 학년도/학년/반/학생 관리 구현

- 독립 모드의 학년도, 학년, 반, 그룹, 학생 관리를 구현한다.
- OJ 모드에서는 로컬 그룹 생성 UI를 숨긴다.
- `academic_context_mappings`로 OJ 그룹/사용자를 학년도, 학년, 반, 번호에 연결한다.
- 학생 명단 CSV/XLSX 가져오기 preview/commit 흐름을 구현한다.

완료 기준:

- 모든 결과를 학년도, 학년, 반으로 필터링 가능
- OJ 모드와 독립 모드의 그룹 원본이 섞이지 않음

### 10단계: 관리자 메뉴와 화면 shell 구현

- 단일 관리자 메뉴를 구성한다.
- 화면 shell은 라벨, 버튼, 상태값 중심으로 구성한다.
- 개발자 설명 문구와 교사용 긴 안내 문구는 화면에 넣지 않는다.
- 자세한 설명은 별도 도움말 문서 또는 닫을 수 있는 도움말 패널로 분리한다.

완료 기준:

- 주요 메뉴 진입 가능
- 화면 내 설명 문단 없음
- 권한 없는 메뉴는 숨김 또는 차단

### 11단계: Plate 활동지 편집기 통합

<!-- HARNESS: plate-whole-editor -->

- 기존 Plate 템플릿 빌더/편집기 구조를 통째로 가져와 활동지 편집 화면을 구성한다.
- Plate 개별 패키지나 개별 모듈을 임의 조립해서 유사 편집기를 만들지 않는다.
- Plate provider, editor shell, toolbar, floating toolbar, slash menu, context menu, portal/root layer 구조를 유지한다.
- 커스텀 입력 블록과 schema 추출, 저장 연동만 extension 계층으로 추가한다.
- `teacher_schema`, `student_schema`, `grading_schema` 생성기를 구현한다.
- `question_id`와 `block_id`를 안정적으로 생성한다.
- 템플릿 가져오기/내보내기를 구현한다.

완료 기준:

- 교사용 활동지 생성 가능
- 학생 API에 정답, 예시답안, 배점, 교사용 메모가 노출되지 않음
- 편집기 메뉴, 툴바, 플로팅 메뉴, 선택 영역 메뉴가 본문에 가리거나 잘리지 않음
- 데스크톱과 모바일 폭에서 floating layer 위치와 z-index가 정상 동작

### 12단계: 입력 블록 구현

<!-- HARNESS: worksheet-input-blocks -->

- 체크박스, 단일 선택, 복수 선택, 단답, 서술, 숫자 입력을 구현한다.
- 표 입력은 행/열 제목과 셀 타입을 지원한다.
- 이미지 업로드, 파일 첨부, 그림 그리기를 구현한다.
- 블록별 배점, 정답, 예시답안, 교사용 메모는 교사용/채점용 schema에만 둔다.

완료 기준:

- 모든 입력 타입 작성/저장 가능
- 표 셀 타입별 답안 저장 가능

### 13단계: 자동저장, 제출, ZIP 업로드 구현

<!-- HARNESS: submission-autosave-zip -->

- 자동저장 세션, revision, idempotency key를 구현한다.
- 첨부파일 상태와 본문 저장 상태를 분리한다.
- 마감 자동 제출을 구현한다.
- 교사 대리 제출을 구현한다.
- ZIP 업로드 preview/map/commit 흐름을 구현한다.
- ZIP 안전 검사를 적용한다.

완료 기준:

- 네트워크 실패 후 복구 가능
- 여러 탭 충돌 감지
- ZIP 자동 매칭과 수동 매핑 가능

### 14단계: 루브릭과 성취기준 구현

<!-- HARNESS: rubric-curriculum -->

- 루브릭 모드는 활동지 전체 또는 문항별 중 하나만 선택하게 한다.
- 문항별 루브릭은 `question_id` 기준으로 저장한다.
- 활동지 단위 성취기준과 문항별 성취기준 연결을 구현한다.
- 성취기준 본문은 특기사항 생성 컨텍스트로 사용한다.

완료 기준:

- 루브릭 혼합 사용 불가
- 성취기준 연결 조회 가능

### 15단계: AI 채점 구현

<!-- HARNESS: assessment-plan-rubric-mapping -->
<!-- HARNESS: assessment-scoring -->
<!-- HARNESS: assessment-review-workbench -->
<!-- HARNESS: regrade-change-review -->

- 채점 전에 평가 계획, 대상, 학생 매핑, 루브릭, 성취기준 연결을 완성한다.
- `채점` 버튼은 점수와 채점 검토의견만 생성한다.
- 채점 입력 패키지에는 질문, 학생 답변, 배점, 정답, 예시답안, 루브릭, 첨부 요약만 포함한다.
- grading fingerprint와 rubric snapshot을 저장한다.
- 서버가 총점을 계산한다.
- 재채점 변경은 `change_pending`으로 저장한다.

완료 기준:

- 같은 조건의 재채점은 기존 결과 재사용
- 점수 변경은 교사 검토 전 반영되지 않음

### 16단계: 피드백과 특기사항 구현

<!-- HARNESS: feedback-special-note -->

- `피드백` 버튼은 accepted 채점 결과만 공식 입력으로 사용한다.
- 피드백은 승인 후 학생에게 공개한다.
- 반려, 공개 취소를 지원한다.
- `특기사항` 버튼은 accepted 채점 결과, 승인된 피드백, 성취기준, 교육과정을 사용한다.

완료 기준:

- 승인 전 피드백은 학생에게 보이지 않음
- 미확정 재채점 결과로 공식 특기사항이 생성되지 않음

### 17단계: 대시보드, 보고서, 내보내기 구현

<!-- HARNESS: dashboard-analytics -->
<!-- HARNESS: output-import-completion -->

- 교사용 대시보드와 학생용 대시보드를 구현한다.
- OJ 모드에서는 코드 제출, 문제 풀이, 활동지, AI 채점 결과를 통합 표시한다.
- 독립 모드에서는 활동지, 업로드 제출, AI 채점 결과를 표시한다.
- 학생별 PDF, 학급별 PDF, 제출물 일괄 PDF를 구현한다.
- 점수 CSV를 구현한다.
<!-- HARNESS: dashboard-reports-export -->

완료 기준:

- 학년도, 학년, 반 필터 동작
- PDF 포함 옵션 동작
- CSV 점수 출력 가능

### 18단계: 호환, 검증, 릴리스 준비

<!-- HARNESS: functional-equivalence-final -->

- 기존 shortcode 호환을 구현한다.
- 기존 데이터 가져오기 preview/commit을 구현한다.
- unit, integration, e2e 검증을 수행한다.
- Plate 편집기는 브라우저 기반 시각 검증을 수행한다.
- Plate 메뉴, 플로팅 메뉴, 모달, 포털, 스크롤 컨테이너, z-index 회귀를 확인한다.
- PHP lint, PHPCS, JS build, REST 권한 테스트를 통과한다.
- 배포 패키지 생성 절차를 정리한다.
<!-- HARNESS: compatibility-import-release -->

완료 기준:

- 기존 데이터 가져오기 중복 처리 가능
- 주요 사용자 흐름 검증 완료
- 릴리스 후보 패키지 생성 가능

## 4. 단계 요약

총 단계 수는 18단계다.

```text
1. 기준 문서 확정
2. 플러그인 기본 뼈대 생성
3. autoload와 Core 부트스트랩 구성
4. 개발 도구와 코드 표준 구성
5. 보안과 권한 기반 구축
6. 환경 감지와 실행 모드 구현
7. DB 마이그레이션과 저장소 계층 구현
8. OJ connector 구현
9. 학년도/학년/반/학생 관리 구현
10. 관리자 메뉴와 화면 shell 구현
11. Plate 활동지 편집기 통합
12. 입력 블록 구현
13. 자동저장, 제출, ZIP 업로드 구현
14. 루브릭과 성취기준 구현
15. AI 채점 구현
16. 피드백과 특기사항 구현
17. 대시보드, 보고서, 내보내기 구현
18. 호환, 검증, 릴리스 준비
```

## 5. 참고 기준

- WordPress Plugin Header Requirements
- WordPress Plugin Security: capabilities, nonces, sanitizing, escaping
- WordPress REST API controller class pattern
- WordPress script/style enqueue hooks
- WordPress DB migration with `dbDelta()`
