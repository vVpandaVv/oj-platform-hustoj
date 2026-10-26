# 공통 애드온 API 1.6.0

OJ Platform 2.4.0의 공개 API 버전은 `1.6.0`이다. 기존 V1 계약과 생성자 호출은 유지하며 학습자 연습 실행을 선택형 인터페이스로 추가한다. 교사용 비공개 예제 실행은 API 1.5에서 도입한 `AddonCodeExecutionRuntimeContextV1` 계약을 그대로 사용한다. 애드온은 공개 `AddonApi\V1` DTO와 인터페이스만 참조한다.

## 학습자 연습 실행

`AddonPracticeRuntimeContextV1::practice(): AddonPracticeGatewayV1`는 현재 학습자의 수업·그룹 참여 권한으로 실행한다. 기존 교사용 `codeExecution()`의 권한은 완화하지 않는다. 소비자는 런타임의 `instanceof AddonPracticeRuntimeContextV1`와 각 결과의 성공 여부를 확인한다.

```php
$material = new AddonPracticeMaterialV1(
    activityKey: 'primm-activity-17',
    revision: 'content-v3',
    contextType: 'class',
    contextId: 'class:12',
    language: 'python',
    tests: [['input' => "2 3\n", 'expected_output' => "5\n"]]
);
$gateway = $runtime->practice();
$availability = $gateway->availability($material);
$result = $gateway->submit(new AddonPracticeRequestV1(
    actorUserId: $currentUserId,
    material: $material,
    source: $learnerSource,
    testIndex: 0,
    idempotencyKey: 'attempt-20261025-1'
));
// pending이면 같은 material과 ticket으로 조회한다.
$result = $gateway->poll(new AddonPracticeQueryV1(
    actorUserId: $currentUserId,
    material: $material,
    ticket: $savedTicket
));
```

예제의 클래스들은 `CodingPanda\OjPlatform\AddonApi\V1` namespace에 있다. `actorUserId`는 로그인한 현재 사용자에서 얻는다. `material`은 매 호출마다 소비 애드온이 자신의 서버 저장소에서 읽고 권한을 검증한 **활성 활동의 현재 버전**으로 구성해야 한다. 브라우저가 보낸 활동 전체 JSON이나 임의 테스트를 그대로 DTO로 만들면 안 된다. 활동 단계, 문제 버전, 비공개·철회 상태와 해당 사용자의 활동 이용 권한은 소비 애드온의 책임이다. 플랫폼은 요청 시작과 결과 반환 시 현재 사용자 일치, 수업·그룹의 활성 상태와 참여 권한을 재확인한다.

자료는 `class` 또는 `group`만 허용한다. 문맥 ID는 `class:12` 또는 `group:8` 형식이다. 활동 키와 리비전은 안전한 토큰 160바이트 이하, 언어는 `python`, `c`, `cpp`, `java`, 테스트는 1~8개다. 각 테스트는 `input`, `expected_output` 두 문자열만 받으며 각각 UTF-8 4,096바이트 이하이고 NUL은 허용하지 않는다. 소스는 24,000바이트, 요청 키는 안전한 토큰 160바이트 이하이다. 테스트 인덱스는 0부터 시작한다.

티켓은 애드온·현재 사용자·활동 키·리비전·문맥·언어·테스트 전체에 서명으로 묶인다. 다른 활동이나 수정된 자료로 이전 티켓을 조회할 수 없다. 같은 요청 키와 같은 코드·입력은 기존 실행을 재사용한다. 코드를 수정한 재시도에는 새 요청 키를 사용한다. 개인 연습 실행에는 플랫폼 정식 제출이나 AC 점수를 부여하지 않으며 학생 제출 목록·성적·랭킹에 포함하지 않는다.

## 결과와 실패 구분

교사 예제 실행과 학습자 연습 실행은 같은 `AddonCodeExecutionResultV1`를 반환한다. `isSuccessful()`은 실행 요청 또는 조회의 성공을 뜻한다. 실제 코드의 정상 실행 여부는 `payload()['verdict']`로 구분한다.

