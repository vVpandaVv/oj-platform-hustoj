# Focus Tracker

OJ Platform 문제·제출 화면의 **화면 활성 상태와 편집·제출 신호**를 기록하는 애드온입니다. 자동 계산값은 학습 참여 참고 지표이며, 학생의 생각·이해·풀이의 질이나 실제 집중을 직접 측정하지 않습니다.

## 요구 사항과 설정

- OJ Platform 2.3.22 이상 / Addon API 1.4 이상, PHP 8.3 이상, WordPress 6.6 이상.
- 교사 콘솔 › 수업 › 집중도 평가에서 학급·모둠, 시간, 문제 번호를 지정해 활동을 만듭니다. 권한은 플랫폼 역할 관리에서 부여합니다.
- 설정은 교사 콘솔 › 집중도 평가 › 설정입니다. 기본 가중치는 화면 활성 8, 복귀 6, 입력·제출 6입니다. 읽기·생각하기 수업은 입력·제출 가중치를 0으로 둘 수 있습니다.
- 기본 추적 경로는 `/problems`와 `/submit`입니다. 설치 하위 경로, `oj_route`, `/submit?problem_id=123`도 처리합니다. 다른 애드온·외부 IDE까지 자동 수집하지는 않습니다.
- 학생 메뉴 › 내 집중도 요약에서 같은 기록과 상태를 확인합니다.

## 1.0.1 DB 부하 개선

- 저장소 설치·정기 점검은 임시 임대 잠금으로 한 요청만 수행합니다. 실패 후 같은 버전의 자동 재시도는 최소 1분 뒤에 하며, 준비되지 않은 저장소의 기록 API는 계속 중단합니다.
- 학생이 속한 학급·그룹의 활동 일정을 한 SQL로 조회합니다. 저장 중 일정은 공유 읽기 잠금으로 검증하여 같은 활동의 학생 저장끼리 배타 잠금을 기다리지 않게 하고, 교사의 시간 수정·종료·삭제 검증과 개인정보·세션 잠금은 유지합니다.
- 운영 진단은 종류·상태별 분당 한 번의 표본입니다. 표시한 관측 분 수는 전체 요청 횟수가 아닙니다. 학습 기록과 문항·요청 제한은 표본으로 바꾸지 않습니다.
- 활동 확인 5초·승인 최대 10초, 숨겨진 탭 기록, 현재 플랫폼 API를 통한 소속 확인과 다른 애드온으로부터의 독립성을 유지합니다. 원시 이벤트 전체를 사용하는 세션 재집계와 장기 통계·정리는 별도 성능 검토 대상입니다.

## 1.0.0 정식 릴리스

- 학생 역할(`subscriber`)에 추적 권한을 한 번 기본 부여합니다. 기존·신규 학생 모두 역할 상속으로 자동 적용되어 학생별 권한 설정이나 참여 버튼이 필요 없습니다. 명시적인 역할·개인 거부와 기본 부여 이후 관리자의 회수는 존중합니다. 일반 학생의 계정 관리를 막지 않는 플랫폼 판정 확장이 필요해 OJ Platform 2.3.22 이상을 요구합니다.
- 활동 목록에서 예정·진행·종료 활동을 삭제할 수 있습니다. 담당 교사의 관리 권한을 확인하고 삭제 확인 후 목록·활동별 결과에서 제거하며 해당 활동의 수집·문항을 중단합니다. 이미 수집한 학습 기록과 주간·월간 통계는 보관 정책에 따라 유지합니다. 다른 진행 활동에는 영향을 주지 않습니다.

