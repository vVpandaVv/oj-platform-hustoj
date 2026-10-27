# PHP 애드온 API

기준: **OJ Platform 2.5.4 · Addon API 1.8.0 · PHP 8.3 이상 · WordPress 6.6 이상**.
공개 타입의 namespace는 `CodingPanda\OjPlatform\AddonApi\V1`이다. `V1`은 API 주 버전이며, 실제 부 버전은 `OJ_PLATFORM_ADDON_API_VERSION`으로 확인한다.

[개발 시작](README.md) · [전체 생성자·메서드·기본값 목록](api-reference.json) · [공개 PHP 타입 원본](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/)

## 1. 등록과 실행 순서

1. WordPress가 애드온의 메인 PHP 파일을 읽을 때 `oj_platform_register_addons` action에 콜백을 연결한다.
2. 플랫폼은 `plugins_loaded` 우선순위 **1**에서 부팅하며 등록 action에 `AddonRegistrationGatewayV1` 하나를 전달한다.
3. 콜백 안에서 `$gateway->register($addonId, $build)`를 호출한다. `$build`가 받은 builder에 manifest와 페이지·권한 등을 선언한다.
4. 플랫폼이 버전, 이름, 경로, 자산 파일과 기여 간 참조를 검증한다.
5. 승인된 애드온의 `onReady()` 콜백에 해당 애드온 전용 `AddonRuntimeContextV1`를 전달한다. 이 객체를 애드온의 서비스에 보관하고 실제 사용자 요청 시 사용한다.

등록 action 연결을 기본 우선순위의 `plugins_loaded` 콜백 안으로 미루면 등록 시점을 놓친다. 플러그인 파일을 읽는 즉시 연결한다. `onReady()`는 WordPress action이 아니라 플랫폼이 등록된 콜백을 직접 호출하는 방식이며, 등록마다 최대 한 번 실행한다. 등록 콜백에서는 기여를 선언하고, DB 조회·AI 실행 등은 실제 요청에서 수행한다.

```php
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;

add_action('oj_platform_register_addons',
    static function (AddonRegistrationGatewayV1 $gateway): void {
        $gateway->register('oj-example',
            static function (AddonManifestBuilderV1 $builder): void {
                $builder->manifest(
                    namespace: 'oj_example',
                    version: '1.0.0',
                    pluginFile: __FILE__,
                    requiresPlatform: '>=2.5.1 <3.0.0',
                    requiresAddonApi: '^1.8',
                    diagnosticLabel: '예제 애드온',
                    supportReference: 'oj-example/api-v1'
                );
                // capability(), consolePage(), assetBundle() 등을 여기서 선언한다.
                $builder->onReady(
                    static function (AddonRuntimeContextV1 $runtime): void {
                        // 애드온의 서비스에 $runtime을 연결한다.
                    }
                );
            }
        );
    }, 10, 1
);
```

예제의 `__FILE__`은 `wp-content/plugins/oj-example/` 바로 아래의 메인 PHP 파일이어야 한다.
플랫폼 타입을 사용하는 애드온 클래스는 등록 action 안에서 자동 로드되도록 하거나, 플랫폼의 공개 인터페이스가 준비된 뒤 로드한다.

원본: [등록 gateway](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonRegistrationGatewayV1.php), [manifest builder](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonManifestBuilderV1.php).

## 2. 세 가지 식별자

| 종류 | 예 | 규칙 |
| --- | --- | --- |
| 애드온 ID | `oj-example` | 플러그인 폴더명과 일치. 영문 소문자로 시작하는 3~64자의 소문자·숫자·하이픈 |
| namespace | `oj_example` | 영문 소문자로 시작하는 3~64자의 소문자·숫자·밑줄. 사이트 안에서 고유하게 유지 |
| 기여 key | `addon.oj_example.home` | `addon.<namespace>.` 접두사. 페이지·메뉴·자산 등 모든 기여 사이에서 고유 |
| 선언 capability | `oj_example_manage` | `<namespace>_` 접두사, 소문자·숫자·밑줄, 최대 160자 |

