# 공통 애드온 API 1.4.0

OJ Platform 2.3.0은 애드온간 조회, 사이트 전역 자산, 개인정보 보관 항목을 하나의 공개 계약으로 통합한다. 공개 버전은 `OJ_PLATFORM_ADDON_API_VERSION=1.4.0`이고 `V1` namespace는 주 버전 1을 뜻한다. 1.4는 1.3에 대한 추가형 업데이트다.

## 호환 범위

| 소비자 | API 1.4.0에서의 동작 |
| --- | --- |
| 기존 1.0~1.3 애드온 | 기존 등록, 페이지, 메뉴, Context, AI, 편집기 계약을 그대로 사용 |
| 1.4 통합 소비자 | `AddonIntegrationRuntimeContextV1::integrations()`로 활동·문제·정답 제출을 권한 범위에서 조회 |
| 1.4 통합 제공자 | `AddonIntegrationManifestBuilderV1`로 활동, 전역 자산, 개인정보 재고를 등록 |

애드온은 1.4 인터페이스를 `instanceof`로 확인한 뒤 사용한다. 1.4가 필수인 애드온은 `requiresPlatform` 2.3.0 이상과 `requiresAddonApi` `^1.4` 를 명시해 잘못된 조합을 활성화 시점에 차단한다.

## 하나의 통합 통로

`AddonIntegrationGatewayV1`은 다음 읽기 기능의 유일한 공개 통로다.

- `searchActivities()`: 현재 사용자가 열 수 있는 다른 애드온의 활동을 검색한다. 제공자 오류는 다른 제공자를 막지 않는다.
- `searchProblems()`: 현재 사용자에게 공개된 문제의 최소 정보만 반환한다.
- `acceptedSubmissions()`: 요청한 수업 문맥과 명단 리비전을 다시 검증하고, 요청 명단 범위의 유효한 정답 시각만 반환한다.
- `privacyManagementPath()`: 사이트 관리자가 통합 약관·정책 문서를 작성하는 플랫폼 경로를 반환한다.

소비자는 다른 플러그인의 PHP 클래스, WordPress 옵션, 테이블, REST 라우트를 직접 읽지 않는다. 플랫폼은 요청자 일치, 권한, 명단 소유권, 리비전, 개수 상한을 중앙에서 검증하며 실패 시 우회 데이터를 제공하지 않는다.

## 학습자 식별자와 이름

학습자를 식별하는 공개 필드는 정수 `id`로 통일한다. PHP DTO에서는 의미를 보존하기 위해 `learnerUserId()`라는 접근자를 사용하지만, 애드온이 생성하는 JSON·세션 표현은 `id`를 사용한다. 기존 `userId`는 이전 저장 데이터를 읽는 마이그레이션 경로에서만 허용한다.

`AddonAssignmentLearnerV1`은 다음 이름을 구분한다.

- `displayName()`: 학습자에게도 표시할 수 있는 이름.
- `managementName()`: 교사·관리자가 구분을 위해 사용하는 이름. 비어 있으면 `displayName()`으로 대체한다.

어떤 이름을 화면에 노출할지는 소비 애드온의 권한이 있는 교사 UI가 선택하며, 학습자 응답에는 다른 학습자의 `id`나 이름을 포함하지 않는다.

## 제공자 기여

`AddonIntegrationManifestBuilderV1`은 다음 기여를 받는다.

- `activityProvider()`: 권한을 검증한 활동 검색 제공자.
- `siteAsset()`: 등록된 asset bundle을 인증된 OJ Platform 화면 전체에 로드하는 자산. 플랫폼이 중복 DOM ID와 없는 bundle을 거부한다.
- `privacyData()`: 저장 항목, 보관 기간, 관리 참고를 재고로 등록한다. 애드온은 개인정보 처리방침 문구를 생성하거나 게시하지 않고, 관리자가 플랫폼 통합 문서 화면에서 재고를 검토한다.

## 선택형 학습 확인 확장 (Platform 2.3.20)

`AddonLearningCheckRuntimeContextV1`은 기존 런타임을 확장해 `learningChecks(): AddonLearningCheckGatewayV1`를 제공합니다. 기존 기본 인터페이스와 생성자 호출은 유지합니다. 소비자는 `instanceof`로 이 선택형 기능을 확인하고, 없는 플랫폼에서는 해당 기능을 건너뜁니다.

