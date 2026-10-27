# 화면·JavaScript·REST API

기준: **OJ Platform 2.5.4 / Addon API 1.8.0**, WordPress 6.6 이상, PHP 8.3 이상. 등록과 Runtime 사용법은 [PHP API](php-api.md), 실행 가능한 전체 코드는 [시작 예제](examples/oj-addon-starter/)를 참고합니다.

## 1. 화면을 플랫폼 셸에 연결하기

애드온 화면은 PHP `AddonPageHandlerV1::handle()`에서 HTML 조각을 반환합니다. 플랫폼이 로그인·선언 권한을 검사한 뒤 메뉴, 머리말, CSS와 공통 JavaScript가 있는 문서 안에 넣습니다. 애드온은 `<html>`, `<head>`, `<body>`를 만들지 않습니다.

| 등록 | 경로 예 | 용도와 접근 조건 |
| --- | --- | --- |
| `consolePage()` | `/oj-console/addons/oj-addon-starter` | 콘솔 화면. 로그인, `oj_access_console`, 해당 화면의 선언 capability 필요 |
| `publicPage()` | `/oj-addons/oj-addon-starter` | 사용자 화면. 로그인, 해당 화면의 선언 capability 필요 |
| `consoleMenu()` / `publicMenu()` | 등록한 route key 참조 | 사용자가 접근 가능한 화면으로 연결 |
| `consoleLocalNav()` | 같은 부모 메뉴의 여러 route key 참조 | 콘솔의 하위 화면 이동 |

`publicPage()`의 public은 사용자 영역을 뜻합니다. 이 API로 등록한 화면도 로그인해야 합니다. 미로그인 요청은 로그인 화면으로 이동하고, 권한이 없는 요청은 접근 제한 응답을 받습니다. 버튼을 숨기는 것과 별개로 각 REST 요청에서도 권한을 검사합니다.

페이지 응답은 다음과 같습니다. 모든 클래스의 namespace는 `CodingPanda\OjPlatform\AddonApi\V1`입니다.

```php
return AddonPageResponseV1::page(
    '개인 메모',
    $html,
    'standard', // standard | wide | immersive
    true,       // 애드온 CSS가 다크 모드 토큰에 맞을 때
    200
);
```

`layout`의 기본값은 `standard`, 다크 모드 지원 기본값은 `false`입니다. `wide`는 콘텐츠 폭을 넓히고 `immersive`는 사이드바를 접습니다. 화면 처리 실패를 표현할 때는 `AddonPageResponseV1::unavailable($message, $code)`를 반환하면 503 화면이 표시됩니다. 반환 HTML은 애드온이 직접 escaping합니다.

원문: [페이지 요청](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonPageRequestV1.php), [페이지 응답](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonPageResponseV1.php), [로컬 내비 정의](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonConsoleLocalNavItemV1.php).

## 2. CSS·JavaScript 파일 선언

등록 callback에서 bundle을 한 번 선언하고, 사용할 화면의 `assetBundles`에 그 key를 넣습니다.

```php
$addon->assetBundle(new AddonAssetBundleDefinitionV1(
    'addon.addon_starter.assets', // manifest namespace: addon_starter
    'oj-addon-starter',           // 다른 플러그인과 겹치지 않는 handle
    ['resources/css/note.css'],
    ['resources/js/note.js'],
    [],                        // WordPress에 등록된 자산 handle 의존성
    '1.0.0'
));

$addon->consolePage(new AddonConsolePageDefinitionV1(
    'addon.addon_starter.note',
    '/oj-console/addons/oj-addon-starter',
    new \OjAddonStarter\Page\NotePage(),
    'addon_starter_use',
    ['addon.addon_starter.assets']
));
```

| 필드 | 형식 |
| --- | --- |
| `key` | 자기 namespace의 contribution key |
| `handle` | 영문 소문자·숫자·구분자를 사용하는 고유 handle |
| `styles` | manifest의 `pluginFile` 기준 `.css` 상대 경로 배열, 최대 8개 |
| `scripts` | 같은 기준 `.js` 상대 경로 배열, 최대 8개 |
| `dependencies` | WordPress 자산 handle 배열. CSS와 JS 양쪽에 적용되므로 필요한 경우에만 지정 |
| `version` | SemVer 문자열. 플러그인 버전과 맞추면 배포 후 캐시 갱신에 유용 |
| 화면의 `assetBundles` | 자신이 등록한 bundle key 배열, 화면당 최대 4개 |

파일을 플러그인에 실제로 넣고 상대 경로로 선언합니다. CSS는 화면 본문 앞, bundle JavaScript는 화면 본문 뒤에 출력됩니다. 같은 bundle 안의 파일은 선언 순서대로 연결됩니다.