플러그인 메인 파일의 `Version:`, `plugin-package.php`의 `version`, manifest의 `version`을 함께 관리한다. manifest의 `requiresPlatform`, `requiresAddonApi`는 각각 플랫폼과 애드온 API의 호환 범위다. 지원 표기는 실제 사용한 기능의 최소 버전에 맞춘다.

`manifest()`의 일곱 인자는 모두 필수이며 반환값은 `void`다. `pluginFile`은 실제 존재하는 메인 PHP 파일의 절대 경로다. `diagnosticLabel`은 HTML 없는 80자 이내 표시 이름, `supportReference`는 160자 이내의 영문·숫자·`._/-` 참조 식별자다(예: `oj-example/api-v1`).

`plugin-package.php`는 저장소의 배포·의존성 메타데이터다. 실제 플랫폼 등록은 위 action과 `manifest()`가 담당한다.

```php
<?php
return [
    'id' => 'oj-example',
    'name' => 'OJ Example',
    'version' => '1.0.0',
    'main_file' => 'oj-example.php',
    'requires' => ['oj-platform:^2.5.1'],
    'soft_requires' => [],
    'provides' => ['example_activities'],
];
```

## 3. 페이지·메뉴·자산 선언 형식

아래는 생성자 인자 순서다. 기본값을 표시한 인자만 선택 사항이다. `key`와 참조 key에는 위의 기여 key 형식을 사용한다.

| builder 메서드 | 전달 DTO와 생성자 |
| --- | --- |
| `capability($definition): void` | `AddonCapabilityDefinitionV1(string $key, string $label, string $audience)` |
| `consolePage($definition): void` | `AddonConsolePageDefinitionV1(string $key, string $path, AddonPageHandlerV1 $handler, string $capability, array $assetBundles = [])` |
| `publicPage($definition): void` | `AddonPublicPageDefinitionV1(string $key, string $path, AddonPageHandlerV1 $handler, string $capability, array $assetBundles = [])` |
| `consoleMenu($definition): void` | `AddonConsoleMenuDefinitionV1(string $key, string $label, string $anchor, string $route, string $capability, int $order, string $icon = '')` |
| `publicMenu($definition): void` | `AddonPublicMenuDefinitionV1(string $key, string $label, string $parent, string $route, string $capability, int $order, string $scope, bool $mobile = true, bool $defaultEnabled = true)` |
| `assetBundle($definition): void` | `AddonAssetBundleDefinitionV1(string $key, string $handle, array $styles, array $scripts, array $dependencies, string $version)` |

- `audience`: `operator`, `educator`, `learner` 중 하나. 역할에 대한 자동 권한 부여 선언은 아니다.
- 콘솔 경로: `/oj-console/addons/<addon-id>` 또는 그 하위 경로.
- 학습자 경로: `/oj-addons/<addon-id>` 또는 그 하위 경로. `publicPage`도 선언한 capability 검사를 거친다.
- 동적 경로: `/oj-console/addons/oj-example/items/{id}`처럼 `{id}`를 쓴다. 매개변수는 중복 없이 최대 8개다.
- 페이지의 `capability`는 이 manifest에서 선언한 capability여야 한다. `assetBundles`는 선언한 bundle key의 목록이며 최대 4개다.
- 메뉴의 `route`는 URL이 아니라 페이지의 기여 key다. 메뉴와 해당 페이지의 capability가 같아야 한다.
- `anchor`는 플랫폼 콘솔 상위 메뉴 key다. 예: `classes`, `problems`, `learning_support`, `community`, `users`, `operations`. 현재 목록은 [콘솔 메뉴](../oj-integration-rebuild/oj-platform/config/menus.php)의 `parent=null`인 항목이다.
- `parent`는 학습자 기본 메뉴의 상위 key다. `scope`는 `learner`다. 학습자 메뉴에 연결하는 페이지 경로에는 `{id}` 같은 필수 매개변수를 넣지 않는다.
- 자산 경로는 메인 PHP 파일 기준 상대 경로다. `styles`는 `.css`, `scripts`는 `.js` 파일 목록이며 각각 최대 8개다. 실제 파일이 존재해야 한다. `dependencies`는 WordPress 자산 handle 목록(최대 32개)이다.
- `handle`은 사이트에서 충돌하지 않게 애드온 접두사를 붙인다. 자산 `version`에는 SemVer를 쓴다.

