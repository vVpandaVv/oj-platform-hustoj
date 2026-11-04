# 실제 WordPress·MySQL 참여 인수 검사

이 검사는 기존 `test-bootstrap.php`, SQLite 어댑터, 가짜 컨텍스트를 사용하지 않습니다. 네이티브 WordPress를 로드하고 실제 플랫폼 서비스·역할 저장소·REST 디스패처·MySQL/MariaDB를 사용합니다. 전용 로컬 검증 사이트에서만 실행합니다. 운영 사이트와 실제 학생 계정에는 실행하지 않습니다.

공식 릴리스 ZIP을 새 WordPress에 설치하고 플랫폼의 실제 설치 절차를 완료합니다. PHP 8.3 이상과 openssl·mysqli·dom·xmlwriter·zip·fileinfo, InnoDB가 필요합니다. 그룹 기능과 회원가입·Auth 스키마를 준비합니다. `WP_ENVIRONMENT_TYPE`은 `local`로 지정하고 사이트 주소는 loopback을 사용합니다. Focus Tracker는 아직 활성화하지 않습니다.

별도 비공개 출력 폴더를 가리키는 다음 `config.json`을 준비하고 `FOCUS_TRACKER_REAL_WP_CONFIG` 환경변수로 절대 경로를 지정합니다. 파일에는 전용 검증 계정 암호가 있으므로 공개 배포나 커밋에 포함하지 않습니다.

```json
{
  "wpPath": "absolute/path/to/disposable/wordpress",
  "baseUrl": "http://127.0.0.1:33918",
  "admin": {"username": "disposable-admin", "password": "disposable-password"},
  "outputDir": "absolute/path/to/private/artifacts"
}
```

1. `php participation-acceptance.php prepare`를 실행합니다. 실제 플랫폼 GroupService로 그룹을 만들고, Focus 활성화 전 기존 학생과 개인 거부 학생을 생성합니다.
2. WordPress의 실제 플러그인 활성화 절차로 Focus Tracker를 활성화합니다. 임의 부트스트랩이나 강제 게이트를 추가하지 않습니다.
3. `setup-problem.php`로 실제 공개 문제를 준비합니다. 동일 출력 폴더의 `problem-fixture.json`에 문제 번호를 기록합니다.
4. 새 프로세스에서 `php participation-acceptance.php verify`를 실행합니다. 실제 init 자동 부여, 기존 학생·개인 거부·신규 플랫폼 등록, 같은 WP_User의 권한 캐시 갱신, REST 활동·자동 연결·실제 시간 경계·문항 취소를 검사합니다. 학생 자동 권한과 별개로 시험 교사의 관리·결과 열람·설정 권한을 실제 플랫폼 역할 배정 API로 설정합니다. 시험용 신규 가입에만 쓰는 등록 정책은 복원합니다. 가입 동의 화면이나 이메일 전달을 검증했다고 해석하지 않습니다.
5. 플러그인 수명주기를 검사하려면 `lifecycle-deactivate`, `lifecycle-activate`, `lifecycle-restored`, `lifecycle-denied`, `lifecycle-revoked`, `lifecycle-final`을 각각 **새 PHP 프로세스**로 순서대로 실행합니다. 실제 WordPress core의 비활성화·재활성화, 다음 init의 자체 부여 복원, 이후 관리자의 명시적 false와 권한 회수 보존을 확인합니다. 마지막 단계 전에 브라우저 검사를 시작하지 않습니다. 검사에서 의도적으로 변경한 역할·초기화 상태는 브라우저 검사 전에 원래 검증 설정으로 복원합니다.
6. `browser-acceptance.cjs`는 생성된 `participation-fixture.json`의 전용 계정으로 로그인해 실제 쿠키·페이지 설정의 nonce·학생 화면과 자동 시작·종료를 별도로 검사합니다. PHP 내부 REST 요청 검사는 HTTP 쿠키 인증을 대신하지 않습니다.

브라우저 실행은 다음 환경변수를 사용합니다. `FOCUS_REAL_CONFIG`는 사이트 설치 설정이 아니라 위 단계에서 생성한 전용 계정 fixture 파일입니다. PowerShell에서 실행하는 예입니다.

```powershell
$env:FOCUS_REAL_CONFIG = 'absolute/path/to/private/artifacts/participation-fixture.json'
$env:FOCUS_REAL_ARTIFACTS = 'absolute/path/to/private/artifacts/browser-1.0.0'
$env:FOCUS_REAL_VERSION = '1.0.0'
node focus-tracker/tests/real-wordpress/browser-acceptance.cjs
```

검증 도구의 후처리만 실패한 경우 `FOCUS_REAL_CONTINUE_REPORT`로 원래 보고서를 지정하면 새 활동·문항을 만들지 않고 실제 재로그인·보안·기존 저장 결과 조회만 이어 검사합니다. 원래 실패 보고서는 보존하며 이 후속 조회를 전체 자동 타이머 재실행으로 표시하지 않습니다. 새 전체 실행 전에는 이 변수를 제거합니다.

