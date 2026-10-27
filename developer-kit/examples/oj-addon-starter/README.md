# OJ Addon Starter

OJ Platform 콘솔에 **개인 메모 예제** 메뉴를 추가하는 개발용 플러그인입니다. 플랫폼 2.5.1 이상·Addon API 1.8·WordPress 6.6 이상·PHP 8.3 이상을 기준으로 합니다.

## 실행

1. OJ Platform을 설치·활성화합니다.
2. 이 `oj-addon-starter/` 폴더 전체를 WordPress의 `wp-content/plugins/` 아래로 복사합니다. 폴더 이름은 그대로 둡니다.
3. WordPress 관리자 → 플러그인에서 **OJ Addon Starter**를 활성화합니다.
4. OJ 콘솔의 역할·권한 관리(`/oj-console/roles`)에서 사용할 역할에 **시작 예제 사용** 권한을 부여합니다. 이 예제의 `educator` 값은 권한 분류이며 자동 부여 설정이 아닙니다.
5. 해당 계정으로 `/oj-console/addons/oj-addon-starter`를 열어 메모를 저장합니다. 새로고침 후 저장한 값이 표시됩니다.

Ubuntu에서 저장소를 내려받은 폴더에 있다면 다음처럼 복사할 수 있습니다.

```bash
sudo cp -R developer-kit/examples/oj-addon-starter /home/judge/src/web/wp-content/plugins/
```

## 파일

| 파일 | 역할 |
| --- | --- |
| `oj-addon-starter.php` | WordPress 헤더, 상수, 자동 로더와 부팅 |
| `plugin-package.php` | 패키지 ID·버전·의존성 메타데이터 |
| `src/Core/Plugin.php` | 매니페스트, 메뉴·화면·권한·자산 등록, `onReady` |
| `src/Page/NotePage.php` | 공개 페이지 계약으로 HTML 응답, REST URL·nonce 전달 |
| `src/Rest/NoteApi.php` | GET·POST 경로와 서버 권한 검사 |
| `src/Storage/NoteRepository.php` | 본인 메모 조회·검증·저장 |
| `resources/js/note.js` | 로그인 쿠키·nonce를 사용하는 REST 호출 |
| `resources/css/note.css` | 애드온 루트 아래의 플랫폼 토큰 기반 스타일 |
| `uninstall.php` | 플러그인 삭제 시 예제의 메모 정리 |

플랫폼은 매니페스트를 승인한 뒤 애드온에 등록된 `onReady` 콜백을 직접 호출합니다. 예제는 이때만 자체 REST 경로를 등록합니다. 애드온 수집 훅은 메인 파일을 읽을 때 등록하며 `init`까지 미루지 않습니다.

## REST 형식

브라우저에서 로그인한 계정의 쿠키와 `X-WP-Nonce` 헤더(`wp_create_nonce('wp_rest')`)를 함께 보냅니다. 기본 URL은 PHP의 `rest_url('oj-addon-starter/v1/note')`가 생성합니다. 고유주소 설정에 따라 `?rest_route=` 형식도 사용할 수 있으므로 `/wp-json/`을 직접 붙이지 않습니다.

| 메서드 | 경로 | JSON 본문 | 성공 응답 |
| --- | --- | --- | --- |
| GET | `/oj-addon-starter/v1/note` | 없음 | `{"note":"저장한 메모"}` |
| POST | `/oj-addon-starter/v1/note` | `{"note":"새 메모"}` | `{"note":"새 메모"}` |

메모는 UTF-8 문자열, 최대 4,000바이트입니다. 빈 문자열로 비울 수 있고, HTML 태그는 제거됩니다. 본문은 `note` 필드 하나만 받습니다. 서버는 현재 로그인 계정의 `_oj_addon_starter_note` 사용자 메타만 읽고 씁니다. 사용자 ID를 요청으로 받지 않습니다.

권한 검사는 `current_user_can(AddonCapabilityKeyV1::wordpress('addon_starter_use'))`입니다. nonce는 요청 출처 검증에 사용하고, 실제 접근 권한은 이 검사로 판단합니다.

| 상태 | 코드 | 의미 |
| --- | --- | --- |
| 400 | `starter_invalid_body`, `starter_invalid_note` | JSON 형식·메모 검증 실패 |
| 401 | `starter_authentication_required` | 로그인 필요 |
| 403 | `starter_forbidden` | 예제 권한 없음 |
| 500 | `starter_save_failed` | 저장 실패 |
| 503 | `starter_unavailable` | 애드온 런타임 준비 전 |

오류는 WordPress REST 형식인 `{"code":"...","message":"...","data":{"status":403}}`입니다. 잘못되거나 만료된 nonce는 WordPress가 콜백에 도달하기 전에 `rest_cookie_invalid_nonce`(403)로 거부합니다. nonce 없는 쿠키 요청은 익명 요청으로 처리됩니다.

비활성화하면 메모를 유지하고, WordPress에서 플러그인을 삭제하면 이 예제가 저장한 사용자 메모를 삭제합니다.

## 새 플러그인으로 변경

1. 폴더·메인 파일·WordPress 헤더와 `plugin-package.php`의 `oj-addon-starter`를 새 플러그인 ID로 바꿉니다. 등록 ID와 메인 파일의 부모 폴더 이름을 맞춥니다.
2. PHP 네임스페이스 `OjAddonStarter`, 상수 접두사 `OJ_ADDON_STARTER`, 매니페스트 네임스페이스 `addon_starter`를 함께 바꿉니다.
3. 기여 키의 `addon.addon_starter.`, 권한 `addon_starter_use`, REST 네임스페이스, CSS 루트, 설정 요소 ID와 메타 키를 각각 고유하게 바꿉니다.
4. 저장 서비스와 화면을 원하는 기능으로 교체합니다. 본인 자료 외에 학급·그룹 데이터를 사용할 때는 런타임의 컨텍스트 게이트웨이로 현재 접근 권한을 확인합니다.

[PHP API](../../php-api.md) · [프런트엔드 API](../../frontend-api.md)

예제 코드는 GPL-3.0-or-later입니다. 전문은 동봉한 `LICENSE.txt`를 참고하세요.
