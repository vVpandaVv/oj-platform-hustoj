# AI 대시보드 관리자 Shell

문서 상태: 확정
작성일: 2026-06-14
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: admin-menu-shell -->

## 1. 목적

관리자 진입점을 워드프레스 관리자 메뉴에 두지 않는다. OJ 플랫폼과 함께 사용할 때는 `/oj-console` 안에 AI 대시보드 메뉴와 라우트를 직접 등록하고, 독립 실행일 때만 플러그인 자체 관리자 경로를 fallback으로 제공한다.

## 2. 진입 경로

OJ 플랫폼 연결 모드의 기본 관리자 경로는 `/oj-console/ai-dashboard`이다.

| 화면 | OJ 콘솔 경로 |
| --- | --- |
| AI 대시보드 | `/oj-console/ai-dashboard` |
| 교사 대시보드 | `/oj-console/ai-dashboard/teacher` |
| 학생 대시보드 | `/oj-console/ai-dashboard/student` |
| 활동지 | `/oj-console/ai-dashboard/worksheets` |
| AI 채점 | `/oj-console/ai-dashboard/assessment` |
| 보고서 | `/oj-console/ai-dashboard/reports` |
| 데이터 가져오기 | `/oj-console/ai-dashboard/import` |
| 학급/명단 연결 | `/oj-console/ai-dashboard/academic` |
| 실행 환경 | `/oj-console/ai-dashboard/environment` |
| OJ 플랫폼 연결 | `/oj-console/ai-dashboard/connection` |
| 설정 | `/oj-console/ai-dashboard/settings` |

독립 실행 fallback의 기본 관리자 경로는 `/oj-ai-dashboard/admin/`이다.

하위 화면은 다음 독립 경로를 사용한다.

| 화면 | 경로 |
| --- | --- |
| 전체 | `/oj-ai-dashboard/admin/` |
| 교사 대시보드 | `/oj-ai-dashboard/admin/teacher/` |
| 학생 대시보드 | `/oj-ai-dashboard/admin/student/` |
| 활동지 | `/oj-ai-dashboard/admin/worksheets/` |
| AI 채점 | `/oj-ai-dashboard/admin/assessment/` |
| 보고서 | `/oj-ai-dashboard/admin/reports/` |
| 데이터 가져오기 | `/oj-ai-dashboard/admin/import/` |
| 학급/명단 연결 | `/oj-ai-dashboard/admin/academic/` |
| 실행 환경 | `/oj-ai-dashboard/admin/environment/` |
| OJ 플랫폼 연결 | `/oj-ai-dashboard/admin/connection/` |
| 설정 | `/oj-ai-dashboard/admin/settings/` |

## 3. 플랫폼 연결

`oj_platform_loaded` 액션에서 OJ 플랫폼 컨테이너를 받으면 `Platform\Oj_Console_Connector`가 다음 레지스트리에 직접 등록한다.

| 레지스트리 | 등록 내용 |
| --- | --- |
| `route_registry` | `console.ai_dashboard.*` 라우트 |
| `console_menu_registry` | `ai_dashboard` 루트 메뉴와 하위 메뉴 |

콘솔 등록이 완료되면 기존 독립 관리자 URL 접근은 대응되는 `/oj-console/ai-dashboard/...` 경로로 리디렉션한다.

호환을 위해 `oj_ai_dashboard_register_oj_admin_entry` 액션은 유지한다. OJ 콘솔 통합이 불가능한 외부 플랫폼이 관리자 메뉴 항목을 붙일 때 다음 항목을 받을 수 있다.

| 키 | 값 |
| --- | --- |
| `label` | `AI 대시보드` |
| `url` | 독립 관리자 URL |
| `capability` | `oj_ai_dashboard_teach` |
| `slug` | `oj-ai-dashboard-teacher` |

OJ 콘솔이 없는 환경에서 외부 플랫폼이 항목을 연결하면 `oj_ai_dashboard_oj_admin_entry_connected` 필터에서 `true`를 반환할 수 있다. 연결되지 않아도 독립 관리자 경로는 계속 사용할 수 있다.

## 4. 워드프레스 관리자 호환

기본값으로 워드프레스 관리자 메뉴는 등록하지 않는다. 기존 테스트나 호환 설치에서 필요하면 `oj_ai_dashboard_register_wp_admin_pages` 필터를 `true`로 바꿔 이전 `wp-admin/admin.php?page=...` 화면을 임시로 열 수 있다.

필터가 꺼진 상태에서 이전 관리자 URL로 접근하면 OJ 콘솔 연결 모드에서는 콘솔 경로로, 독립 실행 모드에서는 독립 관리자 경로로 리디렉션한다.

## 5. 권한

| 화면 | capability |
| --- | --- |
| 전체 | `oj_ai_dashboard_view_own_dashboard` |
| 교사 대시보드 | `oj_ai_dashboard_teach` |
| 학생 대시보드 | `oj_ai_dashboard_view_own_dashboard` |
| 활동지 | `oj_ai_dashboard_manage_worksheets` |
| AI 채점 | `oj_ai_dashboard_run_assessment` |
| 보고서 | `oj_ai_dashboard_export_reports` |
| 데이터 가져오기 | `oj_ai_dashboard_import_data` |
| 학급/명단 연결 | `oj_ai_dashboard_manage_academic_context` |
| 실행 환경 | `oj_ai_dashboard_manage_settings` |
| OJ 플랫폼 연결 | `oj_ai_dashboard_manage_settings` |
| 설정 | `oj_ai_dashboard_manage_settings` |

로그인하지 않은 사용자는 로그인으로 이동한다. 권한이 없는 사용자는 화면 렌더링 전에 차단한다.

## 6. 화면 구성

관리자 본문은 다음 요소만 기본으로 사용한다.

- 페이지 제목
- 탭 메뉴
- 작업 버튼
- 상태 표

본문에는 개발자 설명 문구, 교사용 사용법 안내 문단, 기능 설명 카드 문단을 넣지 않는다.

## 7. 완료 기준

- OJ 연결 모드에서는 AI 대시보드가 `/oj-console` 왼쪽 메뉴와 콘솔 탭에 표시된다.
- OJ 연결 모드에서는 독립 관리자 URL이 대응되는 콘솔 경로로 리디렉션된다.
- 독립 실행 모드에서는 독립 관리자 URL이 모든 탭을 연결한다.
- 워드프레스 관리자 메뉴는 기본 등록되지 않는다.
- 호환 플랫폼은 독립 관리자 URL을 메뉴 항목으로 연결할 수 있다.
- 권한 없는 메뉴는 보이지 않고 직접 접근도 차단된다.
- 관리자 화면의 사용자 표시 문구는 한글이다.
