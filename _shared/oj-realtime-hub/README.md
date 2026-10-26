# OJ 공통 실시간 서버

Teaching Tools와 Code Arena가 각자 설치할 수 있는 공통 Node 런타임이다. 별도 WordPress 플러그인이 아니며 두 플러그인 사이의 선행 설치·활성화 조건을 만들지 않는다. 기존 공통 기반인 OJ Platform 요구사항은 그대로다.

## 설치 계약

- 두 설치 ZIP 모두 동일한 런타임을 `common-server/`에 포함한다. 다른 플러그인의 폴더를 참조하지 않는다.
- 플러그인별 `realtime-addon.json`과 `ojhub1.` 설정 코드로 자기 모듈을 등록한다.
- 첫 설치는 `/opt/oj-realtime-hub`와 `oj-realtime-hub.service`, 내부 `127.0.0.1:3210`을 준비한다.
- 후속 설치는 다른 모듈·비밀키·데이터를 보존하고 자기 모듈만 갱신한다. 설치된 공통 런타임이 더 새로우면 내리지 않는다.
- 기존 설치 명령에 `--update`를 붙이면 설정 코드 입력 없이 저장된 등록 정보를 사용한다. 사용자 지정 포트·환경값과 다른 모듈·미전송 데이터를 보존하며 nginx와 systemd 유닛·자동 시작 설정은 변경하지 않는다. 최초 등록이나 주소·키 변경은 일반 설치 명령과 새 설정 코드를 사용한다. 업데이트 모드도 스테이징·백업·인증 확인 및 실패 시 복구 절차를 거치며 공통 서비스를 재시작한다.
- 티칭툴 `/classroom-live`, 아레나 `/arena`는 같은 프로세스로 연결되지만 인증과 메시지 처리는 분리된다. 서로의 주소·비밀키를 복사하지 않는다.
- 기존 티칭툴 단독 허브는 설치된 서버 파일만으로 이전한다. 기존 아레나 독립 서비스는 미전송 결과와 추가 환경 설정을 보존하고, 새 서비스의 인증 확인 뒤 비활성화한다.
- 설치·갱신은 전체 공통 서버를 재시작하므로 실시간 수업과 경기가 없는 시간에 한다. 진행 중 경기는 메모리에 있으므로 이어지지 않는다.
- 비활성화·WordPress 플러그인 삭제는 공통 서버를 중지하거나 삭제하지 않는다. 한 모듈 제거 시 다른 모듈을 남기는 절차는 공식 설치 안내서 18단계를 따른다.

## 확장 계약 (프로토콜 1)

`realtime-addon.json`은 `protocol`, `app`, `version`, `secretKey`, `adapter`, `include`, 선택적 `dataPath`를 가진다. `include`는 해당 플러그인 내부 실행 파일만 명시한다. 어댑터는 `createAdapter({ app, rooms, root })`를 내보낸다.

- 필수: `connect(socket)`, `disconnect(connection)`, `message(connection, raw)`, `status()`.
- 선택: `tick()`, `tickMs`, `maxPayload`, `http(request, response, route, status)`, `close()`.
- 인증 성공 시 `connection.ready = true`. 티켓은 서명·만료·사용자·역할·앱·채널을 검증해야 한다.
- `rooms.channel(app.id, channel)`의 Map에 방을 저장한다. 같은 방 이름이어도 `앱 / 채널 / 방`이 다르면 상태를 공유하지 않는다.
- 인증키는 `config.json`의 앱별 항목에 저장한다. 다른 앱의 키로 상태 조회·입장할 수 없다.
- 게임 규칙·권한·점수 판정은 해당 앱 어댑터가 담당한다. 판서 메시지를 게임 명령으로 해석하지 않는다.

`GET /<경로>/healthz`는 최소 생존 정보만 공개한다. `GET /<경로>/status`는 `X-OJ-Realtime-Time`과 `X-OJ-Realtime-Signature`가 필요하다. 서명은 앱 비밀키로 `METHOD + "\n" + route + "\n" + time + "\n" + rawBody`의 HMAC-SHA256(hex), 시간 오차 허용은 300초다. 응답은 공통 버전·앱 버전·인증 여부·채널별 방 수·접속 수를 포함한다. 다른 앱의 정보나 비밀키는 노출하지 않는다.

관리자 상태 확인은 HTTP 경로와 서버 인증을 검사한다. 실제 브라우저의 WebSocket 업그레이드·방 입장은 수업 참여로 별도 확인한다.

## 검증

이 디렉터리에서 `npm ci` 후 `npm test`를 실행한다. 단독 설치, 양방향 설치 순서, 실제 WebSocket 통신, 인증 분리, 이전 서버 전환, 재설치 데이터 보존, nginx 재실행을 검사한다. Ubuntu/systemd/nginx 실배포 테스트와는 구분한다.