`provision.php`는 고정 loopback 포트 33917/33918과 `focus_acceptance_` 전용 DB 이름을 요구하는 설치 도우미입니다. `install` → `platform` → `setup`으로 네이티브 WordPress와 실제 플랫폼을 준비하고, `prepare` 후 `focus`로 실제 활성화합니다. 이 도우미의 설정에는 `home`, `dbHost`, `dbName`, `dbUser`, `dbPassword`, `admin.login`도 필요합니다. `install-zip <공식 1.0.0 ZIP>`은 대상 경로를 확인한 뒤 WordPress `Plugin_Upgrader`로 기존 설치를 교체합니다. 설치 ZIP 생성에는 사용하지 않습니다.

브라우저가 닫힌 뒤 `upgrade-acceptance.php <config.json> before`로 RC3의 8개 테이블 행 수·해시와 설정을 저장하고, 정식 ZIP 설치 후 새 프로세스의 `after`로 1.0.0/DB 버전과 보존을 대조합니다. 비교 사이에는 cron·수집·통계 조회를 중지합니다. 0행이었던 테이블의 동일성은 실제 이력 보존의 증거로 확대하지 않습니다. 최종 브라우저 `browser-result.json`을 출력 루트에 복사한 뒤 `native-evidence.php <config.json> <공식 ZIP>`으로 실제 DB 시간 경계·InnoDB와 ZIP/설치본 전체 파일 해시를 읽기 전용으로 검증합니다.

WP-CLI가 이미 WordPress를 로드한 경우 `wp eval-file participation-acceptance.php prepare` / `verify`도 같은 코드를 사용합니다. CLI의 새 WordPress 부팅 단계와 브라우저 실행 결과를 각각 보관합니다. 이 파일을 기존 모의 테스트 부트스트랩으로 실행하지 않습니다.

실제 문항 발급은 기본 5분 간격·하루 3회 상한을 소비합니다. 브라우저와 PHP 검사가 같은 학생에게 연달아 문항을 요구하면 정상 대기·상한에 걸릴 수 있습니다. 신규 등록 학생으로 브라우저 문항을 검사하거나 실제 대기 시간을 지키며 반복 인수 검사는 새 계정 세트를 준비합니다. 테스트를 위해 제품의 간격·상한을 낮추지 않습니다.

Windows의 단일 PHP 내장 서버에서 실제 플랫폼 부팅·요청 대기가 길어지면 두 학생의 5초 승인 확인이 시간 초과할 수 있습니다. 이때 제품은 승인 기한이 끝나면 안전하게 수집을 멈춥니다. 최종 정식 ZIP 검사는 `node focus-tracker/tests/real-wordpress/worker-server.cjs`의 독립 PHP 작업자 4개·OPcache·loopback 역방향 프록시를 사용했습니다. 이 도구는 이 작업 공간의 PHP 8.3 런타임 경로와 고정 포트를 사용하는 로컬 검증용이며 공개 서비스용 서버가 아닙니다. 이전 33918 단일 서버를 종료한 뒤 실행합니다. 모든 요청·응답 본문, 쿠키와 nonce는 실제 WordPress가 처리하며 프록시는 응답을 가짜로 대체하지 않습니다. `http-observations.json`에는 본문·쿠키·nonce 없이 경로·상태·처리 시간만 남깁니다. 정상 활동 구간의 응답과 페이지 이동 중 취소·의도적 권한 거부를 구분합니다.

이 검사는 전용 계정·그룹·문제와 시험 기록을 남깁니다. 브라우저 검사가 끝날 때까지 유지하고 검증 후 전용 사이트·DB를 폐기합니다. 다른 사이트나 기존 데이터를 삭제하는 정리 기능은 제공하지 않습니다. 설치 ZIP은 릴리스 킷으로만 생성하며 출시된 ZIP을 덮어쓰지 않습니다.

## 1.1 페이지 분류와 최종 ZIP 교체 검사

`page-categories-acceptance.php`와 `page-categories-package.php`는 2026-10-28에 만든 별도 인스턴스만 허용합니다. 주소 `http://127.0.0.1:33918`, DB 연결 `127.0.0.1:33917`, WordPress/application DB `focus_acceptance_categories_20261028`, 작업 폴더 `_notes/tmp/focus-tracker-real-wp/wordpress`가 고정되어 있습니다. 임의 사이트에서 실행할 수 있는 일반 마이그레이션 도구가 아닙니다. 이번 출발 환경은 실제 WordPress 7.1 / PHP 8.3.33 / MariaDB 11.4.10 / OJ Platform 2.5.4이며, 공식 Focus Tracker 1.0.1에서 1.1.0으로 올라가는 경계를 검사합니다. 앞 절의 RC3→1.0.0 기록과 구분합니다.