| 필드 | 값 |
| --- | --- |
| `status` | `pending`, `completed` |
| `ticket` | 소비 애드온에만 보관하는 불투명 조회 티켓 |
| `verdict` | `pending`, `executed`, `compile_error`, `runtime_error`, `output_incomplete`, `time_limit`, `memory_limit`, `output_limit` |
| `actual_output` | 정상 종료와 전체 출력이 확인된 UTF-8 stdout, 최대 16,000바이트 |
| `diagnostics` | 표식·서버 경로·제어 문자를 제거한 안전한 오류 설명 |
| `result_code` | 기존 채점기의 결과 번호. 성공 판정에는 `verdict`를 사용 |

출력 잘림, 비정상 UTF-8, NUL, 완료 표식 누락은 정상 실행으로 취급하지 않는다. 예상 출력과 실제 출력의 비교 및 학습 피드백은 소비 애드온이 수행한다. `executed`가 아닌 결과에서 빈 `actual_output`을 예상 출력과 비교해 정답으로 처리해서는 안 된다.

실패 결과에는 빈 payload와 `AddonApiProblemV1`를 반환한다. `addon_execution_busy`는 실행 한도, `addon_execution_timeout`은 300초 넘게 완료되지 않은 실행이다. 두 오류는 재시도 가능하지만 지수형 대기 등으로 조회 간격을 늘려야 한다. 대기 초과는 코드 오답이나 AI 수정 근거가 아니다. 권한 취소는 `addon_access_denied`로 차단한다. 티켓·네이티브 식별자·서명·검증 표식은 AI 프롬프트나 학습자 응답에 포함하지 않는다.

## 실제 언어 지원표

실행 가능한 언어를 설치 직후 네 가지로 고정해 광고하지 않는다. `/oj-console/submissions/settings`의 **예제·실습 실행 지원**에서 관리자가 언어별 **실행 검사**를 누르고 잠시 후 **결과 조회**를 누른다. 기존 채점기의 `custominput` 경로로 고정 프로그램이 실행되어 정확한 전체 출력이 확인되어야 `passed`가 된다. `availability()->languages()`에는 사이트 제출 설정에서 허용되고 실제 검사를 통과한 언어만 포함된다.

제출 설정 또는 Judge DB 연결 설정을 변경하면 기존 검사 결과는 무효화되어 `untested`로 돌아간다. 변경 후 다시 검사한다. DB 연결 성공, 실행 요청 생성 또는 테스트용 가짜 엔진 통과만으로 실서버 언어 지원을 확인한 것은 아니다. Python, C, C++, Java 각각의 실제 컴파일러·런타임과 채점 데몬을 운영 서버에서 확인해야 한다.

## 실행 한도와 독립 정리

같은 제출 설정 화면에서 비공개 실행의 사이트 전체 동시 수, 사용자별 동시 수, 사용자별 시간당 새 실행 수를 설정한다. 기본값은 각각 **8, 2, 120**이고 허용 범위는 1~100, 1~20, 1~1,000이다. 교사 예제, 학생 연습, 관리자 언어 검사는 같은 한도를 사용한다. 중복 요청 재조회는 새 실행을 만들지 않는다. 원자적 입장 검사는 네이티브 DB의 이름 잠금으로 보호한다.

`oj_platform_private_execution_cleanup` WordPress cron은 최초 약 5분 뒤부터 매시간 독립적으로 실행한다. 완료된 비공개 실행과 미발송 준비 기록 중 24시간 지난 항목을 한 번에 최대 100개 정리하고 AI 원장도 별도로 정리한다. 설정 화면의 **보관 정리**에서도 실행 기록을 정리할 수 있다. 화면에는 전체·진행 중·5분 초과 대기 실행 수가 표시된다. WordPress cron을 운영 서버의 실제 스케줄러로 깨우는 기존 설치 절차가 필요하다.

