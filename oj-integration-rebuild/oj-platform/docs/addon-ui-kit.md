# 애드온 UI 키트 (Addon API 1.3, 플랫폼 2.1.0)

D-010(`docs/d-010-unified-ux-ui.html`) 설계를 애드온에서 쓰는 방법입니다. 애드온 화면은 플랫폼 셸 안에 그려지고, 플랫폼 CSS(`oj-ui-system.css`)와 JS(`oj-ui.js`)는 항상 먼저 로드됩니다. 애드온은 색, 버튼, 표, 알림을 새로 만들지 않고 아래 토큰과 클래스를 씁니다.

## 1. 지켜야 할 규칙

- 색은 `--ojp-*` 토큰만 씁니다. 애드온 CSS에 hex 값을 새로 정의하지 않습니다.
- `:root`나 `html`, `body`, `button` 같은 전역 선택자에 스타일을 걸지 않습니다. 모든 규칙은 애드온 루트 클래스 아래에 둡니다.
- 확인은 `window.confirm`/`alert` 대신 `data-ojp-confirm` 또는 `OJP.confirm()`을 씁니다.
- 아이콘은 이모지 대신 플랫폼 Lucide 스프라이트를 씁니다.
- 메뉴는 애드온당 1개입니다. 하위 화면은 로컬 내비(아래 3절)로 보여 줍니다.

## 2. 토큰

| 용도 | 토큰 |
|---|---|
| 글자 | `--ojp-color-ink`, `--ojp-color-ink-2`, `--ojp-color-muted`, `--ojp-color-tertiary` |
| 면 | `--ojp-color-surface`, `--ojp-color-elevated`, `--ojp-color-subtle`, `--ojp-color-bg-grouped` |
| 채움(회색 반투명) | `--ojp-color-fill`, `--ojp-color-fill-2`, `--ojp-color-fill-strong` |
| 선 | `--ojp-color-line`, `--ojp-color-line-strong`, `--ojp-color-separator` |
| 강조 | `--ojp-color-primary`, `--ojp-color-primary-hover`, `--ojp-color-accent-bg`, `--ojp-color-on-accent` |
| 상태 | `--ojp-color-success`, `-warning`, `-caution`, `-danger`, `-info` (각각 `-bg` 있음) |
| 채점 결과 | `--ojp-verdict-pe`, `-limit`(시간·메모리·출력 초과), `-re`, `-ce` (각각 `-bg` 있음). 맞음·틀림은 `success`·`danger` 색을 씁니다 |
| 모서리 | `--ojp-radius-xs` 6 · `-sm` 8 · `-md` 12 · `-lg` 16 · `-xl` 22 · `-pill` |
| 글자 크기 | `--ojp-text-large-title` 32 · `-title1` 26 · `-title2` 21 · `-title3` 18 · `-body` 15 · `-callout` 14 · `-subhead` 13 · `-footnote` 12 · `-caption` 11 |
| 간격 | `--ojp-space-1`~`8` (4 8 12 16 24 32 40 56) |
| 그림자·재질 | `--ojp-shadow-1`, `--ojp-shadow`, `--ojp-shadow-3`, `--ojp-color-material`, `--ojp-color-scrim` |
| 글꼴 | `--ojp-font-sans`(Pretendard), `--ojp-font-mono` |
| 움직임 | `--ojp-duration`, `--ojp-ease`, `--ojp-ease-spring` |

다크 모드에서는 같은 토큰이 어두운 값으로 바뀝니다. 토큰만 쓰면 다크 모드가 저절로 맞습니다.

## 3. 메뉴와 로컬 내비

```php
$builder->consoleMenu(new AddonConsoleMenuDefinitionV1(
    'addon.oj_code_arena.menu', '코드 아레나', 'learning_support',
    'addon.oj_code_arena.sessions', Capabilities::TEACH, 20
));
// 플랫폼 2.1.0 이상에서만 있는 기능이므로 확인하고 부른다.
if ($builder instanceof AddonConsoleLocalNavBuilderV1) {
    $builder->consoleLocalNav(new AddonConsoleLocalNavItemV1(
        'addon.oj_code_arena.nav-sessions', '수업 게임', 'addon.oj_code_arena.menu', 'addon.oj_code_arena.sessions', 10
    ));
    $builder->consoleLocalNav(new AddonConsoleLocalNavItemV1(
        'addon.oj_code_arena.nav-bank', '문제은행', 'addon.oj_code_arena.menu', 'addon.oj_code_arena.bank', 20
    ));
}
```