상세: [페이지 DTO](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonConsolePageDefinitionV1.php), [자산 DTO](../oj-integration-rebuild/oj-platform/src/AddonApi/V1/AddonAssetBundleDefinitionV1.php).

### 페이지 처리기

`AddonPageHandlerV1::handle(AddonPageRequestV1 $request): AddonPageResponseV1`를 구현한다.

| 요청 접근자 | 반환 형식 |
| --- | --- |
| `routeKey()` | 현재 페이지의 기여 key `string` |
| `actorUserId()` | 현재 인증된 사용자 ID `int` |
| `parameters()` | 경로 매개변수 `array<string,string>` |
| `query()` | 쿼리 `array<string,string\|list<string>>` |
| `locale()` | locale `string` |
| `correlationId()` | 진단 추적 ID `string` |

경로·쿼리 값은 문자열이다. 숫자 ID 범위와 해당 자료의 소유권은 처리기에서 다시 검사한다.

```php
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

final class ExamplePage implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $body = '<section class="oj-example"><p>'
            . esc_html('사용자 ' . $request->actorUserId())
            . '</p></section>';
        return AddonPageResponseV1::page('예제', $body, 'standard', true);
    }
}
```

반환용 정적 메서드:

```text
AddonPageResponseV1::html(string $title, string $body, int $status = 200): self;
AddonPageResponseV1::page(string $title, string $body,
    string $layout = 'standard', bool $supportsDarkMode = false, int $status = 200): self;
AddonPageResponseV1::redirect(string $route, array $parameters = []): self;
AddonPageResponseV1::unavailable(string $message, string $code): self;
```

`layout`은 `standard`, `wide`, `immersive`다. `supportsDarkMode=true`는 애드온 CSS가 플랫폼 색상 토큰을 따를 때 사용한다. HTML 응답 상태는 `200`, `403`, `404`, `409`, `422`, `503` 중 하나다. HTML 본문은 2MiB 이내이며, 처리기가 텍스트·URL을 각각 `esc_html()`, `esc_url()` 등으로 이스케이프한다. `redirect()`의 `$route`는 등록한 페이지 key, `$parameters`는 문자열 경로 매개변수 맵이며 결과 상태는 302다. `unavailable()`는 안전한 메시지와 플랫폼 오류 코드(예: `addon_runtime_unavailable`)로 503을 반환한다.

애드온 내부 링크는 `home_url('/oj-console/addons/oj-example')`, API 주소는 `rest_url('oj-example/v1/...')`로 만든다. WordPress가 하위 경로에 설치된 경우에도 사이트 설정을 따른다.

### 콘솔 페이지 안의 탭

`$builder instanceof AddonConsoleLocalNavBuilderV1`를 확인한 뒤 아래 DTO를 `consoleLocalNav()`에 전달한다.

```text
new AddonConsoleLocalNavItemV1(
    string $key, string $label, string $menu, string $route, int $order
);
```

`menu`는 이 애드온의 콘솔 메뉴 key이고 `route`는 이 애드온의 콘솔 페이지 key다. 탭을 추가한 메뉴는 자신의 기본 페이지도 탭 목록에 포함해야 한다.

## 4. 권한과 REST

플랫폼이 선언 capability를 WordPress capability로 변환한다. 애드온의 REST·명령 처리기는 공개 mapper로 같은 값을 검사한다.

```php
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

$allowed = is_user_logged_in()
    && current_user_can(AddonCapabilityKeyV1::wordpress('oj_example_manage'));
```

반환 문자열은 현재 `oj_addon_oj_example_manage`다. 직접 접두사를 붙이는 대신 `AddonCapabilityKeyV1::wordpress(string $declaredKey): string`를 사용한다. 플랫폼의 **역할과 권한**에서 필요한 애드온 권한을 사용자 역할에 부여한다.

애드온 REST는 WordPress `register_rest_route()`로 자신의 namespace에 등록한다. `permission_callback`에서 로그인·capability를 검사하고, cookie 인증 요청에는 `X-WP-Nonce: wp_create_nonce('wp_rest')`를 보낸다. 자료 ID를 받는 요청에서는 대상 소유권이나 아래 Context 권한도 확인한다. `actorUserId`는 `get_current_user_id()` 또는 인증된 페이지 요청에서 얻는다.