비공개 `config.json`에는 앞 절의 키에 `home`, `dbHost`, `dbName`, `dbUser`, `dbPassword`, `admin.login`도 필요합니다. 설정·fixture 파일에는 암호가 있으므로 로그에 출력하거나 검증 증거 폴더에 복사하지 않습니다. PHP는 mysqli·mbstring·openssl·curl·zip·intl·fileinfo·sodium이 켜진 실제 8.3 런타임을 사용합니다. Windows 예시는 다음과 같습니다.

```powershell
$php83 = 'D:/프로젝트/plugins/_notes/tmp/oj-ml-1.0-integration/php83/php.exe'
$runtimeArgs = @('-n', '-d', 'extension_dir=D:/프로젝트/plugins/_notes/tmp/oj-ml-1.0-integration/php83/ext',
    '-d', 'extension=mysqli', '-d', 'extension=mbstring', '-d', 'extension=openssl', '-d', 'extension=curl',
    '-d', 'extension=zip', '-d', 'extension=intl', '-d', 'extension=fileinfo', '-d', 'extension=sodium')
$localConfig = 'D:/프로젝트/plugins/_notes/tmp/focus-tracker-real-wp/config.json'
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/page-categories-acceptance.php baseline $localConfig
```

1. 전용 DB·WordPress를 새로 준비하고 공식 Platform 2.5.4 ZIP을 활성화한 뒤 실제 SetupCoordinator를 완료합니다. 공식 Focus 1.0.1, edu-games 1.6.7, learning-materials 1.1.2 ZIP을 설치합니다. Focus 1.1 소스로 먼저 교체하면 업그레이드 출발점 검증이 되지 않습니다.
2. 위 `baseline`을 실행합니다. 첫 호출이 플러그인을 활성화하고 `needsFreshRequest`를 반환하면 같은 명령을 **새 프로세스**에서 한 번 더 실행합니다. 실제 GroupService와 UserProfileRepository로 비관리자 그룹관리 교사·기존 학생·개인삭제 전용 학생, 그룹과 공개 자료, 완료된 과거 활동·세션·이벤트·점수를 만듭니다. 기존 공통 컬럼의 SHA256과 운영자 설정을 비공개 fixture에 기록합니다. baseline은 이미 기록한 fixture를 덮어쓰지 않습니다.
3. 검증용 설치본만 현재 1.1 소스로 교체하고 `page-categories-acceptance.php verify $localConfig`를 새 프로세스에서 실행합니다. 실제 WordPress dbDelta의 새 세션 네 필드와 점수 두 필드 기본값, 기존 행·설정 보존, 신규 학생의 상속 권한, 서명된 자료·게임·ML의 null 문제 번호 수집, 주월 통계, 편집 중립 평가, 실제 REST 활동 종료와 위조 차단을 검사합니다. 개인정보 삭제는 브라우저 학생과 다른 전용 학생만 대상으로 합니다. 자료 TXT 파일은 이 폐기용 사이트의 보호 저장소에 직접 넣는 fixture이며 제품의 HTTP 업로드 검증을 대신하지 않습니다. verify는 고정 신규 계정·source UID를 쓰는 단회 검사라 동일 fixture에서 반복하지 않습니다.
4. `setup-problem.php $localConfig`를 실행해 같은 전용 MariaDB의 별도 `jol` 스키마와 실제 JudgeConnectionCommandService의 `testCandidate`→`applyTestedCandidate` 설정을 준비합니다. 자료·게임 페이지라도 일반 교사의 플랫폼 콘솔 메뉴가 실제 Judge read connection을 사용하므로 필요합니다. 관리자 우회로 메뉴를 검증하지 않습니다. 이 절차는 로컬 합성 문제를 만들며 채점 데몬이나 실제 제출 실행을 검증하지 않습니다.
5. 생성된 `page-categories-fixture.json`으로 실제 Chrome 검사를 실행합니다. fixture의 교사는 administrator가 아니며 실제 그룹을 관리합니다. 기존·신규 학생의 PARTICIPATE는 직접 부여하지 않고 subscriber 기본 권한을 상속합니다. self 통계·게임·자료 열람 권한만 fixture 역할에 별도로 부여합니다. PHP 내부 REST 검사와 별도로 실제 로그인 쿠키·페이지 nonce, 그룹/시간 UI, 자동 시작·종료, 자료·게임·ML iframe과 주월 범주 UI를 확인합니다.

```powershell
$env:FOCUS_REAL_CATEGORY_CONFIG = 'D:/프로젝트/plugins/_notes/tmp/focus-tracker-real-wp/artifacts/page-categories-fixture.json'
$env:FOCUS_REAL_ARTIFACTS = 'D:/프로젝트/plugins/_notes/tmp/focus-tracker-real-wp/artifacts/category-source-browser'
$env:FOCUS_REAL_VERSION = '1.1.0'
node focus-tracker/tests/real-wordpress/page-categories-browser.cjs
```