`context(new AddonLearningCheckContextQueryV1(actorUserId, problemId, submissionId, contextType, contextId))`는 현재 사용자와 일치하는 요청에 공개 문제 내용·명시적 예제·내용 해시와 본인의 제출 소스 한 건을 제공합니다. 제출 번호가 없으면 현재 문제의 최근 본인 제출 중 접근 가능한 자료를 최대 6건 확인합니다. 제출이 없어도 문제 자료는 반환합니다. `contextId`는 양의 정수이며 general에서는 null입니다.

문제 열람 권한·수업/그룹 소속·대회 피드백 제한·기존 제출 코드 공개 범위를 다시 확인합니다. 다른 학생의 코드, 비공개 테스트, 채점용 래퍼, 실행 오류는 반환하지 않습니다. 이 계약은 자료 제공만 담당하며 문항 생성·정답 검증·응답 보관은 소비 애드온의 책임입니다.

Platform 2.4.2부터 성공 payload에 선택 필드 `latest_own_submission_id`를 추가합니다. 제출 번호 없는 조회에서는 같은 문제의 현재 본인 유효 제출 요약 목록 중 최대 ID이며, 목록이 실제로 비어 있으면 `0`입니다. 원문 공개가 제한되어 `submission`이 null이거나 더 오래된 조회 가능 제출이 반환되더라도 기준 ID는 유지합니다. 특정 제출 번호 조회에서는 최신 목록을 읽지 않으므로 null입니다. 기존 두 필드만 사용하는 DTO 생성자 호출도 계속 허용합니다. 이 값은 새 제출을 기다릴 때의 기준점이며 정답이나 제출 원문 접근의 증거가 아닙니다. 제출 원문 검사는 최대 여섯 건과 기존 정책을 그대로 적용합니다. 제출 시각의 타임존을 추정해 신규 여부를 판정하지 않습니다.

## 선택형 코드 순서 배열 확장 (Platform 2.3.23)

`AddonCodeOrderingRuntimeContextV1::codeOrdering()`은 PRIMM 등 학습 애드온이 플랫폼의 코드 순서 배열 문제를 재사용하는 공개 통로다. Addon API 버전은 `1.4.0`을 유지하며 기본 런타임 인터페이스는 변경하지 않는다. 소비자는 이 선택형 인터페이스를 `instanceof`로 확인한다. 확장 인터페이스가 있어도 실행 서비스가 준비되지 않은 경우에는 실패 결과가 반환되므로 `isSuccessful()`도 확인해야 한다.

- `puzzle(new AddonCodeOrderingQueryV1(actorUserId, problemId, contextType, contextId))`: 언어, 문제 ID, `template_hash`, 섞인 `blocks`를 제공한다. 각 조각은 무작위 불투명 `id`와 `code`만 포함한다. 기준 순서·위치·원본 스키마·비공개 래퍼·제출 코드는 제공하지 않는다.
- `assemble(new AddonCodeOrderingAnswerV1(actorUserId, problemId, templateHash, blockIds, contextType, contextId))`: 현재 문제 버전을 확인하고 모든 조각이 정확히 한 번 포함되었는지 검사한 뒤, **학습자가 지정한 순서**의 `source`와 언어를 제공한다. 성공은 복원 가능하다는 뜻이며 정답·AC·실행 성공을 뜻하지 않는다. 입력 코드나 정답 순서는 받지 않는다.

`contextType` 기본값은 `general`, `contextId` 기본값은 `null`이다. `class`, `group`, `contest`는 양의 정수 문맥 ID가 필요하다. 두 호출은 매번 기존 `learningChecks()->context()`의 현재 사용자 일치·문제 열람·학급/그룹·대회 피드백 정책을 확인하고, Problems의 공개 제출 정책 계약으로 같은 문맥의 문제 접근을 다시 확인한다. 열린 퍼즐을 보유했더라도 권한이 취소되면 복원할 수 없다. 코드 순서 배열로 설정되지 않은 문제에는 퍼즐을 만들어 주지 않는다.

성공한 퍼즐 payload의 키는 `problem_id`, `language`, `template_hash`, `blocks`뿐이고, 복원 payload의 키는 `problem_id`, `language`, `template_hash`, `source`뿐이다. 실패 결과는 빈 payload와 안전한 `AddonApiProblemV1`만 제공한다. 바뀐 해시, 누락·중복·다른 문제의 조각, 내부 조회 오류는 소스를 제공하지 않는다. 코드와 ID는 최대 200개 조각, 전체 소스는 약 64KB로 제한한다.

