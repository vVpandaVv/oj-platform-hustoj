# OJ Code Arena (코드 아레나)

OJ Platform 애드온으로 동작하는 **실시간 팀 블록 점령 코딩 게임**입니다.
팀원들이 한 판을 나눠 쓰며 블록을 차지하고, 블록에 적힌 코드를 정확히 입력해서 판을 모두 풉니다.
중간에 보스 문제(출력 예측·빈칸 채우기)와 아이템, 팀끼리의 공격이 나옵니다.

- 버전: 1.3.3 · WordPress 6.6+ · PHP 8.1+ · OJ Platform 2.0.0-dev.30+ (Addon API v1) · (선택) Node.js 20+
- KAIT-PLAY의 게임 방식(규칙)을 참고했지만, 코드·화면·문제는 이 플러그인을 위해 새로 작성했습니다.

## 설치 (초보자용 — 이것만 하면 됩니다)

1. `oj-code-arena-1.3.3.zip` 을 **플러그인 → 새로 추가 → 업로드** → 활성화 (OJ Platform 이 먼저 켜져 있어야 함)
2. 끝! 활성화하면 자동으로:
   - **내장 게임 엔진**으로 동작 (따로 설치할 서버 없음)
   - 기본 문제은행(일반 193 · 보스 39) 넣기
   - 권한 연결: 로그인하는 모든 역할 → 참가(`oj_code_arena_play`), 반·그룹 관리 역할과 관리자 → 수업 운영(`oj_code_arena_teach`)
     (운영자가 이미 권한을 정해 두었다면 건드리지 않아요)
   - 자유 대전 허용
3. 교사: OJ 콘솔 **[코드 아레나]** → 반 선택 → 만들기 → 학생이 들어오면 **[경기 시작]**
4. 학생: 메뉴 **[코드 아레나]** → 수업 게임 **입장** → 팀 고르기

학생의 로비·대기실·경기 화면은 에듀게임과 같은 밝은 바탕, 시스템 서체, 파란 주요 버튼과 최대 1120px 너비를 사용합니다. 화면 자산은 아레나에 포함되므로 에듀게임이 없어도 동작합니다. 경기 규칙과 REST/WebSocket 설정은 변경하지 않습니다.

## 게임 엔진 두 가지

| | 내장 (기본) | Node 게임 서버 (선택) |
|---|---|---|
| 설치 | 없음 | Node.js 서버 + (https 사이트면) nginx 프록시 |
| 통신 | 같은 사이트로 HTTPS 폴링 (0.35~0.8초 간격, 명령은 즉시 전송) | WebSocket 실시간 |
| 반응 | 0.3~0.8초 | 즉시 |
| 알맞은 규모 | 한 반(30명 안팎) | 여러 반 동시 |
| 판정 | WordPress 안 PHP 엔진 (`src/Engine/`) | Node 엔진 (`game-server/src/`) |

OJ 콘솔 **교사 메뉴 › 코드 아레나 › 설정**(`/oj-console/addons/oj-code-arena/settings`, 사이트 관리자 또는 '코드 아레나 설정' 권한을 받은 교사)에서 언제든 바꿀 수 있습니다. 두 엔진은 같은 규칙을 따르고, 같은 시험 시나리오로 확인합니다.

**내장 엔진의 동작**: 학생 브라우저가 `relay.php`(WordPress 핵심만 불러오는 가벼운 입구, 요청당 수 ms)로 입장권·명령을 보내고
새 이벤트를 받아 갑니다. 진행 상태는 DB 표(`oj_code_arena_live`·`_events`·`_presence`)에 있고, 요청 하나를 처리할 때 MySQL 잠금(GET_LOCK)으로
순서를 지킵니다. 시간 처리(점유 시간, 보스, 봇)는 들어오는 요청이 0.2초마다 돌립니다. 호스팅이 플러그인 폴더의 PHP 직접 실행을 막으면
자동으로 `POST /wp-json/oj-code-arena/v1/relay` 로 바꿔 씁니다.

## 구성

```
oj-code-arena/            ← WordPress 플러그인
├─ oj-code-arena.php      시작 파일 · 활성화: 표 만들기, 기본 문제, 권한, 기본값
├─ relay.php              내장 엔진 폴링 입구 (SHORTINIT)
├─ src/Engine/            내장 엔진: Arena(수업/방) · Team_Match(한 팀의 판) · Rules · Catalog · Relay · Store
├─ src/Addon/             Addon API 등록, 반·그룹 명단(Context Gateway), 페이지
├─ src/Rest/              REST (oj-code-arena/v1)
├─ src/Database/          세션 · 문제은행 · 경기 기록 표
├─ src/Support/           설정, 서명 입장권, 엔진 호출(Game_Server), 기본값, 서버 .env 생성
├─ src/Admin/             설정 저장·.env 다운로드 처리, 첫 안내, 플러그인 목록 [설정] 링크 (wp-admin 메뉴 없음)
├─ resources/             학생 게임 · 교사 콘솔 · 전광판 · 문제은행 · 기록 화면 (JS/CSS)
├─ shared/                규칙 JS (이동 · 채점 · 문제은행 형식)
└─ data/default-bank.*    기본 문제은행

oj-code-arena-server/     ← (선택) Node 게임 서버
├─ game-server/src/       index · hub · arena · match · bot · items · setup(.env 만들기)
├─ game-server/deploy/    nginx · systemd 예시
├─ shared/ · data/
```