- 원시 활동 기록, 확인 문항·응답, 일별·활동 집계의 보관 기간을 설정에서 따로 지정합니다. 기본값은 각각 60일·60일·0(기간 제한 없음)입니다. 유한 집계 기간은 앞 두 기간보다 최소 1일 길어야 합니다. 기간을 줄이면 다음 정리에서 삭제될 수 있으며 삭제한 상세 기록은 복원하지 않습니다.
- 정리는 실행 시간과 처리량을 제한하고 남은 자료를 이어 처리합니다. 실패한 자료는 보존·재시도하며 뒤 자료를 막지 않습니다. 운영 상태에 마지막 실행·성공, 남은 작업과 오류를 표시합니다. WP-Cron이 실행되지 않으면 정리도 지연됩니다.
- WordPress 개인정보 도구에서 학생별 기록을 내보내고 삭제할 수 있습니다. 계정 삭제 시 해당 학생의 원시 자료·문항·집계·발급 상태를 삭제하고 다른 학생과 공유 활동은 유지합니다. 교사의 개인별 기록 조회는 현재 명단과 권한을 다시 확인합니다.
- 원자적 요청 제한, 민감한 원문을 저장하지 않는 운영 카운터, 설정·활동 변경 이력, WordPress 사이트 건강 검사를 제공합니다. 저장소 업그레이드·InnoDB·중복 방지 인덱스 확인에 실패하면 기록 API를 중단합니다.
- 학생은 활동 시작 대기·기록 중·종료·연결 오류 상태를 확인할 수 있습니다. 교사 일정 상태도 시간에 따라 갱신하고, 확인 문항의 키보드 초점과 알림을 보완했습니다.
- 설치 ZIP은 릴리스 킷으로 생성합니다. 실제 WordPress 7.1·PHP 8.3.33·MariaDB 11.4.10에서 기존·신규 학생의 자동 권한과 그룹 활동 자동 참여·종료를 검증했습니다. 환경과 결과는 [실환경 검증](docs/REAL-WORDPRESS.md), 학교 운영 환경에서 추가할 항목은 [인수 점검](docs/RC-ACCEPTANCE.md)에 구분합니다.

## 활동 시간에만 수집·확인

- 현재 학생이 속한 학급·그룹의 활동 시간과 문제 범위가 일치할 때만 기록과 자동 확인을 켭니다. 활동이 없거나 시작 전·종료 후이면 초안도 읽거나 전송하지 않습니다. 학급·그룹을 명시한 페이지는 해당 문맥으로 제한합니다.
- 열어 둔 페이지도 시작 시 서버 확인 후 자동으로 켜지고, 알려진 종료 시각에는 기록과 문항을 자동 중단합니다. 시간 수정·조기 종료·소속 변경은 기본 5초 간격으로 다시 확인하고, 확인 실패 시 최대 10초 승인 기한이 끝나면 중단합니다. 서버는 변경된 시간 밖의 기록·출제·응답을 즉시 거부합니다.
- 종료 전에 발생한 기록은 24시간 이내 지연 전송할 수 있습니다. 시간 밖 이벤트는 저장하지 않고 처리 완료로 응답해 재전송 대기열에 쌓이지 않게 합니다. 겹치거나 연속된 활동은 중복 수집하지 않고, 활동 사이의 빈 시간은 제외합니다.
- 종료로 닫힌 미응답 문항은 취소로 처리합니다. 오답·무응답을 미집중으로 판단하지 않는 원칙을 유지합니다.

## 그룹 시간표와 누적 통계

- 학생은 별도 참여 신청·입장·시작 버튼 없이 자기 계정으로 로그인해 평소처럼 문제를 풀면 됩니다. 학생 추적 권한은 기존·신규 학생 역할에 기본 적용합니다. 현재 학급·그룹 소속, 활동 시간·문제 범위가 맞으면 자동으로 기록합니다. 학생의 그룹 소속은 미리 준비해야 합니다.
- 교사 콘솔에서 학급·그룹별 활동 시작·종료 시각을 지정하고, 그룹별 목록에서 시간 수정·지금 종료를 할 수 있습니다. 지정 시간과 문제에 해당하는 기록으로 그룹 활동을 집계합니다.
- 학생 본인 화면과 교사의 학생별 누적 통계에서 주간·월간 기간을 선택합니다. 주간은 월요일~일요일, 월간은 달력 월이며 사이트 시간대를 사용합니다.
- 페이지 관측 시간·화면 활성 시간·활성률·편집/실행/제출 신호와 일별 변화 그래프를 제공합니다. 탭과 겹치는 활동의 시간은 합집합으로 계산하며 비율은 전체 시간으로 계산합니다. 하루 비율이나 활동 점수를 단순 합산하지 않습니다.
- 교사 통계는 권한이 있는 해당 학급·그룹의 활동 시간·문제 범위만 포함합니다. 학생 본인은 자신의 누적 기록을 보며, 새 기록은 활동 시간에만 쌓입니다. 이전 버전에서 수집한 본인 기록은 유지합니다. 자동 확인 응답은 별도 집계하며 집중 점수에 넣지 않습니다.
- 원시 기록 만료 전에 일별 누적 집계를 저장해 장기 통계를 유지합니다. 자료 없음·누락·보관된 집계를 구분하고, 이미 삭제된 과거 기록은 임의로 복원하지 않습니다.