브라우저와 다른 클라이언트를 종료한 뒤 최종 패키지 보존 검사를 진행합니다. WordPress 설정의 `DISABLE_WP_CRON=true`를 유지하고, prezip/postzip 사이에는 수집·통계 조회·활동 수정·개인삭제를 실행하지 않습니다. `inspect`는 비교 기준을 만들지 않는 읽기 전용 사전 점검입니다. `prezip`은 기존 기준 파일이 있으면 덮어쓰지 않습니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/page-categories-package.php inspect $localConfig
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/page-categories-package.php prezip $localConfig
$finalZip = 'D:/프로젝트/plugins/_releases/focus-tracker/1.1.0/focus-tracker-1.1.0.zip'
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/provision.php $localConfig install-zip $finalZip 1.1.0
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/page-categories-package.php postzip $localConfig $finalZip
```

ZIP은 위 명령으로 생성하지 않습니다. 릴리스 킷이 만든 불변 1.1.0 ZIP만 WordPress Plugin_Upgrader로 설치합니다. `prezip`/`postzip`은 WordPress/application DB의 **모든 base table**을 일관된 읽기 트랜잭션에서 행 수·전체 행 multiset SHA256·컬럼 정의 SHA256으로 비교합니다. 현재 사이트에서는 79개 테이블입니다. 별도 Judge `jol` DB는 이 비교 범위에 포함하지 않습니다. 원시 행·코드·답안·설정값·암호는 내보내지 않습니다. cron, transient, 스키마 상태·재시도·정리 진행 상태의 명시된 런타임 옵션만 제외하고, 실제 설정·역할·개인삭제 cutoff·학습 기록은 유지합니다. 제외한 옵션은 이름에 토큰이 들어갈 수 있는 transient 원문 대신 종류별 개수만 기록합니다.

`postzip`은 현재 데이터 전체와 최초 1.0.1 공통 컬럼 hash를 다시 비교하고, 최종 ZIP의 모든 파일과 설치 폴더의 바이트를 SHA256으로 대조합니다. 누락·추가 파일도 실패합니다. 이어 실제 WordPress를 새로 부팅해 DB/플러그인 1.1.0, 새 필드·InnoDB·핵심 unique index, subscriber 기본 권한과 두 학생의 개인 grant 없는 상속을 확인하고 부팅 후 데이터도 다시 대조합니다. 이 단계 뒤에는 `FOCUS_REAL_ARTIFACTS`를 새 폴더로 바꿔 같은 실제 Chrome 검사를 최종 ZIP 설치본에서 다시 실행합니다. 새 실행 전에는 재전송·활동·통계가 사전 스냅샷을 바꾸지 않도록 반드시 postzip을 먼저 완료합니다.

보고서는 `page-categories-baseline-report.json`, `page-categories-verify-report.json`, `page-categories-prezip.json`, `page-categories-postzip-report.json`에 저장합니다. 각 보고서의 `assertionCount`를 기록 기준으로 사용합니다. 최초 생성된 baseline/verify 보고서의 수치는 21/67이며, 당시 stdout의 22/68은 보고서 저장 확인을 한 번 더 센 값이었습니다. 이후 도구는 파일 저장 성공을 검증 개수에서 제외해 새 stdout과 보고서 수치가 같습니다. 기존 증거 파일은 수정하지 않습니다. 고정된 검증 계정·리소스·DB 이름 및 시간상 제약이 있는 이 helper를 다른 환경에 그대로 실행했다고 표시하지 않습니다.

## Oracle MySQL 8 동시성·부하 검사

별도 환경 `_notes/tmp/focus-tracker-mysql8`과 DB `focus_acceptance_mysql8_20261028`을 사용합니다. origin/DB 포트는 33918/33917이며 MariaDB 환경과 동시에 구동하지 않습니다. `mysql-concurrency-acceptance.php`와 `mysql-package-acceptance.php`는 이 새 루트·DB 이름을 정확히 확인하고 실제 Oracle MySQL 8.0 Community Server를 요구합니다. 앞 절의 MariaDB helper 경계를 완화해서 재사용하지 않습니다. 이번 실제 서버는 Oracle MySQL 8.0.46이며 WordPress와 PHP 버전은 각각 7.1, 8.3.33입니다. Windows MySQL의 한글 경로 제한 때문에 같은 전용 폴더의 ASCII `Q:` 별칭을 사용하며 실제 `@@datadir`도 보고서에 기록합니다.

별도 서버에 공식 Platform 2.5.4와 Focus 1.1.0, 자료·교육게임 애드온을 설치·활성화하고 실제 플랫폼 설치 및 Judge 후보 검사·적용을 마칩니다. 위 PHP 런타임 인자와 같은 확장을 켜고 새 비공개 설정을 사용합니다.

```powershell
$mysqlConfig = 'D:/프로젝트/plugins/_notes/tmp/focus-tracker-mysql8/config.json'
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php prepare $mysqlConfig 60
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php probe $mysqlConfig
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php run $mysqlConfig 2 12
```

`prepare`는 실제 WordPress 학생 60명·비관리자 그룹 교사·두 private 그룹·공개 TXT 자료를 만듭니다. 처음 애드온 활성화가 필요하면 `needsFreshRequest` 뒤 새 프로세스에서 다시 실행합니다. 학생은 두 그룹 모두에 소속하지만 서명된 A 그룹 자료를 B 그룹으로 옮겨 연결하지 못해야 합니다. PARTICIPATE는 역할 기본 상속만 사용하며 자료·게임·본인 통계 열람 권한만 fixture에 별도로 설정합니다. 교사가 그룹·시간을 설정하는 실제 HTTP 부하 검사에 쓸 비공개 `mysql-concurrency-fixture.json`을 생성합니다.

`probe`는 실제 Events REST 트랜잭션 두 개를 서로 다른 학생으로 실행하고, 기존 unique session UID 사이의 같은 gap에 들어갈 새 source 쌍을 고릅니다. 표준 WordPress `query` 필터는 실제 참여 INSERT 직전에 파일 장벽으로 잠깐 동기화할 뿐 SQL·응답·권한·서버 시각을 대체하지 않습니다. 기존 행을 지워 빈 테이블을 만들지 않습니다. InnoDB 교착 카운터는 Oracle의 `INFORMATION_SCHEMA.INNODB_METRICS.lock_deadlocks`를 사용하고 활성 여부도 확인합니다. 공식 1.1.0 기준에서는 교착이 증가해 실패해야 하며 수정 설치본에서는 같은 절차가 교착 없이 끝나야 합니다. 최종 원시 데이터가 재시도로 복구되더라도 교착 증가를 통과로 취급하지 않습니다.

`replay-probe`는 두 학생의 source에 실제 관측 네 개와 서버 제어 두 개를 REST로 먼저 저장하고, 같은 패킷을 학생당 20회씩 재전송합니다. 장벽은 실제 event INSERT 직전에만 적용합니다. 수정 전에는 이미 존재하는 여섯 tuple을 no-op duplicate-key 쓰기로 보내 실제 교착이 재현됐습니다. 수정 후에는 새 관측이 없으므로 event INSERT 자체가 0회여야 하고, 장벽 미도달은 정상입니다. 기존 관측·리비전과 final/deadline 메타데이터를 구분합니다. 단순 중복을 제거하면서도 마지막 전송·일정 경계·활동 연결 처리는 계속 검증합니다. 의도적으로 유지한 장벽에서 증폭된 lock timeout과 실제 교착 카운터를 분리하며 그 대기 시간을 일반 서비스 지연으로 쓰지 않습니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php replay-probe $mysqlConfig
```

