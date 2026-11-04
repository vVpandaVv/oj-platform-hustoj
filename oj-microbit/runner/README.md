# 마이크로비트 핀 채점 실행기

이 서비스는 WordPress 플러그인이 전달한 MakeCode 프로젝트를 **Microsoft PXT 컴파일러로 다시 컴파일**하고, 공식 micro:bit 시뮬레이터의 핀 읽기·쓰기 함수를 계측하여 실행합니다. GPIO·위치 서보·펄스 폭·레지스터 방식 I²C 장치를 실물 없이 검증합니다. 학생이 보내는 점수나 출력 로그는 받지 않습니다. 기본 실행 환경은 네트워크가 없는 일회성 Docker 컨테이너입니다.

## 고정된 실행 환경

- Node.js 24.22.1
- `pxt-microbit` 9.1.1 / `pxt-core` 13.0.1
- 실행 식별자: `pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3`
- `core`, `radio`, `microphone`, `servo` 기본 패키지의 번들만 사용합니다. 제출 프로젝트가 임의 URL이나 GitHub 확장 패키지를 다운로드하도록 허용하지 않습니다.
- 블록·Python 편집 자료를 함께 보관하되, MakeCode가 저장한 `main.ts`를 실행 대상으로 사용합니다. 플러그인은 채점 전 편집기의 최신 저장을 기다려야 합니다.
- HEX는 브라우저의 MakeCode 컴파일·다운로드 경로에서 생성합니다. 이 실행기는 HEX 생성 서버가 아닙니다.
- 학생 TypeScript의 사용자 정의 네이티브 연결 메타데이터, ambient `declare`, `.d.ts` 파일은 컴파일 전에 AST와 PXT 주석 파서로 거부합니다. 기본 패키지의 선언은 유지하며 일반 함수·문자열·설명·블록 표시 메타데이터를 차단하지 않습니다.

## 서버 설치

같은 호스트의 Ubuntu 24.04 amd64·systemd·PHP 8.3 WordPress는 동봉 설치기를 사용합니다. ZIP 활성화 후 SSH에서 다음 명령을 실행합니다. 다른 경로는 `--wp-root`, 계정은 `--wp-user`, 계획은 `--dry-run`, 설치 후 진단은 `--check`를 사용합니다. 인증키는 명령에 입력하지 않습니다.

```sh
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/runner/install.sh
```

Node·Docker 준비부터 실제 GPIO 채점·WordPress 자동 연결까지 수행합니다. 기존 Docker 경로에서는 APT·저장소·Docker 시작/재시작 없이 재사용하고 도구가 부족하면 중단합니다. 포트 소유권과 설치·Docker 파일시스템의 각 4GiB 여유를 검사합니다. 재실행·백업·소스 불변·실패 복원과 실제 Linux 검증 한계는 [설치기 안내](../docs/INSTALLER.md), HUSTOJ 병행 조건은 [공존 점검서](../docs/HUSTOJ-COEXISTENCE.md)를 확인합니다. 아래는 수동 설치와 외부 호스트 배치의 개요입니다.

WordPress의 업로드 폴더나 공개 웹 경로에서 실행하지 않습니다. 별도 실행기 호스트에 Node.js 24와 Docker Engine을 준비하고 `runner/`를 배치합니다. PHP가 이 서비스에 요청하고, 브라우저는 WordPress REST API만 호출합니다. 운영 설치 순서는 저장소의 중앙 설치 안내서를 따릅니다.

```sh
npm ci --ignore-scripts --no-audit --no-fund
docker build -t oj-microbit-worker:1.0.0-rc.9 .
```

서버 프로세스에는 다음 환경 변수를 설정합니다. 토큰은 32바이트 이상의 예측 불가능한 비밀값을 만들고 WordPress 설정에도 같은 값을 입력합니다. 실제 토큰을 소스·로그·브라우저 설정에 넣지 않습니다.

| 환경 변수 | 기본값 | 설명 |
|---|---|---|
| `MICROBIT_RUNNER_TOKEN` | 없음, 필수 | WordPress와 실행기 사이의 Bearer 토큰 |
| `MICROBIT_RUNNER_HOST` | `127.0.0.1` | 수신 주소 |
| `MICROBIT_RUNNER_PORT` | `3037` | 수신 포트 |
| `MICROBIT_WORKER_IMAGE` | `oj-microbit-worker:1.0.0-rc.9` | 빌드한 실행 전용 이미지 |
| `MICROBIT_RUNNER_MODE` | `docker` | 운영 환경은 `docker` 사용 |