## 자동 학습 확인

- 교사는 활동 시간만 설정하면 됩니다. **문항 제작·채점·수동 출제 없이** 해당 시간에 문제를 여는 학생에게 자동으로 문항을 제시합니다. OJ Platform 2.3.22의 학습 확인 확장을 사용하며, 이전 플랫폼은 지원하지 않습니다.
- 제출하지 않아도 현재 문제의 Ace·일반 코드·완성된 빈칸·Blockly 초안을 사용합니다. 본인의 최근 제출 코드와 공개 문제 예제도 활용합니다. 서로 다른 자료가 있으면 출처를 번갈아 선택합니다.
- 코드에서는 값을 명시한 독립 정수 비교식과 Python `range`, 문제에서는 명시된 예제 입력·출력 쌍만 출제합니다. 서버가 정답과 자료 일치를 다시 계산한 문항만 제시합니다. 학생 프로그램을 실행하거나 전체 출력·임의 변형의 정답을 추측하지 않습니다. 지원하지 않는 문법·모호한 예제는 출제하지 않습니다.
- 기본값은 활동 시간 안에서 켜진 45초 후 확인, 문항 간격 최소 5분, 학생별 하루 3개, 응답 유효기간 10분과 활동 종료 중 먼저 도래한 시각입니다. 설정에서 활성 여부·간격·일일 상한을 바꿀 수 있습니다. 작은 알림을 눌러 문항을 열 수 있으며 입력을 막거나 포커스를 빼앗지 않습니다.
- 정답·오답·모름·건너뛰기·무응답·취소를 별도 기록합니다. **모든 응답 결과는 집중 점수와 분리**하며 오답·무응답을 미집중으로 판단하지 않습니다. 본인 요약에서 이전 확인 기록도 볼 수 있습니다.
- 초안은 UTF-8 64KB 이내를 서버에서 일시 분석합니다. 전체 초안을 DB·브라우저 저장소에 별도 보관하지 않고, 출제 시점의 문항에 필요한 코드 일부·자료 해시·검증 근거·응답만 저장합니다. 외부 AI 서비스나 API 키는 사용하지 않습니다.

## 0.3.0-dev 변경

- 플랫폼의 `siteAsset`으로 독립 HTML 화면에 추적기와 설정을 적재합니다. 테마 페이지용 WordPress enqueue도 유지합니다.
- 실제 제출 폼, Ace/일반 코드 입력, 코드 빈칸 완성, Blockly 변경을 감지합니다. 연속 입력은 주기적으로 기록하고 종료 직전 입력도 보존합니다. 실행 신호는 실행 버튼이 있는 화면에서만 수집합니다.
- 사용자별 전송 대기열과 서버 ACK, 재시도, 세션별 이벤트 순번 중복 방지를 사용합니다. 최대 1,000건/24시간이며 한도 초과는 누락 상태로 표시합니다. 강제 종료·저장소 차단·장기 오프라인까지 무손실을 보장하지 않습니다.
- 수업 전 접속, 페이지 이동, BFCache 복원, 중첩·연속 활동을 처리합니다. 하나의 세션을 관련된 여러 활동에 연결하며 모든 컨텍스트 페이지를 조회합니다.
- 탭별 활성 구간의 합집합을 사용합니다. 최신 heartbeat가 살아 있으면 즉시 이탈로 감점하지 않습니다. 45초 넘게 활성을 관측하지 못한 공백은 활성 시간으로 채우지 않습니다.
- 진행 중 값은 재계산하고, 종료 45초 이후 집계만 캐시합니다. 활동·가중치·설정·이벤트 변경을 검증합니다. 그래프와 점수는 같은 활성 구간을 사용합니다.
- 자료 없음·일부 누락·진행 중·계산 완료·원시 기록 만료를 구별합니다. 자료 없음과 불완전한 기록의 참여 지표는 화면에 숫자로 확정 표시하지 않습니다.
- 원시 기록을 지우기 전에 최종 집계 저장을 확인합니다. 저장 실패 시 원시 기록을 유지합니다. 원시 기록 만료 후 새 가중치로 과거 점수를 만들어내지 않습니다.

