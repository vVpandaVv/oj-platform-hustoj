# OJ Platform 애드온 개발

**OJ Platform 2.5.3 · Addon API 1.8.0 · WordPress 6.6+ · PHP 8.3+**

이 폴더는 외부 플러그인을 만드는 데 필요한 공개 API 형식과 실행 예제를 모은 개발 자료입니다.

| 자료 | 내용 |
| --- | --- |
| [실행 예제](examples/oj-addon-starter/) | 콘솔 메뉴·개인 메모 화면·REST 조회/저장·권한·CSS/JS |
| [PHP API](php-api.md) | 등록 순서, 매니페스트, 인자·반환값, 학급·그룹·문제·AI 연동 |
| [프런트엔드 API](frontend-api.md) | 화면·자산 등록, UI 함수, CSS 토큰, REST 인증과 응답 |
| [전체 API 형식](api-reference.json) | 공개 타입 95개의 생성자·메서드·필수 인자·기본값·반환형·배열 형식 |

## 예제로 시작하기

1. 개발용 WordPress에 [OJ Platform](../_releases/README.md)을 설치하고 활성화합니다.
2. `examples/oj-addon-starter/` 전체를 `wp-content/plugins/oj-addon-starter/`로 복사합니다.
3. WordPress 관리자에서 **OJ Addon Starter**를 활성화합니다.
4. OJ 콘솔의 **역할·권한**(`/oj-console/roles`)에서 사용할 역할에 **시작 예제 사용** 권한을 부여합니다.
5. 해당 계정으로 `/oj-console/addons/oj-addon-starter`를 엽니다. 메모를 저장하고 새로고침해 확인합니다.

예제의 [새 플러그인으로 변경](examples/oj-addon-starter/README.md#새-플러그인으로-변경) 순서에 따라 이름과 기능을 바꾸면 됩니다.

## 기본 흐름

```text
플러그인 로드
  → oj_platform_register_addons 훅
  → gateway->register(애드온 ID, 등록 콜백)
  → manifest + 권한 + 페이지 + 메뉴 + 자산 선언
  → 플랫폼 검증·승인
  → onReady 콜백으로 이 애드온의 런타임 전달
  → 페이지 처리 / 자체 REST 요청 / 런타임 게이트웨이 호출
```

애드온이 사용하는 PHP 계약의 네임스페이스는 `CodingPanda\OjPlatform\AddonApi\V1`입니다. 플랫폼은 선언한 화면·메뉴·자산을 연결하고, 애드온은 자신의 기능·저장소·REST 경로를 구현합니다.

| 바꿀 식별자 | 예제 값 |
| --- | --- |
| 폴더·등록 ID | `oj-addon-starter` |
| PHP 네임스페이스 | `OjAddonStarter` |
| 매니페스트 네임스페이스 | `addon_starter` |
| 기여 키 접두사 | `addon.addon_starter.` |
| 애드온 권한 키 | `addon_starter_use` |
| REST 네임스페이스 | `oj-addon-starter/v1` |
| 사용자 메타 키 | `_oj_addon_starter_note` |

권한 키는 `AddonCapabilityKeyV1::wordpress()`로 변환한 뒤 `current_user_can()`에 전달합니다. REST에는 로그인 쿠키와 `wp_rest` nonce를 사용하고, 서버에서 요청자의 권한과 대상 자료 접근 권한을 확인합니다. 예제는 현재 로그인한 본인의 메모만 저장합니다.

학급·그룹 조회는 `runtime->contexts()`, 문제·제출·명단 조회는 `runtime->integrations()`, AI 요청은 `runtime->aiTasks()`에서 시작합니다. 각 요청·결과 형식과 선택형 런타임 인터페이스는 [PHP API](php-api.md)에 있습니다.

## API 형식 읽기

`api-reference.json`은 현재 플랫폼의 공개 PHP 선언에서 추출한 자료입니다. `symbols`에서 클래스·인터페이스 이름을 찾으면 다음 항목을 확인할 수 있습니다.

| 필드 | 의미 |
| --- | --- |
| `name`, `kind` | 전체 PHP 이름과 인터페이스/클래스 구분 |
| `methods[].name` | 생성자 `__construct` 또는 공개 메서드 |
| `parameters[]` | 인자 이름·타입·필수 여부·기본값 |
| `returnType` | PHP 반환형. `self`는 해당 선언 클래스 |
| `doc` | `list<...>`, `array{...}` 등 PHPDoc에 선언된 세부 형식 |
| `source`, `sourceSha256` | 플랫폼 폴더 기준 원본 경로와 LF 개행 기준 해시 |

`?Type`은 `null`을 허용하며, `required: false`인 인자는 생략할 수 있습니다. 결과 객체에 따라 `isSuccessful()`·`problem()` 또는 `isAllowed()`·`code()`를 확인한 뒤 사용합니다. PHP DTO는 객체 계약이고, 애드온의 HTTP 응답은 별도로 JSON으로 구성합니다.

## 검증

저장소 루트에서 실행합니다.

```bash
php developer-kit/tools/export-api.php --check
php developer-kit/tests/starter.php
```

첫 명령은 공개 API 형식이 플랫폼 소스와 일치하는지 확인합니다. 두 번째는 실제 플랫폼 매니페스트 검증기와 WordPress 함수 대역을 사용해 예제의 등록·페이지·REST·권한·사용자별 저장을 확인합니다. 개발 서버에서는 위 실행 순서로 화면과 저장까지 확인합니다.

플랫폼 API를 변경한 경우 `php developer-kit/tools/export-api.php`로 형식을 갱신하고 변경된 계약의 설명과 예제를 함께 갱신합니다.

예제 코드는 [GPL-3.0-or-later](examples/oj-addon-starter/LICENSE.txt)로 제공됩니다.