원문: [자산 정의](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonAssetBundleDefinitionV1.php).

## 3. JavaScript 시작 시점

화면의 DOM과 공통 UI 스크립트 준비를 기다린 뒤 초기화합니다. 플랫폼은 공통 `oj-ui.js`를 문서 끝에서 `defer`로 불러오므로 애드온 파일의 최상위에서 곧바로 `OJP`를 호출하지 않습니다.

```js
(function () {
  'use strict';

  function initialize() {
    var root = document.querySelector('.oj-addon-starter');
    if (!root || root.dataset.initialized === 'true') { return; }
    root.dataset.initialized = 'true';

    var form = root.querySelector('[data-starter-form]');
    // form의 submit 이벤트와 REST 호출은 note.js 구현을 참고합니다.
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
}());
```

PHP의 `$addon->onReady(...)`는 서버 Runtime 준비 callback입니다. 브라우저 코드는 위와 같이 DOM 초기화와 별도로 작성합니다.

### `window.OJP` 공통 UI

| 함수 | 인자 | 반환 |
| --- | --- | --- |
| `OJP.confirm(options)` | 아래 options 객체 | `Promise<boolean>` |
| `OJP.toast(message, tone?)` | 메시지 문자열, 기본 성공 톤. `danger`, `info` 톤 사용 가능 | 반환값 없음 |
| `OJP.openCommandPalette()` | 없음 | 반환값 없음 |

```js
var accepted = await window.OJP.confirm({
  title: '기록을 삭제할까요?',
  message: '삭제한 기록은 복구할 수 없습니다.',
  confirmLabel: '삭제',
  cancelLabel: '취소',
  destructive: true
});
if (accepted) {
  // 여기서 자신의 REST 삭제 요청을 보냅니다.
}
```

모든 options는 선택 사항입니다. 기본 제목은 `계속할까요?`, 확인·취소 버튼은 `확인`·`취소`입니다. 취소 버튼, Escape, 바깥 영역 클릭은 `false`를 반환합니다. 확인 창은 서버 권한 검사를 대신하지 않습니다.

일반 폼 제출·링크에는 선언형 확인도 쓸 수 있습니다. 확인 후 기존 클릭 동작이 한 번 실행됩니다.

```html
<button type="submit" class="ojp-button ojp-button--danger"
        data-ojp-confirm="기록을 삭제할까요?"
        data-ojp-confirm-label="삭제">삭제</button>
```

공통 API 원문: [oj-ui.js](../oj-integration-rebuild/oj-platform/resources/ui/js/oj-ui.js). 문서 로드 순서 근거: [HtmlDocumentRenderer](../oj-integration-rebuild/oj-platform/src/Ui/View/HtmlDocumentRenderer.php).

## 4. UI 클래스와 CSS 토큰

플랫폼의 공통 클래스를 쓰면 폼·버튼·오류 표시가 다른 OJ 화면과 같은 방식으로 동작합니다.

| 요소 | 클래스 |
| --- | --- |
| 머리말 | `ojp-page-header`, `ojp-eyebrow`, `ojp-heading-actions` |
| 버튼 | `ojp-button`, `ojp-button--primary`, `--tinted`, `--plain`, `--danger`, `--small`, `--large` |
| 입력 | `ojp-field`, `ojp-input`, `ojp-select`, `ojp-textarea` |
| 표 | `ojp-table-wrap` 안의 `ojp-table` |
| 상태 안내 | `ojp-notice ojp-notice--info`, `--success`, `--warning`, `--danger` |
| 배지 | `ojp-status-badge ojp-status-badge--neutral`, `--success`, `--warning`, `--danger`, `--info` |
| 빈 목록 | `ojp-empty-state` |

`--primary`처럼 줄여 쓴 항목은 같은 접두사를 붙인 전체 클래스입니다. 예를 들어 경고 안내는 `ojp-notice ojp-notice--warning`입니다.

```html
<section class="oj-addon-starter">
  <header class="ojp-page-header">
    <div><h1>개인 메모</h1><p>로그인한 본인의 메모를 저장합니다.</p></div>
  </header>
  <form class="ojp-form-stack" data-starter-form>
    <fieldset class="ojp-fieldset" data-starter-fields>
      <div class="ojp-field"><label for="starter-note">메모</label>
        <textarea class="ojp-textarea" id="starter-note" name="note" rows="8"></textarea>
      </div>
      <button type="submit" class="ojp-button ojp-button--primary">저장</button>
    </fieldset>
    <p class="oj-addon-starter__status" role="status" aria-live="polite" data-starter-status></p>
  </form>
</section>
```