## 검증

저장소 루트에서 실행합니다. PHP가 PATH에 없으면 `D:\php\php.exe`를 사용합니다.

```text
php focus-tracker/tests/scoring.php
php focus-tracker/tests/scoring-service.php
php -d extension=pdo_sqlite focus-tracker/tests/events-storage.php
php -d extension=pdo_sqlite focus-tracker/tests/activity-gate.php
php -d extension=pdo_sqlite focus-tracker/tests/database-performance.php
php focus-tracker/tests/platform-integration.php
php focus-tracker/tests/bootstrap-smoke.php
node --test focus-tracker/tests/tracker-client.cjs
node --test focus-tracker/tests/activity-gate-client.cjs
node focus-tracker/tests/platform-browser.cjs
node focus-tracker/tests/activity-gate-browser.cjs
php focus-tracker/tests/check-generator.php
php -d extension=pdo_sqlite focus-tracker/tests/check-storage.php
php -d extension=pdo_sqlite focus-tracker/tests/check-gate.php
php -d extension=pdo_sqlite focus-tracker/tests/release-readiness.php
php -d extension=pdo_sqlite focus-tracker/tests/retention-cleanup.php
php -d extension=pdo_sqlite focus-tracker/tests/privacy-security.php
node --test focus-tracker/tests/checks-client.cjs
node focus-tracker/tests/check-browser.cjs
php oj-integration-rebuild/oj-platform/tests/Contract/addon-learning-check-runtime.php
php -d extension=pdo_sqlite focus-tracker/tests/activity-schedules.php
php -d extension=pdo_sqlite focus-tracker/tests/activity-delete.php
php -d extension=pdo_sqlite focus-tracker/tests/activity-delete-statistics.php
php -d extension=pdo_sqlite focus-tracker/tests/student-defaults.php
php -d extension=pdo_sqlite focus-tracker/tests/statistics.php
node --test focus-tracker/tests/statistics-client.cjs
node focus-tracker/tests/statistics-browser.cjs
node focus-tracker/tests/operations-browser.cjs
node focus-tracker/tests/activity-delete-browser.cjs
node focus-tracker/tests/student-defaults-browser.cjs
node focus-tracker/tests/http-security.cjs
_release-kit\release.cmd check
```

패키지 생성 후 `php -d extension=zip focus-tracker/tests/package-smoke.php _releases/focus-tracker/1.0.1/focus-tracker-1.0.1.zip`으로 테스트 도구 제외, 필수 파일 포함, 검사한 소스와 ZIP의 바이트 일치·체크섬을 확인합니다.

브라우저 검사는 Playwright와 Chrome/Chromium이 필요합니다. `PLAYWRIGHT_MODULE_PATH`, `PLAYWRIGHT_CHROMIUM_EXECUTABLE` 또는 `CHROME_PATH`, `PHP_BINARY`로 도구 경로를 지정할 수 있습니다. 위 회귀는 실제 플랫폼 원본 렌더러·Ace·Blockly와 운영 REST 컨트롤러·저장소·계산기를 연결하며, WordPress 인증/컨텍스트는 대역이고 DB는 SQLite 어댑터입니다. 이 회귀와 구분하여 네이티브 WordPress·MariaDB·실제 로그인 쿠키로 실행하는 검사를 [별도 안내](tests/real-wordpress/README.md)에 제공합니다.

설계와 한계는 [docs/DESIGN.md](docs/DESIGN.md), 검증 결과는 [docs/VERIFICATION.md](docs/VERIFICATION.md), 실환경 인수·복구 점검은 [docs/RC-ACCEPTANCE.md](docs/RC-ACCEPTANCE.md)에 있습니다. 설치 안내서 원본은 `../_release-kit/guides/focus-tracker.md`이며 생성된 안내서와 ZIP은 `_releases/latest/`에 있습니다. 설치 ZIP에는 테스트 서버·검사 도구를 넣지 않습니다.
