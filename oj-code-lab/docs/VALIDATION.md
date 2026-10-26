> 최신 1.2.0 단계별 디버거의 검증 범위와 배포물은 [VALIDATION_1_2_0.md](VALIDATION_1_2_0.md)를 확인한다. 1.1.1 기록은 [VALIDATION_1_1_1.md](VALIDATION_1_1_1.md)에 있다. 아래 이전 기록과 구분한다.

# 1.1.0 검증 안내

현재 1.1.0의 변경 범위와 증거 위치는 [1.1.0 검증 안내](VALIDATION_1_1_0.md), 최신 릴리즈 판단은 [배포 게이트](release-gates.json)를 따른다. 아래 1.0.0·RC 본문은 당시 기록으로 보존한다. 그 안의 GO·검사 수치·ZIP 해시·운영 설치 완료 표시는 1.1.0에 적용하지 않는다.

---

# 1.0.0 직접 배포판 검증 안내

**최종 직접 ZIP 배포: GO.** R1–R7을 충족했으며 ZIP은 60개 파일·128,467바이트다. SHA-256은 `7dbebc71d1bdbf9a08bcd1035c63ce5e76b282c37d15acefad84ee38862e151a`이다. qualification의 실행 코드·CSV 49개와 최종 ZIP의 해당 바이트가 같고, 배포 대상 소스 60개 전체와 ZIP이 일치하며 독립 재빌드 해시도 동일하다. 공개 ZIP 옆 manifest·검증 보고서에 연결한다.

현재 공개 배포 대상은 **OJ Code Lab 1.0.0 단독 ZIP**이다. 이번 입력으로 수행한 실제 DB·플랫폼·복원 검사와 데스크톱 브라우저 수용 결과는 [1.0.0 검증 기록](VALIDATION_1_0_0.md)에 모았다. 같은 검사를 여러 환경에서 반복한 횟수나 과거 RC 검사를 고유 검사 수로 합산하지 않는다.

공개 ZIP 관리 경로는 `_releases/oj-code-lab/1.0.0/oj-code-lab-1.0.0.zip`이다. 최종 산출물의 해시·입력과의 바이트 일치 및 배포 결정은 ZIP 옆 증빙과 [배포 게이트](release-gates.json)를 따른다. 라이선스는 최신 제작 위임에 따라 GPL-2.0-or-later와 공개 API 연동 추가 허락으로 정리했다. 운영 사이트의 1.0.0 설치·활성화는 별도 읽기 전용 증거로 확인했으며, WordPress.org 등록은 미실행이다.

2026-09-10 21:40(+09:00) WordPress 관리자 UI에서 **1.0.0 설치·활성화**를 확인했다. Platform은 dev.104이며 새 실습실 탭에서 기존 8셀(코드 7·메모 1)의 ID·종류·본문·출력이 같았다. 코드·텍스트 추가 위치는 각각 9개이고 Ace chrome/16px가 계정 설정과 일치했다. 기존 편집 탭을 유지했으며 운영 노트북 실행·편집·저장은 하지 않았다. 전체 운영 인수와 교사 흐름 재검수는 별도 미완료다. [운영 설치 증거](../../_notes/oj-code-lab-stable-production-20260910.json)를 참조한다.

## 이전 RC 기록 — 당시 원문

아래 RC4·RC6 안내, 수치와 NO-GO·라이선스 보류 표시는 당시 상태다. 현재 판단에는 위 1.0.0 기록과 [직접 배포 정책](RELEASE_POLICY_1_0.md)을 적용한다.

---

# RC4 검증 기록

현재 작업·운영 버전은 **1.0.0-rc.6**이며 OJ Platform은 **2.0.0-dev.104**다. Ace 테마 공유·텍스트 셀의 검증 및 설치 결과는 [RC6 변경 검증](ACE_TEXT_CELLS_2026-09-10.md)과 [RC6 reviewed 배포 증빙](../../_releases/oj-code-lab/1.0.0-rc.6/reviewed/release-evidence.json)을 참조한다. 아래 RC4 검사 수치·산출물·운영 설치 기록은 당시 증거로 보존하며, 공개 정식 배포 판단은 **NO-GO**를 유지한다.

검토일: **2026-09-10 (Asia/Seoul)** · 대상: **1.0.0-rc.4**. 공개 정식 배포는 NO-GO다. 수치와 범위는 [validation-results.json](validation-results.json)에 기록한다. 이전 RC의 검사 개수를 새 버전의 고유 검사로 합산하지 않는다.

## 확인된 결과