```sh
node src/server.mjs
```

같은 호스트이면 loopback을 유지합니다. 별도 호스트이면 WordPress 서버만 접근 가능한 사설망·방화벽 및 TLS 역방향 프록시를 사용합니다. 공개 인터넷으로 실행기 포트를 노출하지 않습니다. 실행기 프로세스에는 Docker 명령을 실행할 권한이 필요합니다. Docker daemon 접근 권한은 호스트 관리 권한에 가깝기 때문에 이 서비스를 다른 중요 애플리케이션과 분리한 전용 호스트에 배치합니다. 실행 이미지에는 API 비밀 토큰을 전달하지 않습니다.

## 실행 격리와 제한

각 요청은 별도 `docker run` 작업으로 실행합니다. `--network=none`, 읽기 전용 파일 시스템, 비루트 사용자, 모든 capability 제거, `no-new-privileges`, PID 64개, 메모리 384MiB, CPU 1개, 최대 40초 벽시계 제한을 적용합니다. 작업명은 서버가 생성하며, 시간 초과 시 해당 작업 컨테이너를 제거합니다. 사용자 파일을 호스트 파일 경로로 쓰거나 Docker 명령에 보간하지 않습니다.

이미지 자체의 `/usr/bin/timeout`도 40초 제한을 적용하므로 API 부모의 강제 종료와 별개로 작업 시간이 제한됩니다. `--pull=never`로 운영 중 원격 이미지 다운로드를 시도하지 않습니다. 이미지 존재 확인은 진행 중 조회를 공유하고 성공·실패를 5초 캐시합니다. 이는 실제 PXT 실행을 인증하는 검사가 아니므로 배포 후 간단한 GPIO 요청을 실제 `/v1/run`으로 실행해 확인합니다.

서비스의 health와 실행 응답에는 `sandbox`가 포함됩니다. WordPress는 `docker-no-network`만 수용하며 개발용 `test-process`나 필드 누락을 거부합니다. 기존 실행기를 그대로 두면 새 플러그인의 실행 요청이 거부되므로 동봉 이미지와 서비스를 함께 갱신합니다. 직접 Node 테스트는 기존처럼 명시적인 개발 프로세스 모드를 사용할 수 있습니다.

시뮬레이터 안에는 `process`, `require`, `fetch` 또는 브라우저 네트워크 API가 없습니다. 별도 VM 컨텍스트와 1초 연속 CPU 제한도 사용하지만 **VM 자체를 보안 경계로 취급하지 않습니다.** 운영 보안 경계는 Docker 작업 컨테이너입니다. 실제 운영 호스트에서 Docker 격리 옵션·메모리 제한·시간 초과 회수 동작을 확인한 뒤 사용합니다.

프로젝트는 32개 파일·256KiB 이하, 시나리오는 20개 이하, 개별 가상 시간은 30초 이하, 총 가상 시간은 120초 이하입니다. 입력·검사 목록은 시나리오당 각각 256개 이하입니다. HTTP 요청은 2MiB, 작업 결과는 4MiB까지 받고 동시에 2개 작업을 실행합니다. 바쁜 경우 `429`를 반환합니다. 출력이 너무 많거나 무한 반복에 빠진 작업은 통과시키지 않습니다.

## 핀·시간 의미

입력 채널은 `digital` 0/1 또는 `analog` 0~1023입니다. 출력 채널은 `digital` 0/1, `pwm` 0~1023, `servo` 0~180도, `servo_pulse` 1~20000µs입니다. 핀은 0~16, 19, 20을 사용하며 아날로그 입력은 0~4, 10으로 제한합니다. I²C 장치를 선언하면 P19·P20을 버스용으로 예약하고 설정과 실행 시 일반 GPIO 접근을 거부합니다. 실제 보드에서 LED 화면·버튼과 공유되는 핀은 교사가 과제 구성에 맞게 선택해야 합니다.

테스트마다 새 공식 DalBoard와 변수·타이머 상태로 시작합니다. 가상 시간은 0ms부터 진행하고 난수는 고정된 초기값을 사용합니다. MakeCode의 `pause`, `forever`, 백그라운드 실행은 실제 PXT 스케줄러를 사용합니다. 0ms 입력은 프로그램 시작 전에 적용합니다. 동일 시각의 후속 입력은 입력 목록 순서대로 처리하고 해당 시각의 예약 출력보다 먼저 반영합니다. GPIO의 입력 읽기와 출력 기록은 별도로 유지합니다.