`replay-probe`는 실제 REST로 학생 두 명의 실제 이벤트 네 개와 시작·종료 제어를 먼저 저장하고, 같은 패킷을 20라운드·40요청으로 반복합니다. 기존 이벤트 INSERT 직전의 파일 장벽으로 수정 전 중복 키 쓰기 교착을 재현합니다. 수정 후에는 순수 재전송의 이벤트 INSERT 자체가 0회여야 하며 같은 순번·종류·시각을 정확히 한 번만 유지해야 합니다. 장벽이 앞선 다른 잠금과 함께 대기를 증폭할 수 있어 이 강제 프로브의 timeout·응답 시간은 정상 서비스 부하 지연으로 해석하지 않습니다. 전체 부하는 별도 `run`과 HTTP로 판정합니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php replay-probe $mysqlConfig
```

`check-probe`는 두 명의 별도 새 학생과 전용 그룹에서 실제 검증 문항을 발급하며 main 60학생의 상한을 소비하지 않습니다. 실제 check INSERT 직전에만 동기화해 빈 만료 범위 UPDATE의 교착을 검사합니다. 다른 학생이 그 UPDATE부터 잠긴 경우에는 실제 `data_lock_waits`의 인덱스·잠금 종류만 기록하고 장벽을 풀어 인위적인 장시간 timeout을 피합니다. 문항은 각각 한 개·상한 한 번이어야 하고, 활동을 반드시 닫은 뒤 미응답 취소와 추가 발급 없음도 확인합니다. `checks`는 main 60학생에게 두 탭의 중복 next 요청을 보내 한 문항·상한 한 번·동일 공개 ID를 확인하고, 50명은 공개 선택지 중 하나를 같은 응답 UID로 두 번 제출합니다. 정답 키는 읽거나 내보내지 않으며 오답·중복 응답도 추적 이벤트·점수에 변화가 없어야 합니다. 나머지 미응답은 활동 종료로 취소하고 종료 뒤 요청의 문항·상한 증가가 없는지 SQL로 대조합니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php check-probe $mysqlConfig
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php checks $mysqlConfig
```

문항 검사는 실제 기본 5분 간격·하루 상한을 그대로 소비합니다. 같은 main 학생으로 `checks`를 반복하려면 실제 간격을 기다리고 남은 상한이 있어야 합니다. 시계·상한·발급 상태를 고쳐 반복하지 않습니다. `probe`/`check-probe`의 실패 기준은 실제 교착이나 불필요한 학생 간 직렬화이며 도구의 장벽 대기 시간을 평상시 응답 시간으로 확대하지 않습니다.