| 검사 | 결과 | 범위 |
| --- | --- | --- |
| PHP 회귀 | 36 통과 | WordPress·HTTP·DB doubles |
| 데이터 읽기 실패 | 12 통과 | 실제 실패와 빈 목록·없는 레코드 구분 |
| Kaggle 페이지 처리 | 21 통과 | 최종 클라이언트, HTTP·시각 fixture; PHP 8.1.34 / 8.5.5 각각 |
| JavaScript 회귀 | 27 통과 | Worker·입력·저장·교사 화면의 DOM/Worker doubles |
| 패키지 회귀 | 22 통과 | 샘플 allowlist, 재현 가능한 불변 ZIP, 라이선스 승인 게이트 |
| 운영 사전점검 | 57 assertion 통과 | mock 환경; 운영 서버 실행은 별도 |
| 최소 PHP·WordPress | 조합별 79 통과 | PHP 8.1.34 + WP 6.6.2 / 7.1, MariaDB 11.4.10; 후보 ZIP 직접 사용 |
| 실제 OJ Platform | 44 통과 | WP 7.1/PHP 8.3.33, Platform dev.101 전체 21모듈·48테이블, 실제 인증·역할·소속 서비스 |
| RC3→RC4·전체 DB 복원 | 38 통과 | 실제 ZIP 업데이트 및 변경 전·후 DB 덤프 양방향 복원 |
| RC4 새 설치 | 5 통과 | penguins 단독 seed·CSV 해시·재활성화 중복 방지 |
| DB packet 경계 | 19 통과 | 실제 16MiB SQL 제한, 쓰기 전 거부·기존 데이터 보존 |
| 로컬 요약 동시 조회 | 2,000회, 실패 0 | 원문 200,007,700바이트·100명, 8 worker, p95 1.7905ms |
| 브라우저 후보 ZIP | 엔진별 16 통과 | Chromium 148.0.7778.96 / Firefox 150.0.2 / Playwright WebKit 26.4 |
| PHP 8.1 애드온 계약 | 2 통과 | 플랫폼 등록·runtime/Context 계약 스크립트(test doubles), 전체 WP·DB 검수 아님 |

최종 로컬 검증은 PHP 8.1.34와 8.5.5 각각에서 표의 PHP·읽기 실패·Kaggle·JavaScript·패키지·사전점검을 통과했다. PHP 문법 57개, JavaScript 문법 10개, UTF-8 123개도 확인했다. 호환성 원본 보고서는 `_notes/oj-code-lab-compat-20260909.json`이며 전용 서버·DB·비밀 설정 정리를 완료했다.

브라우저 검사는 Windows Playwright 1.60.0과 Pyodide 0.26.4를 사용했다. WebKit 엔진 검사는 Apple Safari 앱·실기기 검사를 대체하지 않는다. 브라우저 인증·자료 응답은 fixture이며 Python 실행·WordPress REST·DB는 실제 구현이다.

실제 Platform 검사는 공개 Gateway fixture를 사용하지 않는다. `RealAddonContextGateway`, 실제 WordPress 인증·역할 관리, 학급·모둠 DB 서비스를 통해 두 학급의 학생 분리, 소속 이동과 교사·운영자 권한 회수를 검증했다. 브라우저 로그인 화면·프록시 검수는 포함하지 않는다. WordPress 79개 통합 검사의 Context Gateway와 브라우저 인증은 별도 fixture이며 플랫폼 버전 dev.99/API 1.1.0 상수를 사용한다. 이는 플랫폼 전체 부팅이 아니며 실제 Platform 44개와 구분한다. 추가 PHP 8.1 계약 2개는 dev.101 소스의 등록/runtime·Context 계약 스크립트와 test doubles를 사용했다.

DB packet 검사에서 16,777,216바이트 한도에 대해 유효 저장 상한은 8,355,840바이트, 최대 관찰 SQL 쓰기는 16,711,933바이트였다. 패킷 검사 이후 변경된 업로드 읽기 오류 처리는 별도 12개 회귀 및 WordPress 통합 검사로 확인했다. packet 19개를 후보 ZIP 전체 실행 결과로 표시하지 않는다.

동시 조회는 실제 `summaries_for_users()`와 SQL을 측정한 로컬 부하다. 요청당 학생 50명의 요약만 반환했고 최대 응답은 3,801바이트였다. REST·인증·브라우저·프록시 비용은 제외되며, 운영 최대 처리량이나 동시 학생 100명의 서비스 응답 시간으로 해석하지 않는다.

## 어떤 ZIP을 검사했는가

후보 1은 `_notes/oj-code-lab-validation-inputs-20260910/oj-code-lab-1.0.0-rc.4.zip`에 불변 보존했다. 원본 보고서의 임시 실행 경로는 당시 기록이며, 보존한 ZIP의 바이트가 동일함을 확인했다. 후보 1의 SHA-256은 `3d459425ec32727c12df8aa44ad74f4374a610f93da39f96325bb51ebc011830`이다. 57개 파일과 `penguins.csv` 1개를 포함한다. 최소 PHP 통합, 브라우저 3개 엔진 각각 16개, 실제 Platform 44개, 복원 38개와 새 설치 5개는 이 ZIP을 해제해 실행했다.

최종 reviewed ZIP은 `_releases/oj-code-lab/1.0.0-rc.4/reviewed/oj-code-lab-1.0.0-rc.4.zip`이며 SHA-256은 `271853c3a32eb9af32548d76767f1858ba4313083890944e6c76364470d1951b`, 57개 파일·108,085바이트다. 후보 1과 비교한 변경 파일은 README·CHANGELOG·readme.txt·docs/PRIVACY.md 및 `Kaggle_Client.php`다. 실행 코드의 차이는 Kaggle 클라이언트 1개이며 브라우저 자산·CSV와 나머지 실행 파일은 동일하다.