브라우저는 화면과 키 입력만 맡고, 판정(이동·점유·채점·보스·아이템)은 엔진이 합니다.
학생이 들어오면 WordPress 가 반 구성원인지 확인하고 5분짜리 **HMAC 서명 입장권**을 줍니다(자동 갱신). 명단에 없는 학생은 들어갈 수 없습니다.

## (선택) Node 게임 서버 설치

1. 플러그인을 업로드한 뒤 OJ 콘솔 **교사 메뉴 › 코드 아레나 › 설정**에서 내장 엔진을 유지하고, 고급 설정의 주소를 `wss://사이트/arena`, 내부 주소를 `http://127.0.0.1:3210/arena`로 저장합니다.
2. **서버 원클릭 설치** 명령을 WordPress와 같은 Ubuntu/Debian 서버의 SSH에서 실행합니다. Node.js 24 확인 및 설치, `/opt/oj-realtime-hub` 배치, 아레나 모듈 등록, systemd·nginx 적용과 인증 확인을 수행합니다. 티칭툴은 필요하지 않습니다. 웹 버튼이 서버 관리자 권한 명령을 직접 실행하지는 않습니다.
3. 완료되면 엔진을 **공통 실시간 서버(WebSocket)**로 바꾸고 **상태 확인**을 누릅니다. 브라우저에서 실제 수업 입장도 확인합니다.

기존 nginx와 systemd가 필요합니다. 기본 설정 파일은 `/etc/nginx/sites-enabled/default`이고, 다른 파일은 명령 맨 끝에 해당 절대경로를 인자로 붙입니다. 사이트에 해당하는 `server` 블록이 모호하거나 기존 `/arena` 인라인 설정과 충돌하면 변경을 중단합니다. 구성 변경 전 백업하고 `nginx -t` 또는 새 서버의 상태 확인이 실패하면 이전 설치로 복구합니다. 설치 안내서의 서버 준비 18-1단계에 파일·명령·제거 방법을 제공합니다.

플러그인 업데이트나 주소·비밀키 변경 후에는 새 명령을 다시 실행합니다. `/opt/.oj-realtime-backup.*`에 이전 설치를 보존하며, 다른 모듈·인증키·미전송 결과·추가 환경 설정을 유지합니다. **공통 서버 재시작은 모든 실시간 연결에 영향을 주고 진행 중 경기는 종료되므로 수업이 없을 때만 실행합니다.** Node 모드 장애 중 내장 엔진으로 바꾸어 같은 경기를 이어갈 수는 없습니다.

별도 `oj-code-arena-server-1.3.3.zip`에도 같은 공통 런타임과 아레나 모듈이 들어 있습니다. WordPress 업로드용 ZIP은 아닙니다. `game-server/src/index.js`와 `.env` 방식은 기존 독립 서버 호환용이며 새 설치의 기본 경로가 아닙니다. 기존 3200 서버는 내부 주소를 3210으로 변경하고 새 원클릭 명령을 실행하면 데이터 이전 후 구 서비스를 비활성화합니다.

### 티칭툴과 함께 운영

아레나만, 티칭툴만, 두 플러그인 동시 설치가 모두 가능합니다. **플러그인 간 선행 설치·활성화 조건은 없습니다.** 각 ZIP에 `common-server/`가 있으므로 다른 플러그인의 파일을 사용하지 않습니다. 기존 공통 기반인 OJ Platform 요구사항은 유지합니다.

첫 설치는 `oj-realtime-hub` 서비스와 3210 포트를 준비하고, 다음 설치는 자기 모듈만 추가합니다. `/arena`와 `/classroom-live`는 한 프로세스에 연결하되 `플러그인 → 채널 → 방`과 비밀키를 분리합니다. 여러 반은 같은 서버를 사용하므로 반마다 설치하지 않습니다. 한 플러그인을 삭제해도 공통 서비스를 자동으로 제거하지 않습니다.

- WordPress로 보내지 못한 결과는 `/opt/oj-realtime-hub/modules/oj-code-arena/game-server/data/unsent-results.jsonl`에 남습니다.
- 상태 확인은 HTTP 통신과 앱 등록·서명 인증을 검사합니다. 브라우저 WebSocket 경로는 실제 입장으로 확인합니다.

## 교육게임(Edu Games)과의 관계

코드는 합치지 않고 **느슨하게 연결**합니다. 교육게임이 켜져 있으면 교육게임 허브에 "코드 아레나" 카드가 생기고 코드 아레나로 이어집니다
(`src/Integration/`, 교육게임의 `edu_games_registry_ready` 훅 사용 — 교육게임 코드는 바꾸지 않음). 기록·순위는 각자 따로입니다.

## 화면