`run 2 12`는 고정된 60학생의 두 탭 패킷을 두 라운드, 최대 12개의 독립 PHP 프로세스로 실행합니다. 같은 순번 중복 전송과 추가 이벤트, 교사 전체 점수 새로고침·월간 통계, 일정 수정·조기 종료·개인정보 삭제·보관 정리·계정 삭제를 겹칩니다. PHP 부팅 완료 뒤 장벽을 열며 service timing은 부팅·HTTP 전송 시간을 제외합니다. 60학생이 동시에 열린 실제 Chrome/HTTP 부하를 대신하는 수치는 아닙니다. 423 삭제 보호와 503 재시도 횟수를 숨기지 않고 기록하고, 최종 원시 순번·시각·종류·리비전, 그룹 분리, 삭제 재생성 방지·다른 학생 보존, 보관 집계와 누적 시간 합집합을 SQL로 확인합니다. 같은 환경의 기존 학습 이력은 지우지 않으며 새 반복은 겹치지 않는 시간창과 새 개인정보 삭제 전용 계정을 사용합니다. 전용 계정 두 명만 교체해 교사 명단 크기를 일정하게 유지하고 기존 월간 수치에 새 합집합 증분만 더해 비교합니다.

완료된 native 실행 뒤 웹 검증으로 넘어갈 때는 모든 worker와 HTTP 측정이 끝난 상태에서 다음 fixture 정리를 한 번 실행합니다. 계정 삭제 모사에 사용한 별도 계정은 실제 WordPress 계정을 삭제하지 않았으므로 그룹 명단에 남아 있습니다. `cleanup-fixture`는 원래 60학생과 겹치지 않는 전용 계정 두 명 및 원래 비공개 그룹 두 개의 소유자·slug·문맥을 검증한 뒤 실제 `GroupService`로 해당 네 참여 관계만 해제합니다. 계정·학습 자료·Focus 8개 표·60학생의 WordPress 계정/메타데이터와 완료된 1,047-worker 보고서는 변경하지 않으며, 보존 digest를 별도 `mysql-concurrency-fixture-cleanup.json`에 기록합니다. 정리 후 다시 `run`하면 새 전용 계정을 만들어 명단에 추가하므로 후속 웹 측정 직전에 다시 정리합니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php cleanup-fixture $mysqlConfig
```

일반 HTTP 부하와 native run을 겹치지 않습니다. `mysql-observation.php`의 읽기 전용 `snapshot`/`watch`/`diff`/`deadlock`은 병행할 수 있습니다. SQL 원문·파라미터·레코드 값·`LOCK_DATA`를 내보내지 않고 테이블·인덱스·잠금 종류·집계 수치만 기록합니다. 전역 SQL mode와 WordPress가 실제 연결에서 적용한 session SQL mode를 구분하고 전역/session 격리 수준도 남깁니다. 교착을 숨기기 위해 RR이나 기본 승인 기한·출제 간격·요청 제한을 완화하지 않습니다.

native 검사 완료 후 `node focus-tracker/tests/real-wordpress/mysql8-worker-server.cjs`로 독립 PHP 12개·OPcache와 실제 HTTP 전달 프록시를 실행합니다. 도구의 표준입력에 `stop`을 보내 종료합니다. 별도 PowerShell에서 아래 두 부하는 차례로 실행합니다. 보고서 폴더는 실행마다 새 이름을 사용합니다.

```powershell
$env:FOCUS_MYSQL_LOAD_CONFIG = (Resolve-Path '_notes/tmp/focus-tracker-mysql8/artifacts/mysql-concurrency-fixture.json').Path
$env:FOCUS_MYSQL_LOAD_VERSION = '1.1.1'
$env:FOCUS_MYSQL_LOAD_PHP_WORKERS = '12'
$env:FOCUS_MYSQL_LOAD_STAGES = '10,30,60'
$env:FOCUS_MYSQL_LOAD_ARTIFACTS = Join-Path (Split-Path $env:FOCUS_MYSQL_LOAD_CONFIG) 'browser-source-1.1.1-run1'
node focus-tracker/tests/real-wordpress/mysql-load-browser.cjs
$env:FOCUS_MYSQL_HTTP_STAGES = '60'
$env:FOCUS_MYSQL_HTTP_ARTIFACTS = Join-Path (Split-Path $env:FOCUS_MYSQL_LOAD_CONFIG) 'http-source-1.1.1-run1'
node focus-tracker/tests/real-wordpress/mysql-load-http.cjs
```

Chrome는 실제 두 페이지·쿠키·nonce·visibility/focus를 쓰며 메모리 안전선에서 준비를 중단하면 실제 준비한 학생 수의 일정·전송 검증만 마칩니다. 요청한 10/30/60명 달성과 실제 관측 범위 통과를 별도 필드로 보고하며 미실행 큰 단계는 통과가 아닙니다. HTTP는 학생별 두 인증 채널로 제품의 요청 주기와 승인 기한을 유지하지만 화면 상태는 합성입니다. 두 방식 모두 학생들이 페이지/인증 준비를 마친 뒤 실제 교사가 시작 45초 전 일정을 만들며, startup과 waiting/active/drain 지연을 분리합니다. HTTP는 모든 학생의 week/month 시작값을 실제 API로 읽고 종료 후 증가분까지 비교합니다. 실제 Chrome 검사는 종료 후 전체·분류 합계의 일치 여부를 검사하며, 같은 시작값 증분 검사를 수행했다고 표시하지 않습니다.

후속 메모리 제한 구성은 `FOCUS_MYSQL8_WORKERS=4`로 서버를 시작하고 양쪽 부하에 `FOCUS_MYSQL_LOAD_PHP_WORKERS=4`, `FOCUS_MYSQL_LOAD_MYSQL_BUFFER_MIB=128`, `FOCUS_MYSQL_TEACHER_API_ONLY=1`을 지정합니다. API 교사도 실제 로그인 폼의 nonce·쿠키와 콘솔 REST nonce를 사용합니다. 128MiB buffer pool은 소유한 전용 Oracle의 UUID·포트·datadir를 확인한 별도 로컬 작업으로 설정하며 부하 도구가 운영 DB 설정을 바꾸지 않습니다. HTTP 단계는 `FOCUS_MYSQL_HTTP_STAGES=10`, `30`, `60`을 각각 새 보고서 폴더로 순서대로 실행하고 매번 관측 post와 SQL 감사를 끝낸 뒤 다음 단계로 이동합니다.

실제 Chrome의 선택적 `FOCUS_MYSQL_LOAD_POPUP_TABS=1`은 학생별 첫 페이지에서 진짜 `window.open`으로 두 번째 페이지를 만듭니다. 학생 사이 cookie context는 분리하고 실제 popup/opener·HTTP 200·서명·페이지 수를 검증합니다. renderer 배치는 Chrome이 선택하며 보안 격리나 focus·visibility를 바꾸지 않습니다. 기본 독립 `context.newPage` 결과와 별도 보고하고, 시작 전 메모리 guard로 중단되면 popup 효과까지 검증한 것으로 표시하지 않습니다.

`mysql-wire-smoke.cjs`는 같은 인증 계약과 nonce 쿼리로 두 학생의 실제 이벤트 ACK를 짧게 확인하는 별도 검사입니다. `FOCUS_MYSQL_WIRE_ARTIFACTS`에 새 출력 폴더를 지정합니다. wire smoke는 지속 부하 통과를 대신하지 않습니다. 암호가 있는 공통 fixture와 쿠키 저장소는 보고서에 포함하지 않습니다.

실제 Chrome 부하 도구의 `expectedEvents`에는 `userId`, `sessionUid`, `sessionStartedAt`, `seq`, `occurredAt`, `type`, `problemId`, `pageKey`, `pageCategory`, `pageSubtype`, `contextType`, `contextId`를 넣습니다. ACK 키는 `userId|sessionUid|seq`이며 `stages[].acknowledgedEventKeys`로 전달합니다. 토큰·nonce·암호는 보고서에 넣지 않습니다. 브라우저가 모두 닫히고 전송 대기열을 비운 뒤 다음 명령으로 정확한 native g6 source hash, 원시 이벤트와 그룹 연결, 주간·월간 분류 합계를 대조합니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-concurrency-acceptance.php audit $mysqlConfig 'absolute/path/to/browser-report.json'
```