다음은 소비 애드온의 인증된 요청 처리기 안에서 사용하는 예다. `$actorUserId`는 브라우저 값이 아닌 현재 로그인 사용자에서 얻고, 입력 DTO 생성 시 발생하는 `InvalidArgumentException`은 소비자의 잘못된 요청 응답으로 처리한다.

```php
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1;

if ($runtime instanceof AddonCodeOrderingRuntimeContextV1) {
    $gateway = $runtime->codeOrdering();
    $puzzle = $gateway->puzzle(new AddonCodeOrderingQueryV1(
        $actorUserId, $problemId, 'class', $classId
    ));
    if ($puzzle->isSuccessful()) {
        $publicPuzzle = $puzzle->payload();
        // Render blocks as escaped text in this returned order.
        // Keep template_hash with the learner's activity version.
    }

    // A later authenticated answer request supplies only its saved hash and IDs.
    $assembled = $gateway->assemble(new AddonCodeOrderingAnswerV1(
        $actorUserId, $problemId, $submittedHash, $submittedBlockIds, 'class', $classId
    ));
    if ($assembled->isSuccessful()) {
        $learnerSource = $assembled->payload()['source'];
        // Use for the activity's own practice or feedback, without marking AC.
    }
}
```

이 확장은 문제 수정·활동 배정·코드 실행·정식 제출 생성·PRIMM 단계 진행을 추가하지 않는다. 정식 판정은 플랫폼의 기존 제출 화면과 서버 채점 경로를 사용한다. 소비자는 원본 스키마를 읽기 위해 Problems 내부 클래스나 저장소를 직접 참조하지 않는다.

공개 `template_hash`는 조각을 불투명 ID로 정렬한 뒤 계산한다. 조각 내용·언어·ID 집합에 연결되며 기준 배열 순서는 포함하지 않는다. 교사가 완성 코드를 바꾸면 새 조각 ID가 생성되어 이전 배열 요청이 거부된다. 최초 셔플은 기준 순서와 독립적이며 우연히 기준 순서와 같을 수 있다.

플랫폼 셸의 공통 UI는 `[data-oj-code-ordering]`를 찾아 배열 위젯을 지연 로드한다. 애드온은 조회한 공개 조각만 HTML 이스케이프하여 `[data-oj-ordering-list]` 목록에 렌더링하고, 각 조각에 `data-oj-ordering-block-id`, 고정 `<pre><code>`, `data-oj-ordering-move="up"/"down"` 버튼을 제공할 수 있다. `data-oj-ordering-status`는 이동 안내 영역이며 `data-storage-key`는 선택적인 탭 초안 키다. 초안 키에는 학생·문제·버전·학습 문맥을 포함한다.

로딩 후 `window.OJPlatformCodeOrdering.mount(root)`는 `getOrder()`와 `setOrder(blockIds)`를 제공한다. 변경 시 `oj-code-ordering-change` 이벤트의 `detail.blockIds`를 전달한다. 이 클라이언트 이벤트는 학습 기록 수집용이며 정답 판정의 근거가 아니다. 애드온 서버가 조각 ID와 현재 버전을 공개 `assemble()` 계약으로 재검증하고, 공식 채점은 기존 제출 경로로 수행한다.

## 선택형 코드 빈칸 채우기 확장

`AddonCodeCompletionRuntimeContextV1::codeCompletion()`은 PRIMM 등 애드온에서 기존 코드 빈칸 채우기를 선택적으로 재사용하는 공개 계약이다. 기본 런타임 인터페이스와 기존 생성자 호출은 유지한다. `instanceof AddonCodeCompletionRuntimeContextV1`와 결과의 `isSuccessful()`을 함께 확인하며, 기능이 준비되지 않은 플랫폼에서는 빈 payload와 안전한 실패 문제를 반환한다. Addon API 버전은 계속 `1.4.0`이다.

- `template(new AddonCodeCompletionQueryV1(actorUserId, problemId, contextType, contextId))`: 공개 시작 코드의 `{{OJ_BLANK:id}}` 토큰을 그대로 제공하며 각 빈칸의 `id`, `label`, `kind`(`inline` 또는 `block`), `max_bytes`만 제공한다.
- `assemble(new AddonCodeCompletionAnswerV1(actorUserId, problemId, templateHash, answers, contextType, contextId))`: `answers`의 `[빈칸 ID => 문자열]`을 기존 `CodeCompletionSourceMode`와 `SubmissionLanguageCatalog`로 복원한다. 해시, 모든 빈칸의 누락·추가, 한 줄 제한, 줄바꿈 정규화와 UTF-8 바이트 길이 제한을 기존 제출과 동일하게 적용한다. 성공은 복원 가능하다는 뜻이며 실행·정답·AC 판정이 아니다.

