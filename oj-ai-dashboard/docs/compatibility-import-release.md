# 호환, 가져오기, 릴리스 구현 기준
문서 상태: 구현 반영  
작성일: 2026-06-14  
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: compatibility-import-release -->
<!-- HARNESS: output-import-completion -->

## 1. Shortcode 호환

기존 공개 진입점은 `Compatibility` 모듈에서 새 대시보드 mount로 연결한다. 기본 alias는 중립 이름으로 제공하고, 현장별 기존 shortcode 이름은 `oj_ai_dashboard_legacy_shortcode_map` 필터로 추가한다.

기본 연결:

- `ai_dashboard`
- `ai_teacher_dashboard`
- `ai_student_dashboard`
- `ai_class_dashboard`
- `ai_feedback_dashboard`

호환 기능은 `oj_ai_dashboard_legacy_shortcode_compat_enabled` 필터로 끌 수 있다.

## 2. 기존 데이터 가져오기

기존 데이터 가져오기는 `POST /oj-ai/v1/import/legacy/preview`와 `POST /oj-ai/v1/import/legacy/commit`으로 처리한다. 두 요청 모두 로그인, nonce, `oj_ai_dashboard_import_data` 권한을 요구한다.
가져오기는 자동 실행하지 않는다. 콘솔 또는 독립 관리자 `데이터 가져오기` 화면의 미리보기 버튼으로 중복과 차단 항목을 확인하고, 사용자가 가져오기 버튼을 누른 경우에만 commit을 수행한다.

지원 target:

- `worksheet_template`
- `roster_student`
- `assessment_result`
- `feedback`
- `special_note`
- `report`

중복 기준은 `source_type`, `source_key`, `source_id` 조합이다. preview는 기존 imported item과 payload 내부 중복을 찾아 `duplicate_count`와 `duplicates`로 반환한다. commit은 `overwrite_existing`이 없으면 중복 항목을 `skipped`로 남기고, `overwrite_existing`이 있으면 기존 target id를 재사용한다.

가져오기 상태는 `oj_ai_import_runs`, `oj_ai_import_items`에 기록한다. 항목 상태는 `imported`, `skipped`, `failed` 중 하나로 저장한다.

같은 화면에서 학생 명단 CSV/XLSX는 `/academic/roster/preview`, `/academic/roster/commit`으로 처리하고, 활동지 템플릿은 `/worksheets/template-import/preview`, `/worksheets/template-import/commit`, `/worksheets/{id}/template-export`로 가져오기와 내보내기를 수행한다.

## 3. 검증

주요 흐름 검증은 다음 명령으로 묶어 실행한다.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\check-quality.ps1
```

Plate 시각 회귀는 `tests/fixtures/plate-editor-visual.html`와 `npm run check:plate` 기준을 유지한다. 브라우저 기반 확인에서는 toolbar, floating toolbar, slash menu, context menu, portal root가 편집 영역 및 관리자 shell에 가려지지 않는지 본다.

## 4. 릴리스 후보 패키지

릴리스 후보 패키지는 다음 명령으로 생성한다.

```powershell
npm run release:package
```

결과물은 `dist/oj-ai-dashboard-<version>.zip`에 생성한다. 패키지는 플러그인 폴더를 포함하며 `.git`, `.agents`, `.codex`, `node_modules`, `vendor`, `dist`는 제외한다.