ACK된 누락과 미ACK/미전달 누락을 분리합니다. 최종 시간표 밖의 정책 폐기와 뒤늦은 시간 단축 전에 이미 저장된 관측도 구분하며, 읽지 못한 beacon 본문의 추가 원시 행을 숨기지 않습니다. 이 audit 없이 브라우저 전송 횟수만으로 DB 무손실을 선언하지 않습니다. 활동 변경 시점, 숨김·포커스의 실제 브라우저 관측 분포, 오류율·대기시간의 통과 기준은 해당 HTTP 보고서 범위로 판단합니다.

별도 `mysql-gate-diagnostic.cjs`는 실제 60학생의 쿠키·120서명 페이지와 한 시간 뒤 일정 두 개를 준비한 뒤 file marker로 단발 gate 1→12→60→120을 허용하는 진단 도구입니다. 자동 polling·학습 이벤트는 없으며 15초 응답 관측을 제품의 5초 통과로 세지 않습니다. 각 파형 전에 전용 DB/프로세스 기준값을 잡고 종료 뒤 DB·PHP upstream을 비운 후 다음 marker를 허용합니다. 마지막에는 소유자·필드·다른 활동 보존을 확인하고 본인 일정 두 개만 DELETE합니다. 실행 중 계측 설정을 추가했다면 원본 SHA 복원과 도구 제거를 먼저 확인한 뒤 정상 부하를 실행합니다.