| 화면 | 위치 | 내용 |
|---|---|---|
| 학생 게임 | `/oj-addons/oj-code-arena/play` | 수업 게임 입장, 자유 대전 로비, 대기실, 게임, 내 기록 |
| 수업 게임 | `/oj-console/addons/oj-code-arena` | 만들기(반·그룹 선택), 설정, 팀 배정·봇, 시작·멈춤·끝내기, 공격 ON/OFF, 내보내기 |
| 전광판 | `/oj-console/addons/oj-code-arena/board?session=ID` | 프로젝터용 1920×1080 화면: 팀별 미니 판, 순위, 소식, 공격 알림 |
| 문제은행 | `/oj-console/addons/oj-code-arena/bank` | 검색·추가·고치기·켜기/끄기·지우기, 채점 시험, 붙여 넣어 추가, 내려받기 |
| 기록 | `/oj-console/addons/oj-code-arena/records` | 경기별 팀 순위·학생별 블록/보스 수, CSV |

## 게임 규칙 요약

- 판은 12열, 블록 24~144개 (자동: 1인당 24개, 최대 96). 같은 수업의 모든 팀은 **같은 판**을 받습니다.
- 방향키·Tab 이동, Ctrl+방향키 빠른 이동, Home/End, Ctrl+Home/End.
- Enter 로 블록 차지 → 코드 입력 → Enter 제출. 따옴표 밖 공백은 무시, `'` 와 `"` 는 같게 봅니다. 틀리면 블록을 놓습니다. Esc 로 놓기.
- **보스**: 일정 간격으로 모든 팀에 같은 보스 문제가 나옵니다. 보스 왼쪽 칸에서 Delete, 오른쪽 칸에서 Backspace 로 도전, 기회는 한 번.
  네 번째 보스마다(사람 3명 이상) **모여라 보스**: 6초 안에 팀원 모두가 보스 바로 옆에 모이면 성공.
- **아이템** (덱 5칸, Ctrl+Shift+1~5): 폭탄(주변 8칸), 가로 쓸기, 세로 쓸기, 자동 완성(Tab), 짧게 하기.
  벌칙 `정전`(팀 5초 정지)이 12% 확률로 나옵니다(설정으로 끌 수 있음).
- **공격**(교사가 켰을 때): 얼음 · 안개 · 되돌리기 · 흩어지기 · 거울. 바로 위 순위 팀(1등이면 2등)을 노립니다.
  2.5초 안에 방패(Ctrl+Shift+9)로 막을 수 있습니다.
- 순위: 판을 먼저 다 푼 순서 → 나머지는 진행률·푼 블록 수.
- 부정행위 방지: 입력 칸 붙여넣기·끌어놓기·복사 금지, 한글 입력 제거, 보스 정답은 브라우저로 보내지 않음, 메시지 속도 제한.

## 문제은행 형식

```
;; 메모
@일반 출력, 변수
print('hello')
x = 3

@보스 합계 구하기 | 반복문
s = 0
for i in range(4):
    s += i
print(s)
<- (선택) 학생에게 보여 줄 입력값
-> 6 || 6.0
```

`___` 가 있으면 빈칸 문제(정답은 코드), 없으면 출력 예측(모든 공백 무시)입니다. 코드·정답에 한글은 쓸 수 없습니다.

## 개발

```bash
cd game-server && npm install && npm test          # Node 엔진 시험 (규칙 · 경기 · 실제 서버 왕복)
php tests/php/engine.php                           # 내장 엔진 시험 (같은 시나리오 + relay 폴링 흐름)
php tests/php/run.php                              # 검사기 · PHP↔Node 입장권/서명 · .env 생성
php tests/php/manifest.php ../oj-integration-rebuild/oj-platform   # 실제 Addon API 로 매니페스트 검사
node tools/build-bank.js                           # data/default-bank.txt → .json
node tools/dev-harness.js                          # WordPress 없이 화면 시험 — 내장 엔진 (http://localhost:3300)
HARNESS_ENGINE=node node tools/dev-harness.js      # 같은 화면을 Node 엔진으로
```

## 알려진 한계 (1.0.0-rc.2)

- 내장 엔진은 폴링이라 반응이 0.3~0.8초 늦고, 학생 수만큼 사이트에 요청이 늘어납니다(30명 기준 초당 수십 건, 대부분 가벼운 `relay.php`). 공유 호스팅에서 여러 반이 동시에 하면 Node 엔진을 권합니다.
- 내장 엔진의 MySQL 쿼리(`Engine\Wpdb_Store`)는 로컬에 MySQL 이 없어 실제 DB 로는 아직 실행해 보지 못했습니다. 같은 로직은 파일 저장소(`File_Store`)로 시험했습니다.
- Node 엔진에서 진행 중인 경기는 서버 메모리에만 있어, 서버를 다시 시작하면 진행 중이던 경기가 사라집니다.
- OJ Platform Addon API 에는 OJ 문제 목록을 읽는 통로가 없어서 문제는 이 플러그인의 문제은행을 씁니다. OJ 와는 반·그룹 명단, 권한, 기록으로 연동됩니다.
- 실제 WordPress + OJ Platform 사이트에서는 아직 돌려 보지 않았습니다.