실행 중인 네이티브 작업은 채점기가 로그를 뒤늦게 다시 쓰는 문제를 방지하기 위해 소유 행을 유지한다. 대기 초과를 임의 완료 처리하거나 실행 중 행을 삭제하지 않는다. 멈춘 채점 데몬은 운영자가 복구한 뒤 결과가 종결되어야 최종 정리가 가능하다. 계정 삭제 또한 진행 중 실행이 종결될 때까지 차단하여 개인 코드의 고아 기록이 생기지 않게 한다.

네이티브 실행의 사용자 가명에는 플랫폼 소유 비공개 옵션 `oj_platform_private_execution_identity_key`를 사용한다. 최초 활성 런타임의 `init`에서 전용 도메인 HMAC 키를 파생하여 보관하고 이후 AUTH salt 변경과 독립적으로 보존한다. 원본 AUTH salt를 DB에 복사하지 않는다. 티켓 서명은 현재 AUTH salt를 사용하므로 회전 시 기존 티켓은 무효가 되지만 같은 요청을 다시 보내 얻은 티켓으로 기존 실행을 재사용하고 개인 삭제 대상을 계속 찾을 수 있다. 이 옵션은 비밀로 백업하고 수동 삭제·재생성하지 않는다. 민감 설정 재고에 포함하며 일반 설정 조회로 원문을 제공하지 않는다. 플랫폼 전체 데이터 삭제 시 함께 지운다.

2.3.25의 기존 비공개 실행은 당시 AUTH salt가 현재 값과 같을 때 기존 티켓과 완료 표식 조회를 계속 허용하고 추가 prefix 조회로 개인 삭제한다. 기존 salt가 이미 유실된 실행의 사용자 대응 관계는 소급 복구할 수 없다. 업그레이드 후 AUTH salt를 바꾸기 전 기존 실행이 모두 종결되고 24시간 보관 정리되었는지 확인한다. 새 버전에서 생성한 실행부터 독립 identity 키로 salt 회전 이후 삭제를 보장한다.

## AI 요청 영속 원장

기존 공개 `ai()->execute()` 요청 키를 실제 영속 원장에 연결한다. 키는 **애드온 + 현재 사용자 + 요청 키**에 묶이고 작업 계약·입력·모델 프로필·라우팅 리비전의 해시를 함께 확인한다. 같은 요청의 완료 결과는 제공자를 다시 호출하지 않고 재사용한다. 같은 키로 입력이나 모델 설정이 바뀌면 새 생성 요청을 요구한다. 현재 사용자와 AI 이용 권한은 재사용 시에도 다시 검증한다.

요청 예약은 DB 이름 잠금과 InnoDB 트랜잭션으로 보호하고 제공자 HTTP 호출 전에 확정한다. 진행 중 요청은 `addon_ai_request_pending` 및 `retryable=true`로 상태 재확인을 요구한다. 프로세스 중단·네트워크 실패·완료 저장 실패 등 외부 호출 결과를 확정할 수 없으면 `addon_ai_outcome_uncertain`, `retryable=false`로 중복 발송을 막는다. 300초 지난 예약도 불명 상태로 바뀐다. 이 경우 사용량을 확인하고 **명시적으로 새 생성**을 시작해야 한다. 동일 키 자동 재호출로 비용을 다시 발생시키지 않는다. 제공자 자체의 과금 취소나 외부 요청 중단을 보장하지 않는다.

`addon_ai_requests`는 Settings 소유의 추가 InnoDB 테이블이다. 기존 사이트는 플랫폼 설치의 **설치 재개**로 새 테이블을 준비한다. 기존 문제·제출을 다시 가져오지 않는다. 원장은 최대 5,000행, 완료 결과 1건 최대 1MiB, 완료 결과와 진행 중 결과의 예약 합계 64MiB를 제한한다. 한도 도달 시 제공자를 호출하기 전에 중단한다. 원문 입력·API 키·제공자 URL은 원장에 저장하지 않으며 AI 결과에는 코드·수업 자료가 포함될 수 있다.