관측 도구는 같은 PFS fingerprint의 여러 slot을 합산합니다. 초기 snapshot은 마지막 slot으로 덮는 형태여서 raw slot이 사라진 과거 자료의 정밀 집계를 재인증할 수 없습니다. `mysql-observation.php`의 legacy coverage 표식을 확인하며 전역 교착/행 대기와 digest 정밀 수치를 구분합니다. 회귀는 `php focus-tracker/tests/mysql-observation.php`와 `node --test focus-tracker/tests/real-wordpress/native-activity-cleanup.test.cjs`입니다. 빈 일정 삭제는 이벤트·활성 관측이 없는 실패에만 사용하고, 기록이 있는 실패는 close로 남겨 SQL 감사 이력을 유지합니다.

수정 소스와 모든 native/HTTP 검사가 끝난 뒤 1.1.1 최종 ZIP의 설치 보존을 확인합니다. 이 사이에는 브라우저·수집·통계·일정 수정과 cron을 중지하고 순서대로 실행합니다. 사전 스냅샷은 현재 source/schema 1.1.1을 요구하며 이미 저장한 기준을 덮어쓰지 않습니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-package-acceptance.php inspect $mysqlConfig
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-package-acceptance.php prezip $mysqlConfig
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-platform-package.php inspect
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-platform-package.php install
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-platform-package.php verify
$mysqlFinalZip = 'D:/프로젝트/plugins/_releases/focus-tracker/1.1.1/focus-tracker-1.1.1.zip'
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/provision.php $mysqlConfig install-zip $mysqlFinalZip 1.1.1
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-package-acceptance.php postzip $mysqlConfig $mysqlFinalZip
```

Oracle 전용 helper는 application DB의 79 base table 전체 행·컬럼·인덱스·엔진 정의 hash를 비교합니다. 인덱스 cardinality 같은 자동 통계와 명시한 runtime 옵션은 제외하지만 학습 자료·설정·역할·삭제 보호 상태는 제외하지 않습니다. 별도 Judge `jol`은 비교 범위 밖입니다. Platform helper는 고정된 전용 경로와 정식 2.5.5 ZIP·manifest만 허용하며 실제 Plugin_Upgrader 설치와 별도 새 프로세스의 모든 파일 바이트 검증을 수행합니다. Focus 설치 ZIP의 모든 파일/누락/추가 바이트, 실제 native schema health와 사용자 선두 점수 인덱스, 60학생의 개인 grant 없는 역할 상속을 다시 확인합니다. 모든 암호·원시 자료는 비공개 환경에만 남고 정제 보고서와 해시만 검증 증거로 복사합니다. 학교 운영 서버·다른 하드웨어·지속적인 목표 동접과 운영 캐시 환경의 capacity 보증으로 확대하지 않습니다.

실제 HTTP 로그인 준비 중 Platform 2.5.4의 요청 제한 transient timeout 행에서 추가 교착과 TTL 누락을 발견했습니다. 원래의 HTTP 실패·InnoDB 메타데이터는 그대로 보존합니다. 별도 `mysql-rate-limit-acceptance.php`의 `baseline`은 공식 2.5.4에서 실제 사용자와 무관한 전용 카운터 두 개만 만든 뒤 timeout만 제거해 이미 관측한 orphan 형태를 재현합니다. count가 한도에 도달한 상태에서 만료 복구가 되지 않는지 확인하는 fault seed이며 자연 교착을 두 번 재현했다고 주장하지 않습니다. 수정된 Platform 2.5.5를 새 프로세스에서 로드한 뒤 `verify`로 같은 counter/reset_at 보존·bounded expiry 복구와 60개 합성 식별자×2개 독립 호출자, 120 CLI worker·240 저장 연산의 정확한 한도·timeout/index·교착 0을 검증합니다. 실제 계정의 보호 예산은 소비하거나 초기화하지 않으며 전역 `resetAll`은 단위 모델에서만 검사합니다.

```powershell
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-rate-limit-acceptance.php baseline $mysqlConfig
# Install the reviewed new platform runtime, then start a fresh PHP process.
& $php83 @runtimeArgs focus-tracker/tests/real-wordpress/mysql-rate-limit-acceptance.php verify $mysqlConfig
```

이 저장소 검사는 실제 쿠키 로그인·HTTP 워커 용량 시험과 구분합니다. 새 Platform은 DB명·WordPress options 테이블로 범위를 나눈 MySQL named lock 안에서 카운터·만료·추적 목록을 함께 처리합니다. 만료 행이 없는 기존 한도 초과 카운터도 횟수를 유지한 채 만료만 복원합니다. 외부 object cache의 TTL은 해당 backend의 WordPress API 계약을 유지하는 범위이며 SQL timeout 행처럼 직접 검사한 것으로 설명하지 않습니다.