REST 성공 응답은 `WP_REST_Response` 또는 `rest_ensure_response()`로, 잘못된 입력·권한 실패는 상태를 지정한 `WP_Error`로 반환할 수 있다. **플랫폼 gateway는 `WP_Error` 대신 공개 결과 DTO를 반환**하므로 애드온의 REST 경계에서 변환한다. 입력 DTO 생성의 `InvalidArgumentException`은 HTTP 400 등 애드온의 입력 오류로 처리한다.

## 5. 런타임 서비스

기본 `AddonRuntimeContextV1`:

```text
$runtime->snapshot(): AddonRuntimeSnapshotV1;
$runtime->contexts(): AddonContextGatewayV1;
$runtime->aiTasks(): AddonAiTaskGatewayV1;
```

`snapshot()`에서 `addonId()`, `addonVersion()`, `requiredPlatform()`, `actualPlatform()`, `requiredAddonApi()`, `actualAddonApi()`, `status()`, `code()`, `catalogRevision()`, `contributionCounts()`, `correlationId()`를 읽는다. `status()`는 `accepted`, `incompatible`, `rejected`, `disabled`, `unregistered` 중 하나다.

선택형 기능은 해당 공개 인터페이스를 `instanceof`로 확인한 뒤 사용하고, 반환 결과의 성공 여부도 검사한다.

| 런타임 인터페이스 | 메서드 → gateway |
| --- | --- |
| `AddonEditorRuntimeContextV1` | `editor()` → `AddonEditorGatewayV1` |
| `AddonIntegrationRuntimeContextV1` | `integrations()` → `AddonIntegrationGatewayV1` |
| `AddonLearningCheckRuntimeContextV1` | `learningChecks()` → `AddonLearningCheckGatewayV1` |
| `AddonCodeOrderingRuntimeContextV1` | `codeOrdering()` → `AddonCodeOrderingGatewayV1` |
| `AddonCodeCompletionRuntimeContextV1` | `codeCompletion()` → `AddonCodeCompletionGatewayV1` |
| `AddonCodeExecutionRuntimeContextV1` | `codeExecution()` → `AddonCodeExecutionGatewayV1` |
| `AddonPracticeRuntimeContextV1` | `practice()` → `AddonPracticeGatewayV1` |
| `AddonSubmissionDraftRuntimeContextV1` | `submissionDrafts()` → `AddonSubmissionDraftGatewayV1` |

### 수업·그룹·참여 권한

```text
$contexts->listTeachingContexts(AddonTeachingContextQueryV1 $query): AddonContextPageV1;
$contexts->authorize(AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1;
$contexts->snapshot(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1;
```

요청 생성자:

```text
new AddonTeachingContextQueryV1(int $actorUserId, array $types,
    string $search = '', string $cursor = '', int $limit = 30);
new AddonContextAccessRequestV1(int $actorUserId, string $contextType,
    string $contextId, string $operation, ?int $learnerUserId = null,
    ?string $expectedRevision = null);
new AddonContextSnapshotRequestV1(int $actorUserId, string $contextType,
    string $contextId, string $expectedRevision);
```

`types`는 중복 없는 `site`, `class`, `group`, `user` 목록이다. `limit`은 1~100이다. ID는 플랫폼이 반환한 문자열(`class:12` 등)을 보존한다. `operation`은 `view`, `assign`, `evaluate`, `report`, `manage`, `participate` 중 하나다.

목록 결과는 `isSuccessful()`, `items()`, `nextCursor()`, `problem()`을 제공한다. 각 `AddonContextSummaryV1`의 `type()`, `id()`, `label()`, `lifecycleState()`, `revision()`, `attributes()`를 읽는다. 다음 페이지에는 `nextCursor()`를 그대로 전달하며 빈 문자열이면 끝이다.

`authorize()`는 `isAllowed()`, `code()`, `snapshot()`, `correlationId()`를 제공한다. 허용 시 snapshot에 `actorRelation()`도 들어 있다. 저장한 활동을 다시 열거나 변경할 때 최신 권한을 다시 확인한다. 리비전이 달라지면 최신 Context를 다시 조회한다.