`AddonConsoleLocalNavBuilderV1`은 기존 `AddonManifestBuilderV1`과 따로 둔 인터페이스입니다. 테스트용 가짜 빌더나 2.0.x 플랫폼은 이 인터페이스를 구현하지 않으므로, 위처럼 `instanceof`로 확인합니다. `AddonPageResponseV1::page()`도 같은 이유로 `method_exists(AddonPageResponseV1::class, 'page')`로 확인한 뒤 씁니다.

- 플랫폼이 큰 제목 위에 세그먼트 컨트롤을 그리고, 하위 화면에서도 사이드바의 부모 메뉴를 강조합니다.
- 사용자가 권한을 가진 구획만 보입니다. 보이는 구획이 하나뿐이면 내비를 그리지 않습니다.
- 메뉴 자신의 화면이 구획에 포함돼야 합니다. 없는 메뉴·화면을 가리키거나 같은 화면을 두 번 넣으면 매니페스트가 거부됩니다.

## 4. 페이지 응답: 레이아웃과 다크 모드

```php
return AddonPageResponseV1::page('문제은행', $html);                 // 기본 폭, 밝은 화면
return AddonPageResponseV1::page('노트북', $html, 'immersive');      // 사이드바를 접는 몰입형
return AddonPageResponseV1::page('기록', $html, 'wide', true);       // 넓게 + 다크 모드 지원
```

- `layout`: `standard`, `wide`(셸 최대 폭 해제), `immersive`(편집기·노트북·게임·전광판).
- `supportsDarkMode`: 애드온 CSS가 토큰만 쓰는 경우에만 `true`로 둡니다. 기본값과 기존 `html()`은 밝은 화면을 유지합니다.

## 5. 부품

| 부품 | 마크업 |
|---|---|
| 페이지 머리말 | `<header class="ojp-page-header"><div><p class="ojp-eyebrow">코드 아레나</p><h1>문제은행</h1><p>설명</p></div><div class="ojp-heading-actions">버튼</div></header>` |
| 버튼 | `ojp-button` + `--primary`(한 화면에 하나) / `--tinted` / `--plain` / `--danger`, 크기 `--small` `--large` |
| 표 | `<div class="ojp-table-wrap"><table class="ojp-table">…` |
| 알림(계속 남는 상태) | `ojp-notice ojp-notice--info|success|warning|danger` |
| 배지 | `ojp-status-badge ojp-status-badge--success|warning|danger|info|neutral` |
| 채점 결과 배지 | 톤 class 뒤에 `ojp-verdict ojp-verdict--ac|wa|pe|tle|mle|ole|re|ce|pending|manual`을 붙입니다(플랫폼 2.2.1). 결과마다 색이 달라 한눈에 구분됩니다. 상태를 색 하나로 뭉치지 마세요 |
| 화면 안 탭 | `<nav class="ojp-tabs"><a class="ojp-tab is-active">…` |
| 빈 상태 | `ojp-empty-state` (한 문장 + 한 가지 행동) |
| 입력 | `ojp-field` > `label` + `ojp-input` / `ojp-select` / `ojp-textarea` |

## 6. JS (`window.OJP`)

```js
// 되돌릴 수 없는 작업 확인: Promise<boolean>
OJP.confirm({ title: '선택한 팀 3개를 삭제할까요?', message: '팀원 소속도 함께 사라집니다.', confirmLabel: '3개 삭제', destructive: true })
  .then(function (ok) { if (ok) { /* 삭제 */ } });

// 완료 알림
OJP.toast('공개 설정을 저장했습니다');
OJP.toast('저장하지 못했습니다', 'danger');
```

폼 버튼은 스크립트 없이 `data-ojp-confirm="질문"`만 붙여도 같은 시트가 열립니다. `ojp-button--danger` 버튼이면 확인 버튼이 빨간색이 됩니다.

## 7. 문구

안내·오류는 합니다체, 버튼은 짧은 동사(저장, 추가, 편집, 삭제, 내보내기, 가져오기, 다운로드)입니다. 복구할 수 있으면 "보관", 복구할 수 없으면 "삭제"라고 씁니다. 자세한 표는 D-010 11장을 따릅니다.
