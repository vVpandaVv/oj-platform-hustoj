# 공통 애드온 API 1.5.0

OJ Platform 2.3.25는 교사용 수업 예제를 기존 OJ 채점 서버에서 검증하는 선택형 공개 계약을 추가한다. 기존 `AddonRuntimeContextV1`의 메서드는 그대로 유지한다. 이전 1.x 애드온과 기존 런타임 생성자는 호환되며, 소비자는 `instanceof AddonCodeExecutionRuntimeContextV1`와 결과의 성공 여부를 확인한다. 기존 조회·AI·코드 순서 배열·빈칸 채우기 계약은 [API 1.4 문서](addon-api-1.4.md)를 따른다.

```php
if ($runtime instanceof AddonCodeExecutionRuntimeContextV1) {
    $execution = $runtime->codeExecution();
    $availability = $execution->availability();
    $started = $execution->submit(new AddonCodeExecutionRequestV1(
        $actorId, 'python', "print(int(input()) * 2)\n", "3\n", 'lesson:7:demo:1'
    ));
    if ($started->isSuccessful()) {
        $state = $execution->poll(new AddonCodeExecutionQueryV1(
            $actorId, $started->payload()['ticket']
        ));
    }
}
```

## 공개 계약

`AddonCodeExecutionGatewayV1`은 다음 세 메서드를 제공한다.

- `availability(): AddonCodeExecutionAvailabilityV1`: `isAvailable()`, `languages()`, `problem()`. Python/C/C++/Java를 지원하며 실제 판정은 해당 언어의 기존 채점 서버 설정을 따른다. 연결·custominput 조회 가능 여부를 확인하며 채점 데몬의 실제 실행 가능성은 제출·조회로 확인한다.
- `submit(AddonCodeExecutionRequestV1): AddonCodeExecutionResultV1`: 생성자 인수는 `actorUserId`, `language`, `source`, `input`, `idempotencyKey`. 소스는 UTF-8 24,000바이트, 입력은 16,000바이트까지이며 NUL은 허용하지 않는다. 요청 키는 160자까지의 안전한 토큰이다. 같은 애드온·교사·키·언어·코드·입력의 재요청은 보관 중인 실행을 재사용한다.
- `poll(AddonCodeExecutionQueryV1): AddonCodeExecutionResultV1`: 생성자 인수는 `actorUserId`, `ticket`. 티켓은 애드온과 현재 교사에 묶인 서명을 검증하며 다른 교사·애드온은 재사용할 수 없다.

성공 결과의 `payload()`는 다음 필드만 가진다.

| 필드 | 의미 |
|---|---|
| `ticket` | 소비자가 비공개 작업 상태에 저장하는 조회용 티켓 |
| `status` | `pending` 또는 `completed` |
| `verdict` | `pending`, `executed`, `compile_error`, `runtime_error`, `time_limit`, `memory_limit`, `output_limit`, `output_incomplete` |
| `actual_output` | `executed`일 때 전체 UTF-8 표준 출력. 최대 16,000바이트이며 개행을 보존한다 |
| `diagnostics` | 실패 근거. 최대 8,000바이트, 절대 서버 경로·검증 표식·제어 문자를 제거한다 |
| `result_code` | 기존 OJ 판정 코드. 정상 사용자 입력 실행은 13(TR)이며 정식 문제의 AC(4)와 다르다 |

`isSuccessful()`은 API 요청의 성공 여부다. 코드를 검증하려면 `status === completed`와 `verdict === executed`를 모두 확인한 뒤 예상 출력과 실제 출력을 비교해야 한다. 예상 출력을 API에 전달하지 않으므로 AI가 만든 예상 출력이 실행 성공으로 오인되지 않는다. `executed`는 해당 입력의 정상 실행만 뜻하며 알고리즘의 일반적인 정답이나 숨은 테스트 통과를 보장하지 않는다.

연결 오류, 만료·유실된 기록, 권한 철회, 300초를 넘긴 채점 대기는 빈 payload와 안전한 `AddonApiProblemV1`을 반환한다. 연결·대기 오류는 `retryable=true`인 운영 오류로 구분하며 코드 수정 근거로 쓰지 않는다. 컴파일·실행·출력 실패와 예상 출력 불일치는 소비자가 제한된 AI 수정 루프의 근거로 사용할 수 있다. 채점 진행 중에 교사 권한을 잃으면 결과를 전달하지 않는다.

