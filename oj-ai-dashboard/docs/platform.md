# AI 대시보드 OJ 연결

문서 상태: 확정
작성일: 2026-06-14
대상 단계: 8단계

<!-- HARNESS: oj-connector -->

## 1. 목적

OJ 사용자, 그룹, 문제, 제출 기록, 관리자 진입점을 읽기 중심으로 연결한다.

플러그인은 OJ 원본 문제와 코드 제출 원본을 복제하거나 변경하지 않는다. 분석과 보고서 생성에 필요한 최소 조회 결과만 사용한다.

## 2. 구현 클래스

| 클래스 | 책임 |
| --- | --- |
| `Platform\Oj_Connector` | 사용자, 그룹, 문제, 제출, 메뉴 connector facade |
| `Platform\Oj_User_Connector` | OJ 사용자 조회 |
| `Platform\Oj_Group_Connector` | OJ 그룹과 멤버 조회 |
| `Platform\Oj_Problem_Connector` | OJ 문제 조회 |
| `Platform\Oj_Submission_Connector` | OJ 제출 기록과 테스트 결과 조회 |
| `Platform\Oj_Console_Connector` | OJ 콘솔에 AI 대시보드 라우트와 메뉴 직접 등록 |
| `Platform\Oj_Menu_Connector` | OJ 콘솔 연결 상태와 호환 메뉴 진입 URL 관리 |
| `Platform\Oj_Table_Resolver` | OJ 테이블 이름 탐색과 filter 기반 table map 적용 |
| `Platform\Platform_Rest_Controller` | connector 확인용 REST endpoint |
| `Platform\Platform_Admin_Page` | connector 상태값 화면 |
| `Platform\Platform_Module` | REST, 관리자 화면, OJ 메뉴 연결 hook 등록 |

## 3. 읽기 전용 원칙

- OJ 문제, 제출, 사용자, 그룹 원본 테이블에 insert/update/delete를 실행하지 않는다.
- OJ 테이블명은 `Oj_Table_Resolver`와 filter를 통해 해석한다.
- OJ connector 결과를 플러그인 DB에 원본 복사본으로 저장하지 않는다.
- 분석과 보고서 단계에서는 `source_type`, `source_key`, `source_id` 추적 메타데이터만 저장한다.

## 4. REST 확인 endpoint

관리자는 다음 endpoint로 connector 상태와 조회 결과를 확인할 수 있다.

```text
GET /wp-json/oj-ai/v1/platform/status
GET /wp-json/oj-ai/v1/platform/groups
GET /wp-json/oj-ai/v1/platform/problems
GET /wp-json/oj-ai/v1/platform/submissions
```

접근 권한:

```text
oj_ai_dashboard_manage_settings
```

## 5. OJ 콘솔 연결

OJ 플랫폼이 있으면 기본 관리자 화면은 워드프레스 관리자 메뉴나 별도 독립 메뉴에 붙이지 않고 `/oj-console` 안에 직접 등록한다.

`Oj_Console_Connector`는 `oj_platform_loaded`에서 전달된 플랫폼 컨테이너의 `route_registry`와 `console_menu_registry`를 사용한다.

콘솔 라우트:

```text
console.ai_dashboard
console.ai_dashboard.teacher
console.ai_dashboard.student
console.ai_dashboard.worksheets
console.ai_dashboard.assessment
console.ai_dashboard.reports
console.ai_dashboard.import
console.ai_dashboard.academic
console.ai_dashboard.environment
console.ai_dashboard.connection
console.ai_dashboard.settings
```

콘솔 경로:

```text
/oj-console/ai-dashboard
/oj-console/ai-dashboard/teacher
/oj-console/ai-dashboard/student
/oj-console/ai-dashboard/worksheets
/oj-console/ai-dashboard/assessment
/oj-console/ai-dashboard/reports
/oj-console/ai-dashboard/import
/oj-console/ai-dashboard/academic
/oj-console/ai-dashboard/environment
/oj-console/ai-dashboard/connection
/oj-console/ai-dashboard/settings
```

콘솔 메뉴는 `ai_dashboard` 루트 아래에 하위 화면을 둔다. 화면 본문은 기존 관리자 페이지 객체를 재사용하되, `Admin_Shell`이 콘솔 렌더링 모드에서는 `oj-console-page`, `oj-console-panel`, `oj-console-button`, `oj-console-definition-list` 클래스를 출력해 OJ 플랫폼 UI/UX를 상속한다.

콘솔 연결이 완료되면 `Oj_Menu_Connector::mark_console_connected()`로 연결 상태와 URL map을 기록한다. 기존 독립 관리자 URL 또는 이전 `wp-admin/admin.php?page=...` URL 접근은 대응되는 콘솔 경로로 리디렉션한다.

## 6. 호환 메뉴 연결

OJ 콘솔 레지스트리를 제공하지 않는 호환 플랫폼을 위해 `Oj_Menu_Connector`는 `oj_ai_dashboard_register_oj_admin_entry` 액션으로 다음 항목을 전달할 수 있다.

```php
array(
    'label'      => 'AI 대시보드',
    'url'        => Admin_Shell::url( Admin_Shell::TEACHER_SLUG ),
    'capability' => Capabilities::TEACH,
    'slug'       => Admin_Shell::TEACHER_SLUG,
)
```

호환 플랫폼이 자체 관리자 메뉴에 항목을 연결하면 `oj_ai_dashboard_oj_admin_entry_connected` 필터에서 `true`를 반환한다. 연결되지 않은 경우에도 독립 관리자 경로는 계속 사용할 수 있다.

`oj_ai_dashboard_oj_menu_parent_slug` 필터는 과거 parent slug 기반 연결 상태를 진단하기 위한 호환값으로 남긴다. 새 연결에서는 OJ 콘솔 레지스트리 직접 등록을 우선한다.

## 7. 확장 filter

OJ 구현이 자체 repository나 서비스로 데이터를 제공하려면 아래 filter를 사용한다.

```php
add_filter( 'oj_ai_dashboard_oj_groups', 'my_groups_adapter', 10, 2 );
add_filter( 'oj_ai_dashboard_oj_problems', 'my_problems_adapter', 10, 2 );
add_filter( 'oj_ai_dashboard_oj_submissions', 'my_submissions_adapter', 10, 2 );
add_filter( 'oj_ai_dashboard_oj_table_map', 'my_table_map_adapter' );
add_action( 'oj_ai_dashboard_register_oj_admin_entry', 'my_admin_menu_adapter' );
add_filter( 'oj_ai_dashboard_oj_admin_entry_connected', 'my_admin_menu_connected', 10, 2 );
add_filter( 'oj_ai_dashboard_oj_menu_parent_slug', 'my_legacy_menu_parent_adapter' );
```

filter adapter가 값을 반환하지 않으면 connector는 지원 가능한 기본 테이블 조회 또는 빈 결과 fallback을 사용한다.

## 8. 완료 기준

- OJ가 있으면 OJ 그룹, 문제, 제출 조회가 가능하다.
- OJ가 없으면 독립 실행으로 전환하고 로컬 그룹을 사용한다.
- OJ가 있으면 로컬 그룹은 사용하지 않는다.
- OJ 플랫폼이 있으면 관리자 화면은 `/oj-console/ai-dashboard` 안에서 열린다.
- OJ 플랫폼이 없으면 관리자 화면은 `/oj-ai-dashboard/admin/` 독립 경로로 열린다.
- 연결 상태는 `Platform_Admin_Page`와 `/platform/status`에서 확인할 수 있다.