```php
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;

$decision = $runtime->contexts()->authorize(new AddonContextAccessRequestV1(
    actorUserId: get_current_user_id(),
    contextType: 'class',
    contextId: 'class:12',
    operation: 'participate'
));
if (! $decision->isAllowed()) {
    return new WP_Error('example_forbidden', '수업에 참여할 권한이 없습니다.', ['status' => 403]);
}
```

학습자 자신의 수업·그룹 목록은 `$contexts instanceof AddonLearningContextGatewayV1` 확인 후 `listLearningContexts(new AddonLearningContextQueryV1($actorId, ['class', 'group']))`로 읽는다. 이 query의 나머지 인자 기본값은 teaching query와 같다.

### 학습자 명단

`$contexts instanceof AddonAssignmentRosterGatewayV1` 확인 후:

```text
$contexts->listAssignmentLearners(AddonAssignmentRosterQueryV1 $query): AddonAssignmentLearnerPageV1;
new AddonAssignmentRosterQueryV1(int $actorUserId, string $contextType,
    string $contextId, string $expectedContextRevision,
    ?string $expectedRosterRevision = null, string $cursor = '', int $limit = 100);
```

명단 조회는 `class`, `group`에서 사용한다. 첫 페이지는 `expectedRosterRevision=null`, `cursor=''`이고, 다음 페이지부터 결과의 `rosterRevision()`과 `nextCursor()`를 함께 전달한다. 결과는 `isSuccessful()`, `items()`, `nextCursor()`, `rosterRevision()`, `problem()`을 제공한다. 학습자 DTO는 `learnerUserId()`, `displayName()`, `managementName()`을 제공한다. 애드온 JSON의 식별자 필드는 `id`를 사용하고 관리 이름·명단은 권한 있는 교사 화면에만 전달한다.

### AI 작업

builder에 먼저 작업을 선언하고, 관리자가 플랫폼 **작업별 AI 배정**에서 해당 작업의 모델을 연결한다.

```text
new AddonAiTaskDefinitionV1(string $key, string $label,
    string $inputContractVersion, string $sourceKind,
    ?AddonAiTaskInstructionV1 $instruction = null);
new AddonAiTaskInstructionV1(string $contractVersion, string $content);

$runtime->aiTasks()->canRoute(string $taskKey): AddonAiTaskRouteDecisionV1;
$runtime->aiTasks()->snapshot(string $taskKey): AddonAiTaskRoutingSnapshotV1;
$runtime->aiTasks()->execute(AddonAiTaskRequestV1 $request): AddonAiTaskResultV1;

new AddonAiTaskRequestV1(string $taskKey, string $contractVersion,
    int $actorUserId, string $idempotencyKey, array $input);
```

`sourceKind`는 `none`, `addon_payload`, `platform_submission`이다. `input`은 문자열 key를 가진 JSON 맵(최대 1MiB), `idempotencyKey`는 128바이트 이내 안전한 토큰이다. 같은 생성 작업의 재조회에는 같은 키와 같은 입력을 보내고, 새 생성에는 새 키를 쓴다. `instruction`의 지시문은 UTF-8 16KiB 이내다.

결과의 `isSuccessful()`을 확인한 뒤 `output()`의 JSON 맵을 읽는다. `routingSnapshot()`, `correlationId()`, `problem()`도 제공한다. `addon_ai_request_pending`은 진행 중이며, `addon_ai_outcome_uncertain`은 외부 호출 결과가 확정되지 않은 상태다. 후자의 `retryable=false`를 존중하고 자동으로 새 생성 키를 발급하지 않는다.

## 6. 플랫폼·애드온 간 조회

`$runtime instanceof AddonIntegrationRuntimeContextV1` 확인 후 `integrations()`를 사용한다.