검사는 `afterMs`부터 `afterMs + withinMs` 사이에 기대 신호가 나타나고, 그때부터 `holdMs` 동안 유지되었는지를 확인합니다. PWM의 수치 허용 오차는 `tolerance`로 설정합니다. 디지털 검사의 허용 오차는 0입니다. 프로그램이 실제로 한 번도 출력하지 않은 초기 0은 정답으로 간주하지 않습니다. 이전에 쓴 값이 유지되는 동작은 유효한 출력 상태입니다. 유지 구간 끝과 같은 시각에 값이 달라지면 해당 구간은 실패합니다. 핀 방향은 문제의 연결 설정에 고정합니다. 입력 핀에 쓰거나 출력 핀을 읽어 방향을 변경하는 프로그램은 실행 오류로 처리합니다.

복합 출력은 선택 필드 `scenario.outputSequences`로 검사합니다. 각 항목은 `{pin,signal,afterMs,steps}`이고 `steps`는 `{expected,withinMs,holdMs,tolerance}` 목록입니다. 첫 동작은 `afterMs`부터 반응을 기다립니다. 실제 기대 상태가 처음 관찰된 시각이 `matchedAtMs`, 그때부터 유지 시간을 채운 시각이 `completedAtMs`입니다. 다음 동작은 앞 동작의 실제 `completedAtMs`부터 반응을 기다립니다. 쓰기 없는 초기값은 사용하지 않으며, 시작 시각 이전의 실제 출력 쓰기가 계속 유지되면 그 상태는 관찰할 수 있습니다.

동작 순서의 유지 구간은 `[matchedAtMs, completedAtMs)`로 계산해 종료와 정확히 같은 시각에 다음 값으로 바뀌는 프로그램을 허용합니다. 구간 도중 값이나 신호가 달라지면 실패하고 뒤의 일치 구간으로 재시도하지 않습니다. 실패한 동작 이후의 동작은 실패·관찰 시각 `null`로 표시합니다. 이 동작은 기존 `assertions`의 독립 시간 구간 검사와 구분하며 기존 규칙은 변경하지 않습니다.

출력 순서는 시나리오당 최대 64개, 순서당 1~20동작, 순서의 전체 동작 수는 최대 256개입니다. 모든 값·신호·오차는 일반 핀 검사와 같은 범위를 사용합니다. `afterMs + sum(withinMs + holdMs)`가 시나리오 실행 시간 안에 들어야 하므로 가장 늦은 응답에도 전체 동작을 관찰할 수 있습니다. 각 동작을 하나의 검사로 점수에 합산합니다. 실행 오류가 생긴 시나리오는 동작 순서의 모든 동작도 실패 처리합니다.

`pins.servoWritePin`은 위치 서보 각도로, `pins.servoSetPulse`는 20ms 주기의 펄스 폭으로 기록합니다. 번들 `servo` 패키지의 `servos.P0/P1/P2.setAngle()`·`setPulse()`도 같은 계측을 경유합니다. 공식 시뮬레이터의 `servoSetPulse`가 미구현 상태이므로 이 실행기가 문서에 정의된 펄스 모델을 보완합니다. `servoSetContinuous(false)`로 위치 모드를 고르는 것은 허용하지만 실제 출력으로 기록하지 않습니다. 연속 회전 모드인 `servoSetContinuous(true)`·`servos.P1.run()`은 지원하지 않습니다. 같은 핀을 각도·펄스·PWM·디지털 출력 사이에서 바꾸면 신호 종류가 달라져 유지 조건도 다시 평가합니다. 펄스 폭 1~20000µs는 테스트 모델의 허용 범위이며 특정 서보의 기계적 동작 범위를 의미하지 않습니다.

펄스 측정·SPI·핀 오디오·터치 핀 사건 API는 전용 모델이 필요하므로 지원하지 않는 기능으로 실패시킵니다. 연결한 버튼은 `digitalReadPin`으로 읽습니다. 저항식 터치를 사용하는 `onPinPressed`는 디지털 버튼 입력과 구분합니다. 내장 센서와 무선 등 공식 시뮬레이터의 다른 기능에 대한 전용 입력 fixture는 제공하지 않습니다. GPIO 표시용 모터 속도·LED 밝기·서보 위치는 출력값의 시각화이며 전기·기계적 물성 시뮬레이션은 아닙니다.

## I²C 레지스터 장치 모델

