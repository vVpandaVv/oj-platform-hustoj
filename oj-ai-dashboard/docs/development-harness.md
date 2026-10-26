# OJ AI Dashboard 개발 하네스

문서 상태: 확정  
작성일: 2026-06-14  
대상 플러그인: `oj-ai-dashboard`

## 1. 목적

이 하네스는 `oj-ai-dashboard` 플러그인을 구현할 때 작업 순서, 안전 규칙, 자동 검증 기준을 고정하기 위한 개발 장치다.

하네스 구성:

- `AGENTS.md`: 작업자가 먼저 읽는 최상위 개발 규칙
- `_notes/agent-harness.md`: 실수 방지 점검표
- `scripts/validate-plugin-harness.ps1`: 자동 검증 스크립트
- `docs/development-procedure.md`: 18단계 개발 절차
- `docs/implementation-precheck.md`: 2단계 진입 전 확인 체크리스트
- `docs/tooling.md`: 로컬 개발 도구와 품질 검사 명령
- `docs/security-permissions.md`: 역할, capability, REST permission 기준
- `docs/environment.md`: OJ 감지, 실행 모드, feature flag 기준
- `docs/database.md`: DB migration, table mapping, repository 기준
- `docs/platform.md`: OJ 사용자, 그룹, 문제, 제출, 메뉴 connector 기준
- `docs/academic.md`: 학년도, 학년, 반, 학생 명단, 학사 컨텍스트 매핑 기준
- `docs/admin-shell.md`: 단일 관리자 메뉴, 탭, 버튼, 상태 중심 화면 기준
- `docs/legacy-plugin-integration.md`: 기존 3개 플러그인 기능 복사와 통합 기준
- `docs/system-design.md`: 설계 기준
- `docs/requirements-analysis.md`: 요구 기준

## 2. 사용 절차

1. 작업 전 `AGENTS.md`를 읽는다.
2. 작업 단계는 `docs/development-procedure.md`에서 확인한다.
3. 구현 중 위험 규칙은 `_notes/agent-harness.md`로 확인한다.
4. 변경 후 검증 스크립트를 실행한다.
5. 검증 결과를 커밋 또는 작업 기록에 남긴다.

검증 명령:

```powershell
.\scripts\validate-plugin-harness.ps1
```

Windows 실행 정책 때문에 직접 실행이 막히면 다음 명령을 사용한다.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\validate-plugin-harness.ps1
```

## 3. 자동 검증 범위

현재 검증 스크립트는 다음을 확인한다.

- 필수 문서 존재 여부
- 필수 하네스 파일 존재 여부
- git 저장소 여부
- WordPress 플러그인 메인 파일이 있을 때 플러그인 헤더 존재 여부
- PHP 실행 파일 탐색
- PHP 파일이 있을 때 `php -l` 문법 검사
- 런타임 화면 파일에 금지 문구가 들어갔는지 확인
- namespace, schema 분리, accepted 결과 기준 등 핵심 설계 문구 존재 여부
- Plate 편집기를 통째로 가져와 확장한다는 통합 원칙 존재 여부
- Plate 메뉴와 floating layer 시각 회귀 점검표 존재 여부
- 기존 3개 플러그인을 독립 유지하지 않고 새 플러그인 흐름으로 통합하는 기준 존재 여부
- DB migration, table mapping, repository 기준 존재 여부
- OJ connector와 메뉴 fallback 기준 존재 여부
- 학사 컨텍스트와 학생 명단 preview/commit 흐름 존재 여부
- 단일 관리자 메뉴와 공통 화면 shell 존재 여부

## 4. PHP 경로

검증 스크립트는 아래 순서로 PHP를 찾는다.

1. `OJAI_PHP` 환경 변수
2. `D:\php\php.exe`
3. PATH의 `php`

## 5. 실패 처리 원칙

하네스가 실패하면 다음 순서로 처리한다.

1. 실패 항목이 실제 결함인지 확인한다.
2. 실제 결함이면 코드 또는 문서를 수정한다.
3. 하네스 규칙이 오래되었으면 문서를 먼저 고치고 이유를 남긴다.
4. 검증을 다시 실행한다.

## 6. 하네스 통과 기준

현재 단계에서는 아래를 통과하면 된다.

- 필수 문서 존재
- 하네스 파일 존재
- git 저장소 정상
- PHP 경로 확인
- 문서 핵심 규칙 확인

코드 구현 이후에는 아래가 추가로 통과해야 한다.

- 모든 PHP 파일 문법 검사
- 메인 플러그인 파일 헤더 검사
- runtime 화면 파일 금지 문구 검사
- REST/권한 관련 테스트
- 자동저장, ZIP, 채점 상태 전이 테스트

## 7. 하네스와 개발 단계의 관계

`docs/development-procedure.md`의 총 18단계는 구현 순서이고, 이 하네스는 각 단계마다 실행하는 안전 점검이다.

```text
개발 단계 선택 -> 구현 -> 하네스 실행 -> 수정 -> 커밋
```

## 8. Plate 활동지 편집기 하네스

11단계 이후 Plate 편집기 변경은 다음 항목을 함께 확인한다.

- `docs/worksheet-plate.md`
- `resources/plate/whole-editor/README.md`
- `resources/js/worksheet-editor.js`
- `resources/css/worksheet-editor.css`
- `tests/fixtures/plate-editor-visual.html`
- `npm run check:plate`
- `npm run e2e:plate`
- `docs/input-blocks.md`
- `npm run check:blocks`
- `docs/submission-flow.md`
- `npm run check:submissions`
- `docs/curriculum-rubric.md`
- `npm run check:curriculum`
- `docs/assessment-feedback.md`
- `npm run check:assessment`
- `docs/dashboard-reports.md`
- `npm run check:dashboard`
- `docs/compatibility-import-release.md`
- `npm run check:compat`
- `npm run release:package`

데스크톱과 모바일 폭 시각 점검에서는 toolbar, floating toolbar, slash menu, context menu, portal root가 편집 영역이나 관리자 shell에 가려지지 않는지 확인한다.