추가 CSS는 자기 루트 클래스 아래에 작성합니다.

```css
.oj-addon-starter {
  color: var(--ojp-color-ink);
  font-family: var(--ojp-font-sans);
}
.oj-addon-starter .ojp-fieldset {
  padding: var(--ojp-space-5);
  background: var(--ojp-color-surface);
  border: 1px solid var(--ojp-color-line);
  border-radius: var(--ojp-radius-md);
}
```

| 용도 | 자주 쓰는 토큰 |
| --- | --- |
| 글자 | `--ojp-color-ink`, `--ojp-color-ink-2`, `--ojp-color-muted` |
| 배경 | `--ojp-color-surface`, `--ojp-color-elevated`, `--ojp-color-subtle` |
| 강조·테두리 | `--ojp-color-primary`, `--ojp-color-accent-bg`, `--ojp-color-line` |
| 상태 | `--ojp-color-success`, `--ojp-color-warning`, `--ojp-color-danger`, `--ojp-color-info` |
| 간격 | `--ojp-space-1`~`--ojp-space-8`: 4, 8, 12, 16, 24, 32, 40, 56px |
| 모서리 | `--ojp-radius-xs`, `--ojp-radius-sm`, `--ojp-radius-md`, `--ojp-radius-lg`, `--ojp-radius-xl`, `--ojp-radius-pill` |
| 글꼴 | `--ojp-font-sans`, `--ojp-font-mono` |

색 토큰은 다크 모드에서 함께 바뀝니다. `:root`, `html`, `body`, 단독 `button` 같은 전역 선택자 대신 `.oj-addon-starter button`처럼 범위를 지정하고, 색은 공통 토큰을 사용합니다. 일반 상태 안내는 화면 안의 `role="status"` 영역에, 작업 완료는 `OJP.toast()`로 표시할 수 있습니다. 시작 예제는 폼 아래 상태 영역에 저장 결과를 표시합니다.

원문: [공통 CSS](../oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css).

## 5. 브라우저에서 자신의 REST API 호출

Addon API의 Runtime gateway는 **서버 PHP API**입니다. 브라우저가 학급·문제·AI 기능을 사용할 때는 자기 애드온의 WordPress REST endpoint를 거쳐 gateway를 호출합니다. REST 경로·입력·응답 형식은 애드온이 정의합니다.

### 서버에서 URL과 nonce 전달

`rest_url()`로 **완성된 endpoint URL**을 만들면 일반 고유주소와 `?rest_route=...` 형식 모두 처리할 수 있습니다. URL 문자열 뒤에 경로·`?`를 직접 붙이지 않습니다.

```php
$config = wp_json_encode(
    ['endpoint' => rest_url('oj-addon-starter/v1/note'), 'nonce' => wp_create_nonce('wp_rest')],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($config)) {
    return AddonPageResponseV1::unavailable('화면 설정을 준비하지 못했습니다.', 'addon_runtime_unavailable');
}
$html .= '<script type="application/json" id="oj-addon-starter-config">' . $config . '</script>';
```

브라우저에 전달하는 config에는 화면 동작에 필요한 공개 값만 넣습니다. 사용자 입력은 `esc_html()`·`esc_attr()` 등 출력 위치에 맞게 escaping하고, JS에서 표시할 문자열은 `textContent`로 넣습니다.

### 클라이언트 요청

```js
var config = JSON.parse(document.getElementById('oj-addon-starter-config').textContent);
var response = await fetch(config.endpoint, {
  method: 'POST',
  credentials: 'same-origin',
  cache: 'no-store',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-WP-Nonce': config.nonce
  },
  body: JSON.stringify({ note: '안녕하세요' })
});
var payload = await response.json();
if (!response.ok) {
  throw new Error(payload.message || '요청을 처리하지 못했습니다.');
}
```

조회 요청에도 로그인 쿠키와 `X-WP-Nonce`를 함께 보냅니다. nonce가 없는 쿠키 요청은 익명 사용자로 처리됩니다. nonce는 실제 작업 권한을 부여하지 않으므로 서버의 `permission_callback`에서 현재 사용자 권한을 따로 검사합니다. nonce가 만료되면 페이지를 새로고침해 다시 발급받습니다. [WordPress REST 인증 공식 문서](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)

검색 조건을 더할 때는 `new URL(config.endpoint, location.href)`와 `url.searchParams.set()`을 사용합니다. 시작 예제의 POST 본문은 `note` 문자열 하나만 받고, 응답도 `note` 문자열을 돌려줍니다.

### 서버 권한 검사