## 기존 채점 서버 재사용과 격리

구현은 기존 `custominput(solution_id,input_text)`와 `problem_id=0` 실행 경로를 사용한다. 채점 DB 스키마를 추가하거나 수정하지 않는다. 해당 테이블과 기존 제출 관련 테이블에 대한 플랫폼의 쓰기 권한이 필요하다. 소스·입력을 모두 기록하고 다시 읽어 확인할 때까지 시스템 행은 결과 14(MC) 상태를 유지한다. 준비가 끝나면 결과 0으로 공개한다. MySQL advisory lock으로 같은 실행의 중복 생성을 막으며 중간에 끊긴 준비는 같은 키로 복구한다.

시스템 실행은 교사의 실로그인 대신 비밀 키로 만든 가명, `problem_id=0`, `valid=0`, `contest_id=0`을 사용한다. 수업·그룹·대회 제출 링크, 학생 제출 이벤트, AI 피드백 요청을 생성하지 않는다. 일반 제출의 검색·개수·묶음 조회는 `problem_id>0`으로 제한하며 상세 DTO도 문제 0을 거부한다. 순위는 기존 문제 테이블 조인으로 이 실행을 제외한다.

기존 네이티브 엔진은 TR(13)에 정상 출력과 오류 로그를 모두 담을 수 있어 결과 숫자만으로 성공을 판단하면 안 된다. 플랫폼은 제출 복사본에 비공개 완료 표식을 계측하고, 전체 표식이 출력 마지막에 존재할 때만 실제 출력을 제공한다. 컴파일 오류, 비정상 종료, 제한 초과, 빈 오류 응답, 잘린 출력, 잘못된 UTF-8, NUL 출력은 성공으로 처리하지 않는다. Java는 일반적인 `class Main` 진입점을 계측하며 다른 형식은 실행 검증을 통과할 수 없어 소비자가 수정하도록 한다. 원본 예제와 예상 출력은 계측 소스와 구분해서 소비자 저장소에 보관한다.

동작 근거는 고정된 [HUSTOJ judge_client 소스](https://github.com/zhblue/hustoj/blob/b3502371/trunk/core/judge_client/judge_client.cc)의 `test_run`, `watch_solution`, `_addreinfo_mysql`과 [judged 큐 소스](https://github.com/zhblue/hustoj/blob/b3502371/trunk/core/judged/judged.cc)다. 다른 엔진 버전이나 별도 런타임은 설치 환경에서 정상 출력·컴파일 오류·비정상 종료를 먼저 점검해야 한다.

## 보관과 삭제

실행 소스·입력·출력·진단·임시 행은 학생 제출 보관 정책과 분리된다. 다음 실행 요청에서 24시간이 지난 이 기능의 가명·문제 0·무효 행 중 네이티브 완료 상태(11/13)와 아직 공개되지 않은 준비 상태(14)를 최대 100건씩 삭제한다. 사용이 없으면 정리는 다음 요청까지 지연된다. 실행 중인 채점 프로세스가 소유자 없는 로그를 다시 쓰지 않도록 진행·대기 중인 행은 유지한다. 채점 서버가 중단된 작업은 운영 확인과 완료 후 정리한다.

계정 삭제 흐름에도 참여해 해당 교사의 임시 실행과 연관 소스·입력·진단을 삭제한다. 진행 중이거나 다른 요청이 준비하는 실행이 있으면 계정 삭제를 완료하지 않고 해당 실행이 끝난 뒤 재시도하도록 한다. 제출 준비·삭제 모두 동일한 가명 잠금을 사용하며 네이티브 소스의 로그 작성 뒤 완료 상태 변경 순서를 전제로 한다. 애드온 자체에 저장된 생성 작업·검증 보고서의 보관과 개인정보 삭제는 해당 애드온 책임이다. 소비자는 티켓과 교사용 실패 근거를 학습자 공개 응답이나 AI 입력으로 그대로 전달하지 않는다.

테스트는 공개 DTO와 권한·티켓 격리·완료 표식·안전한 진단, 실제 준비 SQL의 SQLite 격리 검증을 포함한다. MySQL advisory lock의 실동시성, 실제 채점 데몬과 각 언어 런타임은 실환경에서 확인해야 한다.