PHP 8.1/8.5 검증 보고서의 `05b717a3df878e91d529cf92ea4ee4e8a32913ec9d3fc3cdd4a8b6596c1a6fa9` ZIP은 보존된 중간 빌드다. 그 뒤 readme.txt·docs/PRIVACY.md의 공식 PyPI 개인정보 고지 링크만 정정해 reviewed ZIP을 만들었다. 두 ZIP의 실행 코드·브라우저 자산·CSV 바이트는 모두 같으며 고지 변경 후 PHP 8.5 로컬 검증도 다시 통과했다. 과거 보고서의 검증 대상 해시는 바꾸지 않는다.

새 Kaggle 클라이언트는 전체 파일 목록을 최대 10페이지·1,000파일·20초 범위에서 조회하고, 중간 실패·반복 토큰·한도 초과는 부분 목록 대신 오류를 반환한다. 최종 PHP 8.1.34와 8.5.5에서 별도 HTTP 회귀 21개씩과 전체 로컬 검증을 통과했다. [Kaggle 계약 검토](KAGGLE_CONTRACT_REVIEW_2026-09-10.md)의 공개 API 무인증 GET 4건은 검색·2페이지 응답을 확인한 것이며 실계정 인증·다운로드 수용을 뜻하지 않는다. 후보 1의 실제 DB·브라우저 시험을 최종 ZIP 전체 재실행으로 표시하지 않는다. 최종 ZIP 옆 `release-evidence.json`·manifest에 차이와 증거를 연결한다. 이전 RC의 불변 증빙 경로는 validation-results.json의 `immutable_releases`에 유지한다.

RC3의 기본 자료 29개 중 사용자가 1개를 삭제한 상태를 만들고, 남은 28개의 메타데이터·업로드 BLOB·노트북·암호화 설정이 RC4 업데이트와 복원 뒤 동일함을 확인했다. 새 설치는 펭귄 데이터만 제공하며 기존 설치 자료를 삭제하지 않는다.

## 재실행

플러그인 루트에서 `python scripts/verify.py --php D:/php/php.exe`로 로컬 정적·회귀·패키지 검사를 실행한다. 실제 서비스 검사는 다음 하네스로 분리한다.

| 하네스 | 실행 환경과 보호 범위 |
| --- | --- |
| `tests/wordpress-integration.php` | 새 loopback 33368 DB; `OCL_TEST_CONFIG/WP/PLATFORM/PLUGIN/REPORT` 지정. 스키마·사용자 삭제·uninstall 포함 |
| `tests/browser-router.php`, `tests/browser.cjs` | loopback 33369, 위 폐기 DB·후보 ZIP, Playwright 엔진. 노트북 내용이 바뀌는 테스트 |
| `tests/platform-acceptance.py` | 실제 플랫폼 전체 부팅, 독립 33388 DB 생성·정리. `--plugin`에 해제한 후보 지정 |
| `tests/operations-rehearsal.py` | `--db-port 33388 --fresh-sample-keys penguins`와 구·신 ZIP 해제 폴더 지정. 새 DB/덤프 생성 |
| `tests/packet-integration.php`, `tests/run-summary-load.py` | 별도 실제 packet/동시 SQL 검사; 운영 DB 사용 금지 |

실제 하네스에는 PHP mysqli·OpenSSL·mbstring과 해당 Platform 필수 확장을 준비한다. 비밀 DB 설정·덤프·로그를 배포 ZIP에 넣지 않고 종료 후 자신이 만든 환경만 정리한다. 운영 사전점검의 `productionReady: unknown` 또는 종료 코드 0은 운영 승인이나 복원 성공을 뜻하지 않는다.

## 운영과 남은 수용 범위

2026-09-10 운영 사이트에 **최종 RC4 ZIP을 설치하고 활성 상태를 확인했다**. WordPress 7.1/PHP 8.3.6/OJ Platform 2.0.0-dev.100에서 관리자 로그인, 학급·모둠 요약과 빈 노트북 상세 읽기 전용 검수가 통과했다. Code Lab 스키마·키 암호화·캐시 정리 예약은 정상이며 노트북 JSON과 CSV의 저장 상한은 각각 **8,355,840바이트**로 적용됐다. 실제 데이터·역할·계정은 변경하지 않았다. 설치 증빙은 `_releases/oj-code-lab/1.0.0-rc.4/production-postcheck-20260910.json`에 기록한다. 공개 가능 오류 로그 경고는 남아 있으며 운영 백업·복원, 서버 접근과 로그 조치, Kaggle 실계정, 실제 Safari, 프록시/CDN 및 전체 학생 실행·저장은 미완료다. [릴리즈 체크리스트](RELEASE_CHECKLIST.md)와 [게이트](release-gates.json)를 따른다.