공식 MakeCode 문서는 I²C가 표준 시뮬레이터에서 지원되지 않는다고 설명합니다. 이 실행기는 **자체 레지스터 장치 모델**을 공식 PXT 런타임의 `i2cReadBuffer`·`i2cWriteBuffer` shim에 연결합니다. `i2cReadNumber`·`i2cWriteNumber`는 원래 PXT TypeScript·Buffer 변환을 실행하므로 숫자 포맷과 바이트 순서를 유지합니다. 장치의 실제 전기 신호, ACK/NACK, 버스 경합이나 특정 센서 명령 전체를 재현하는 모델은 아닙니다.

과제의 `i2cDevices`에 7비트 주소(0~127)와 레지스터 맵을 선언합니다. 최대 16개 장치, 장치마다 0~255번 레지스터 256개까지 사용할 수 있습니다. 각 항목은 `{register,value,writable}`이며 값은 0~255입니다. `writable`을 생략하면 `false`인 읽기 전용 센서 입력입니다. 학생 프로그램은 `writable:true`로 지정한 액추에이터 레지스터에만 데이터를 쓸 수 있습니다. 환경 입력인 `i2cInputs`는 읽기 전용 센서 레지스터도 갱신할 수 있습니다.

- 각 장치의 현재 레지스터 포인터는 시나리오마다 0에서 시작합니다.
- 1바이트 쓰기 `[register]`는 포인터 선택이며 읽기 전용 레지스터에도 허용합니다.
- 여러 바이트 쓰기 `[register, ...data]`는 해당 주소부터 연속 갱신하고 포인터를 다음 위치로 옮깁니다. 대상 레지스터가 모두 선언되어 있고 쓰기 가능해야 합니다.
- N바이트 읽기는 현재 포인터부터 읽고 포인터를 N만큼 옮깁니다. 레지스터 번호는 255 다음에 0으로 순환합니다.
- 전송 크기는 1~64바이트입니다. 미선언 장치·레지스터, 읽기 전용 데이터 쓰기, 잘못된 크기는 실행 오류로 처리합니다. 실패한 쓰기가 일부 레지스터만 바꾸지 않도록 전체 대상을 먼저 검사합니다.
- `repeated`는 실제 호출의 플래그를 기록하고 기대값과 비교합니다. 기본값은 `false`입니다.

`scenario.i2cInputs`의 `{atMs,address,register,value}`로 특정 시각에 센서 값을 바꿉니다. `scenario.i2cAssertions`는 `{address,operation,data,repeated,afterMs,withinMs}`이며 `operation`은 `read` 또는 `write`, `data`는 실제 버스의 바이트 배열입니다. 교사가 적은 검사 순서대로 실제 트랜잭션을 매칭하고 한 트랜잭션으로 두 검사를 통과시키지 않습니다. 주소·방향·바이트·반복 시작 플래그·시간 조건이 모두 맞아야 통과합니다. GPIO·서보 검사와 I²C 검사의 통과 수를 합산해 점수를 계산합니다.

새 I²C 필드를 생략한 기존 GPIO 과제는 빈 장치·입력·검사 목록으로 처리합니다. I²C 장치만 사용하는 과제는 GPIO `connections:[]`로 실행할 수 있습니다.

## API

모든 경로에 `Authorization: Bearer <비밀 토큰>`이 필요합니다. 요청·응답은 JSON이고 응답을 캐시하지 않습니다.

- `GET /health`: 실행기 버전, Docker 이미지 준비 상태, 격리 방식, 동시 작업 상태. 운영 정상 응답의 `sandbox`는 `docker-no-network`입니다.
- `POST /v1/run`: `{project, exercise}`를 받아 프로젝트를 다시 컴파일하고 전체 시나리오를 평가합니다.
- `POST /v1/preview`: 같은 요청 형식으로 실행합니다. PHP가 공개 입력과 빈 `assertions`로 만든 시나리오를 전달하면 실제 핀 출력 trace와 `score:null`을 반환합니다.

`project`는 `{name,files}`이며 `files`에는 `main.ts`, `pxt.json`을 포함합니다. `exercise`는 `connections`, `scenarios`와 선택적인 `i2cDevices`를 포함합니다. 핀 연결은 `{pin,direction,signal,device,label}`, 입력은 `{atMs,pin,signal,value}`, 출력 검사는 `{pin,signal,expected,afterMs,withinMs,holdMs,tolerance}`입니다. `servo`·`servo_pulse` 신호의 장치 종류는 `servo`입니다.