자체 REST route에는 `permission_callback`을 등록합니다. 아래는 시작 예제와 같은 `addon_starter_use` 선언 capability를 확인하는 기본 형태입니다. 실제 저장·조회 서비스에서는 대상 자료의 소유권·학급 참여 권한도 검사합니다.

```php
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

function my_addon_permission(WP_REST_Request $request): bool|WP_Error
{
    if (!class_exists(AddonCapabilityKeyV1::class)) {
        return new WP_Error('my_addon_unavailable', '플랫폼을 확인해 주세요.', ['status' => 503]);
    }
    if (!is_user_logged_in()) {
        return new WP_Error('my_addon_login_required', '로그인이 필요합니다.', ['status' => 401]);
    }
    if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
        return new WP_Error('my_addon_nonce_expired', '페이지를 새로고침해 주세요.', ['status' => 403]);
    }
    if (!current_user_can(AddonCapabilityKeyV1::wordpress('addon_starter_use'))) {
        return new WP_Error('my_addon_forbidden', '이 작업을 할 권한이 없습니다.', ['status' => 403]);
    }
    return true;
}
```

`AddonCapabilityKeyV1::wordpress()`가 선언 key를 WordPress capability로 변환합니다. 이 변환 함수를 사용하고, 현재 사용자는 서버의 `get_current_user_id()`에서 가져옵니다. 요청 body의 `user_id`를 작업자 신원으로 사용하지 않습니다. Runtime gateway가 필요한 endpoint는 자신의 `onReady`에서 받은 Runtime 연결 상태도 확인합니다.

### 응답 형식

성공 응답의 JSON shape는 자신이 정의합니다. 다음은 예시이며 모든 플랫폼 API가 이 형식을 사용하는 것은 아닙니다.

```json
{"note":"안녕하세요"}
```

WordPress REST의 `WP_Error`는 다음 형태로 직렬화됩니다.

```json
{
  "code": "my_addon_forbidden",
  "message": "이 작업을 할 권한이 없습니다.",
  "data": {"status": 403}
}
```

nonce 오류는 WordPress 자체 검사에서 `rest_cookie_invalid_nonce`로 반환될 수도 있습니다. 실제 HTTP 상태를 먼저 확인하고, 401은 로그인, 403은 권한·nonce, 404는 대상 자료 존재·접근 가능 여부, 409는 수정 충돌 등 자신의 API 계약에 맞게 처리합니다. 성공·오류 응답에 교사용 정답·개인정보·내부 예외 내용을 불필요하게 노출하지 않습니다.

관련 공개 계약: [capability 변환](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonCapabilityKeyV1.php). 전체 실행 흐름은 [시작 예제](examples/oj-addon-starter/)에서 확인합니다.

## 6. 여러 OJ 화면에서 동작하는 자산

알림·수업 참가처럼 자신의 화면 밖에서도 필요한 기능에는 `AddonIntegrationManifestBuilderV1::siteAsset()`을 사용할 수 있습니다. 다음은 시작 예제에 전역 기능을 추가할 때의 등록 예시입니다. 전역 config 요소 ID는 메모 화면의 config ID와 다르게 지정합니다.

```php
if ($addon instanceof AddonIntegrationManifestBuilderV1) {
    $addon->siteAsset(new AddonSiteAssetDefinitionV1(
        'addon.addon_starter.global',
        'addon.addon_starter.assets',
        'oj-addon-starter-global-config',
        $configProvider // AddonSiteAssetConfigProviderV1 구현
    ));
}
```

provider는 `config(int $actorUserId): array`를 구현합니다. 해당 사용자에게 자산이 필요하면 문자열 key를 가진 JSON 객체용 배열을, 필요 없으면 빈 배열 `[]`을 반환합니다. config는 최대 64KiB입니다. 이 자산은 로그인한 사용자의 플랫폼 문서에 포함되며, 반환한 config가 `type="application/json"` script 요소로 출력됩니다.

```js
var element = document.getElementById('oj-addon-starter-global-config');
if (element) {
  var config = JSON.parse(element.textContent);
  // DOMContentLoaded 후 자신의 기능을 초기화합니다.
}
```

페이지 bundle과 site asset 양쪽에서 같은 파일을 사용할 때는 초기화를 중복 실행하지 않도록 표시를 둡니다. config provider가 사용자별 필요 여부를 판단하고, 실제 데이터 endpoint는 매번 현재 권한을 다시 검사합니다.

원문: [site asset 정의](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonSiteAssetDefinitionV1.php), [config provider](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonSiteAssetConfigProviderV1.php), [통합 builder](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonIntegrationManifestBuilderV1.php).
