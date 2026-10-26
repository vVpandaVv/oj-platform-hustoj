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