| gateway 메서드 | 입력 생성자 | 결과 |
| --- | --- | --- |
| `searchActivities()` | `AddonActivityQueryV1(int $actorUserId, string $search = '', array $kinds = [], int $limit = 50)` | `AddonActivityPageV1` |
| `searchProblems()` | `AddonProblemQueryV1(int $actorUserId, string $search = '', int $limit = 20)` | `AddonProblemPageV1` |
| `acceptedSubmissions()` | `AddonAcceptedSubmissionQueryV1(int $actorUserId, string $contextType, string $contextId, string $contextRevision, array $learnerUserIds, array $problemIds, ?string $submittedFrom = null, ?string $submittedUntil = null)` | `AddonAcceptedSubmissionPageV1` |
| `privacyManagementPath(): string` | 없음 | 통합 개인정보 관리 경로 |

목록 결과에서 `isSuccessful()`, `items()`, `problem()`을 읽는다. 활동 종류는 `page`, `material`, `game`, `worksheet`, `practice`, `other`이고 `limit`은 1~100이다. 문제 DTO는 `problemId()`, `title()`, `submitted()`, `accepted()`를 제공한다. 정답 제출 query는 현재 수업·그룹 리비전과 요청 명단을 다시 검사하며 명단은 최대 500명, 문제는 최대 100개다. 날짜를 지정하면 `Y-m-d H:i:s` 형식을 사용한다.

### 활동 검색·전역 자산·개인정보 재고 제공

`$builder instanceof AddonIntegrationManifestBuilderV1` 확인 후 등록한다.

```text
$builder->activityProvider(new AddonActivityProviderDefinitionV1(
    string $key, AddonActivityProviderV1 $provider));
$builder->siteAsset(new AddonSiteAssetDefinitionV1(
    string $key, string $assetBundle, string $configElementId,
    AddonSiteAssetConfigProviderV1 $configProvider));
$builder->privacyData(new AddonPrivacyDataDefinitionV1(
    string $key, string $label, array $dataCategories,
    string $retention, string $administrationNote));
```

`AddonActivityProviderV1::search(AddonActivityQueryV1 $query): AddonActivityPageV1`를 구현하고, 현재 사용자가 열 수 있는 활동만 반환한다.

```text
new AddonActivityV1(string $key, string $title, string $description,
    string $url, string $kind, string $sourceLabel, string $contentType = '');
new AddonActivityPageV1(bool $successful, array $items, ?AddonApiProblemV1 $problem);
```

`url`은 HTTP(S) 또는 `/`로 시작하는 사이트 경로다. 활동 DTO는 같은 이름의 접근자(`key()`, `title()` 등)를 제공한다. `contentType`을 쓰면 `text/html` 같은 MIME 형식을 사용한다.

전역 자산 config 제공자는 `config(int $actorUserId): array`를 구현한다. 빈 배열이면 그 사용자에게 자산을 렌더링하지 않는다. `configElementId`는 고유한 DOM ID이며 소문자로 시작하는 소문자·숫자·하이픈 3~80자다. config에 현재 사용자에게 전달할 값만 넣는다. `privacyData()`는 실제 보관 항목과 기간을 플랫폼 관리 화면에 알리는 재고이며, 애드온의 삭제 처리는 애드온이 구현한다.

## 7. 학습·코드 실행 확장

| 기능 | 호출 | 결과·용도 |
| --- | --- | --- |
| 편집기 설정 | `editor()->current(): AddonEditorSnapshotV1` | 현재 계정의 편집기 외형·자산 설정 |
| 공개 문제·본인 제출 | `learningChecks()->context(AddonLearningCheckContextQueryV1)` | 공개 문제와 본인 코드 문맥 |
| 코드 순서 배열 | `codeOrdering()->puzzle(AddonCodeOrderingQueryV1)`, `assemble(AddonCodeOrderingAnswerV1)` | 공개 조각 및 학습자가 정한 순서의 소스 |
| 코드 빈칸 채우기 | `codeCompletion()->template(AddonCodeCompletionQueryV1)`, `assemble(AddonCodeCompletionAnswerV1)` | 공개 시작 코드·빈칸 및 답을 넣은 소스 |
| 교사 예제 실행 | `codeExecution()->availability()`, `submit(AddonCodeExecutionRequestV1)`, `poll(AddonCodeExecutionQueryV1)` | 실제 지원 언어 확인 후 비공개 실행 |
| 학습자 연습 실행 | `practice()->availability(AddonPracticeMaterialV1)`, `submit(AddonPracticeRequestV1)`, `poll(AddonPracticeQueryV1)` | 현재 학습 활동의 테스트를 사용한 개인 연습 |
| 제출 화면 열기 | `submissionDrafts()->prepare(AddonSubmissionDraftRequestV1)` | 저장 코드를 채운 기존 제출 화면 URL |