기본 문맥은 `general`, `null`이다. `class`, `group`, `contest`에는 양의 정수 문맥 ID가 필요하다. 두 호출은 매번 학습 확인의 현재 사용자·문제 열람·학급/그룹·대회 피드백 정책을 확인하고 Problems 공개 제출 정책으로 같은 문맥의 접근을 재검사한다. 권한 취소와 문제 버전 변경 후에는 이전 응답을 복원하지 않는다.

템플릿 성공 payload는 `{problem_id, language, template_hash, starter_code, blanks}`이고, 복원 성공 payload는 `{problem_id, language, template_hash, source}`이다. 원본 스키마 JSON, 정답 필드, 비공개 접두·접미 코드와 제출 코드는 반환하지 않는다. 템플릿은 빈칸 1~30개, 빈칸별 최대 길이는 1~65536바이트 범위다. `inline`은 줄바꿈을 허용하지 않고 `block`은 여러 줄을 허용한다. 복원 소스에는 학생 입력을 넣은 공개 시작 코드만 포함하며 비공개 래퍼를 붙이지 않는다.

```php
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1;

if ($runtime instanceof AddonCodeCompletionRuntimeContextV1) {
    $gateway = $runtime->codeCompletion();
    $template = $gateway->template(new AddonCodeCompletionQueryV1(
        $actorUserId, $problemId, 'class', $classId
    ));
    if ($template->isSuccessful()) {
        $publicTemplate = $template->payload();
        // Split starter_code at its tokens and render other content as escaped text.
        // Render inputs using blanks and save template_hash with the activity version.
    }

    // A later authenticated request supplies the saved hash and blank answer map.
    $assembled = $gateway->assemble(new AddonCodeCompletionAnswerV1(
        $actorUserId, $problemId, $submittedHash, $submittedAnswers, 'class', $classId
    ));
    if ($assembled->isSuccessful()) {
        $learnerSource = $assembled->payload()['source'];
        // Feed the activity's own practice/feedback flow; this is not an AC verdict.
    }
}
```

`$actorUserId`는 현재 인증된 사용자에서 얻는다. 소비자는 DTO 생성의 `InvalidArgumentException`을 잘못된 요청으로 처리하고 시작 코드·이름·학생 코드를 HTML에 넣을 때 이스케이프한다. 정식 제출과 채점은 기존 플랫폼 제출 경로를 사용한다. 이 API는 문제 생성·정답 채점·코드 실행 서비스가 아니다.

## 제출 화면 초안 전달

Addon API 1.7은 현재 학습자의 저장 코드와 언어를 기존 제출 폼으로 전달하는 선택형 확장을 추가한다. 공개 계약·짧은 보관 기간·접근 규칙·수동 제출 원칙은 [Addon API 1.7](addon-api-1.7.md)을 참고한다.

## 검증

- `tests/Contract/addon-code-ordering-runtime.php`: 공개 퍼즐 투영, 제출 순서 그대로의 복원, 버전·조각 검증, 요청별 권한 위임·문맥 전달, 비공개 자료 제한과 이전 생성자 fallback을 검사한다.
- `tests/Contract/addon-code-completion-runtime.php`: 공개 템플릿·토큰 보존, 정답·래퍼 비노출, 기존 제출 모드와 복원 제약 일치, 요청별 권한·버전 재검사와 optional runtime fallback을 검사한다.
- `tests/Contract/addon-learning-check-runtime.php`: 권한, 본인 소스, 공개 자료 제한, 제출 없는 경우와 대회 정책을 검사합니다.
- `tests/Contract/addon-api-v1.php`: 공개 인터페이스·DTO·인자·기본값의 엄격한 표면을 검사한다.
- `tests/Contract/addon-integration-validation.php`: 활동·자산·개인정보 기여와 자산 충돌·누락을 검사한다.
- `tests/Contract/addon-i06-context.php`: 명단 권한, 리비전, 표시·관리 이름, 5,000명 상한을 검사한다.
- `tests/Unit/submissions.php`: 정답 제출 조회의 사용자·문제·시간·유효성 범위를 검사한다.