응답에는 `status`, `passed`, `score`, `scenarios`, `runtimeVersion`, `projectHash`, `trace`, `i2cTrace`, `traceScenarioId`, `diagnostics`가 있습니다. 상태는 `graded`, `preview`, `compile_error`, `runtime_error` 중 하나입니다. 점수는 전체 출력·I²C·출력 순서 동작 검사 중 통과한 비율이며 소수 둘째 자리까지 표시합니다. 시나리오 결과에는 `assertions`, `i2cAssertions`, `outputSequences`를 각각 포함합니다. 출력 순서 결과는 `{pin,signal,afterMs,passed,steps}`이며 각 동작에 계약 값과 `passed`, `matchedAtMs`, `completedAtMs`, `observed`를 포함합니다. 실행 오류가 난 시나리오의 검사는 전부 실패합니다. 컴파일 오류는 0점, 모든 검사 목록이 빈 미리보기는 점수와 통과 여부가 `null`입니다.

0.5.0은 요청 ID와 안전한 오류 코드를 제공합니다. 혼잡은 429와 재시도 대기, 시스템 장애는 503, 시간 제한은 504로 구분하며 학생 코드의 컴파일·런타임 실패는 HTTP 200의 채점 결과로 유지합니다. 로그에는 요청 ID·코드·상태·소요 시간·종료 코드만 기록하고 소스·토큰·비공개 입력·원시 예외를 기록하지 않습니다. WordPress가 운영 장애를 오답 점수로 저장하지 않도록 typed 오류를 전달합니다. 브라우저는 대기 안내 후 사용자가 다시 실행하며 자동 재요청하지 않습니다.

출력 trace는 `{atMs,pin,signal,value,kind:"write"}` 형태이며 같은 핀에 같은 신호·값을 반복해서 쓰면 상태 변화만 기록합니다. `i2cTrace`는 `{atMs,address,operation,data,repeated,kind:"i2c"}`입니다. 두 최상위 trace에는 `traceScenarioId`가 가리키는 첫 공개 시나리오만 포함합니다. 비공개 시나리오의 상세 검사 결과는 WordPress 측에서 학생에게 반환하기 전에 제거해야 합니다. `projectHash`는 전달받은 프로젝트의 UTF-8 파일명 바이트 순서로 정렬한 파일 맵을 포함한 `{name,files}` JSON의 SHA-256입니다. PHP는 프로젝트를 정규화한 후 전달하고 이 해시를 대조합니다.

## 검증

```sh
npm test
```

실제 PXT 컴파일러·공식 시뮬레이터를 사용하는 테스트가 포함되어 있습니다. 임계값 전환, PWM, 디지털 입력, 서보 각도·펄스·모드 변경, I²C 버퍼·숫자 포맷·레지스터 순환·반복 시작·시간 입력·읽기 전용 보호·검사 순서·중복 방지, 잘못된 출력·쓰기 없는 0, 시나리오 상태 초기화, 무한 루프 종료, 컴파일 오류와 HTTP 인증을 검사합니다.

Docker가 없는 개발 PC에서만 `NODE_ENV=test`, `MICROBIT_RUNNER_MODE=process`로 loopback 테스트를 할 수 있습니다. 이 모드는 `test-process`로 보고되며 운영 채점에 사용하면 안 됩니다. 일반 `npm test`의 실제 HTTP 통합 검사는 이 명시적 테스트 모드를 사용합니다. 테스트 통과는 실제 보드 업로드나 운영 Docker 격리 검증을 의미하지 않습니다.

## 외부 구성 요소

PXT와 micro:bit 대상은 Microsoft의 MIT 라이선스 코드입니다. 직접 수정하지 않고 npm의 고정 버전과 lockfile을 사용합니다. 배포 서버에서는 `npm ci`로 의존성 고지를 함께 설치해야 합니다. MakeCode 편집기 임베드와 장치 업로드는 플러그인 브라우저 자산의 별도 고지를 따릅니다.

- [Microsoft PXT](https://github.com/microsoft/pxt)
- [Microsoft PXT micro:bit](https://github.com/microsoft/pxt-microbit)
- [핀 시뮬레이터 구현](https://github.com/microsoft/pxt-microbit/blob/master/sim/state/edgeconnector.ts)
- [MakeCode 서보 각도](https://makecode.microbit.org/reference/pins/servo-write-pin)
- [MakeCode 서보 펄스](https://makecode.microbit.org/reference/pins/servo-set-pulse)
- [MakeCode I²C Buffer API 및 표준 시뮬레이터 제한](https://makecode.microbit.org/reference/pins/i2c-read-buffer)