모든 요청 생성자·반환 타입·기본값은 [전체 API 목록](api-reference.json)에 있다. 코드 배열·빈칸 API의 문맥 ID는 양의 정수(`12`), Context·명단·연습 자료의 문맥 ID는 문자열(`class:12`)이므로 각 DTO 형식을 따른다.

실행 결과는 `AddonCodeExecutionResultV1`의 `isSuccessful()`, `payload()`, `problem()`으로 읽는다. `isSuccessful()`은 API 요청 성공이고 코드 실행 결과는 `payload()['verdict']`다. `status`는 `pending` 또는 `completed`, `verdict`는 `pending`, `executed`, `compile_error`, `runtime_error`, `output_incomplete`, `time_limit`, `memory_limit`, `output_limit`이다. 학습자 연습은 플랫폼 정식 제출·점수를 만들지 않는다. 티켓은 애드온 서버에 보관하고 같은 활동·버전으로 조회한다.

제출 초안 생성자:

```text
new AddonSubmissionDraftRequestV1(int $actorUserId, int $problemId,
    string $contextType, int $contextId, string $language, string $source);
```

`contextType`은 `class` 또는 `group`, `language`는 `python`, `c`, `cpp`, `java`다. 성공 payload는 `url`, `language`, `expires_at`이며 URL은 300초 동안 해당 사용자·문제·문맥에 묶인다. 사용자가 제출 화면에서 직접 제출하면 기존 정식 채점이 시작된다.

심화 예제: [코드 배열·빈칸](../oj-integration-rebuild/oj-platform/docs/addon-api-1.4.md), [실행·연습](../oj-integration-rebuild/oj-platform/docs/addon-api-1.6.md), [제출 초안](../oj-integration-rebuild/oj-platform/docs/addon-api-1.7.md).

## 8. 오류 처리와 저장 알림

대부분의 조회·실행 결과는 `isSuccessful()`과 `problem()`을 제공한다. 권한 결정은 `isAllowed()`, AI 출력은 `output()`, Context snapshot 결과는 `snapshot()`을 사용한다. 결과 DTO의 접근자를 확인해 처리한다.

`AddonApiProblemV1`은 `code(): string`, `message(): string`, `correlationId(): string`, `retryable(): bool`, `details(): array<string,bool|int|float|string|null>`을 제공한다. 오류가 반환되면 자료를 표시하거나 실행을 계속하기 전에 중단하고, `correlationId()`를 진단에 함께 기록한다. 생성자 검증 예외에는 내부 경로·코드·비밀값을 포함한 그대로의 메시지를 학습자에게 반환하지 않는다.

| action | 단일 배열 인자 |
| --- | --- |
| `oj_platform_problem_saved` | `problem_id:int`, `actor_user_id:int`, `change:created\|updated`, `changed_at:string`(UTC) |
| `oj_platform_class_problems_saved` | `class_id:int`, `actor_user_id:int`, `problem_ids:list<int>`, `changed_at:string`(UTC) |

위 action은 저장 완료 알림이다. 콜백에서는 필요한 작업을 자신의 큐에 넣고, 실제 작업 시 현재 권한과 자료를 다시 조회한다.

## 9. 개발 경계

애드온과 플랫폼의 연결에는 이 문서의 `AddonApi\V1` 공개 인터페이스·DTO와 WordPress API를 사용한다. 자신의 자료는 애드온 전용 테이블·옵션에 저장한다. 플랫폼의 `Kernel`, `Modules`, DI 컨테이너, 저장소 구현, 채점 DB 스키마는 내부 구현이므로 직접 결합하지 않는다. API 타입은 활성화된 OJ Platform이 로드한다.

배포 전에는 등록 성공, 교사·학습자 권한, 동적 경로의 자료 소유권, REST nonce, 실패 결과 처리, 자산 존재와 UTF-8(BOM 없음)을 확인한다. 설치 가능한 기본 코드는 [시작 예제](README.md)에서 받는다.