완료·불명 기록은 마지막 갱신 후 7일이 지나면 cron이 최대 200개씩 정리한다. 개인 삭제는 해당 사용자 원장과 결과를 삭제하고 진행 중 응답의 뒤늦은 저장을 차단한다. 원장 보존 기간이 끝난 요청 키의 결과 재사용은 보장하지 않으므로 소비 애드온은 생성 작업 ID를 재활용하지 않는다.

PRIMM은 생성 작업을 최초 시작 후 24시간까지만 재개하도록 제한한다. 보존 기간이 지난 중단 작업이 같은 키로 제공자를 다시 호출하지 않도록 소비 애드온도 원장 보존 기간보다 짧은 작업 수명을 AI 호출 이전에 강제하고, 만료된 작업은 명시적인 새 생성으로만 시작해야 한다.

## 문제·수업 저장 후 공개 이벤트

두 hook은 저장 완료 뒤 최소 식별자만 전달하는 알림이다. 내용을 캐시하지 말고 현재 사용자 권한과 최신 공개 문제 자료를 다시 조회한다. 소비자 예외는 로깅하며 이미 완료된 원본 저장을 실패로 되돌리지 않는다. 소비자는 긴 AI 작업을 hook 안에서 실행하지 말고 자신의 작업 큐에 등록한다.

| hook | 단일 배열 인자 |
| --- | --- |
| `oj_platform_problem_saved` | `problem_id:int`, `actor_user_id:int`, `change:created\|updated`, `changed_at:UTC 날짜 문자열` |
| `oj_platform_class_problems_saved` | `class_id:int`, `actor_user_id:int`, `problem_ids:list<int>`, `changed_at:UTC 날짜 문자열` |

문제 이벤트는 원문과 플랫폼 정보 저장 및 필요한 게시 전환이 성공한 뒤 발행한다. 보관·복원도 `updated` 알림을 발행한다. 수업 이벤트는 문제 연결 전체 트랜잭션의 commit 후 발행한다. 아직 수업에 배정되지 않은 문제는 수업 문맥 공개 조회가 거부될 수 있으므로 자동 준비는 수업 문제 연결 이벤트를 사용해 다시 시도한다. hook의 행위자 ID만 신뢰해 다른 교사 수업의 생성을 우회해서는 안 된다.

학급·그룹 전체 명단은 기존 `AddonAssignmentRosterGatewayV1::listAssignmentLearners(AddonAssignmentRosterQueryV1)`를 재사용한다. 현재 담당 권한, 문맥 리비전, 명단 리비전과 커서 범위를 검증하며 미시작 학습자를 포함한 보고서는 이 명단을 기준으로 소비 애드온의 활동 기록과 결합한다. 플랫폼 내부 수업 저장소나 사용자 테이블을 직접 읽지 않는다.

## 검증 범위

`tests/Unit/code-execution.php`는 실행 출력·비정상 종료·티켓 격리·권한 취소·지원표·한도·정리를 검사한다. `tests/Unit/addon-ai-ledger.php`는 완료 재사용·입력 변경·동시 예약 상태·중단 불명·삭제 응답 차단·보관 한도를 검사한다. 문제·수업 단위 검사는 성공한 commit 이후에만 이벤트를 발행하는지 확인한다. 공개 DTO 표면과 모듈 경계는 Contract 검사에 포함한다.

로컬 검사는 네이티브 SQL을 SQLite 호환 fixture에서 실행하고 MySQL 이름 잠금 성공을 가정한다. 실제 MySQL 동시 요청, WordPress cron, 제공자 과금, 네이티브 채점기 네 언어의 설치 환경을 대신 검증하지 않는다. 운영 배포 전 실제 서버의 언어별 성공·컴파일 실패·런타임 실패·시간 초과·출력 잘림, 교사 생성과 학생 연습 동시 요청, cron·계정 삭제 경합을 확인하고 기록한다.
