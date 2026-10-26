# 개발·출시 검증

## 1.0.0 기술 인수와 검증 범위

2026-09-11 실제 OJ dev.107 / ML RC6 / 스키마 4에서 합의한 G1 검사를 통과했습니다. 취소 후 시험 프로젝트 보존, 명시 삭제 성공 안내와 두 탭 종료, 정상 재로그인 후 미노출, test2 서버 원본 안전 보기와 test1 공개 평가 보존을 확인했습니다. 대상은 빈 합성 프로젝트 1개이며 대용량·36명 동시 사용의 증거가 아닙니다. 상세 근거는 [기술 승인 기록](release-1.0.md)을 따릅니다.

정식 1.0.0은 RC6의 실행 코드·번들을 유지하고 메타데이터와 문서를 승격합니다. 이 문서는 정식 패키징 시점의 기록이며 최종 소스·패키지 검증 및 실제 1.0.0 설치 결과는 배포 폴더의 외부 검증 기록을 따릅니다. 서버 관리·로그·백업·복원은 운영자 인계로 유지하고 미검증 항목을 PASS로 표현하지 않습니다.

## RC6에서 추가해 1.0.0에 유지하는 공용 PC 삭제 회귀

- `tests/public-pc-status-regression.mjs`: 페이지 내 명시 확인·취소·Esc·중복 클릭·표시 실패·숨김·페이지 종료·세션 변경, 새 계정 검증, iframe/직접 삭제, blocked/시간 초과와 늦은 완료를 검사합니다. 확인되지 않은 삭제를 성공으로 표시하거나 다른 계정의 저장소를 지우면 실패합니다.
- `tests/public-pc-dispatch-regression.mjs`: 실제 controller와 bootstrap을 함께 로드해 브라우저 메시지 리스너 순서로 정상 ACK·오류·잘못된 nonce·출처·revoke를 검사합니다. controller만 분리한 테스트로 놓칠 수 있는 중복 응답 처리를 잡습니다.
- `tests/public-pc-lifecycle-regression.php`: 확인→새 검증→삭제 순서, 단일 dispatcher, 안전 잠금 및 결과 지속 표시 계약을 검사합니다.
- 내장 앱을 바꿀 때는 패치 적용 소스의 `oj-public-pc-wipe-race.spec.ts`와 전체 업스트림 검증도 실행합니다. 새 탭의 공유 잠금이 삭제보다 앞서지 않고, native DB 요청이 실제로 끝날 때까지 배타 잠금이 유지되는지 확인합니다.
- 페이지 내 확인창은 실제 브라우저에서 제목·범위 안내·취소 초기 초점·Tab/Shift+Tab·Esc·취소 후 복귀도 확인합니다. 합성 로컬 UI 검사와 실제 학생 저장소 삭제 시험은 별개의 증거입니다.

새 배포 환경에서는 전체 설치 후 취소 시 프로젝트 보존, 두 실습 탭의 종료, 완료 메시지, 재로그인 후 기존 프로젝트 미노출과 기존 서버 제출·평가 보존을 확인합니다. UI의 시간 초과는 native 삭제 취소를 뜻하지 않습니다. 현재 사이트의 실제 통과와 새 환경에 적용할 검수 절차는 구분합니다.

## rc.2 교사 UI 브라우저 검사

`tests/browser-workflow-smoke.cjs`는 실제 JS/CSS를 headless Chrome/Edge에서 로드하되 API는 합성 응답으로 대체합니다. 사용자 브라우저 프로필·운영 서버·학생 자료는 사용하지 않습니다. Node의 Playwright 패키지 경로를 `OJ_ML_PLAYWRIGHT`로 지정한 뒤 `node tests/browser-workflow-smoke.cjs`를 실행합니다.

1280px/390px에서 제출 보기·평가, 저장 실패 입력 보존, 학생별 추가 허용, 양식 생성·편집·다운로드, REPORT 전용 권한, 문서 및 상세 폼의 가로 잘림을 검사합니다. 스크린샷은 개발 워크스페이스의 `_notes/tmp/oj-ml-workflow-ui`에 생성됩니다. 실제 공용 PC 파일 선택/사용자 전환과 학교 OJ 인수시험은 별도입니다.

`tests/portfolio-template-regression.php`는 문항/HTML/크기/UTF-8/게시 후 잠금과 학생 DTO를, `tests/archive-open-regression.php`는 실제 스트리밍 ZIP 및 오류 주입을 검증합니다. `tests/teacher-workflow-regression.mjs`는 UI 안내와 권한, 양식의 ID·원문 보존을 실행 검사합니다.

검증 스크립트와 테스트 소스는 개발 트리에 있으며 설치 ZIP에서는 제외합니다.
ZIP은 실행 파일·고지·설치·운영 문서만 포함하고 빌드 자산의 해시 목록을 함께 제공합니다.

## 로컬 회귀 검사

PowerShell 7 또는 Windows PowerShell에서 다음을 실행합니다.

```powershell
./scripts/verify.ps1 -Php /path/to/php -Node /path/to/node -RequirePlatform
```

PHP에는 zip·mbstring이 필요합니다. Windows의 별도 PHP 패키지는 -ExtensionDirectory에 ext 경로를 지정할 수 있습니다.
OJ 플랫폼 소스는 OJ_PLATFORM_TEST_ROOT 환경변수로 지정합니다. 플랫폼을 찾지 못한 SKIP은 -RequirePlatform에서 실패로 처리합니다.

## 실제 WordPress·DB 통합

tests/Integration/wordpress.php는 다음 환경변수를 요구합니다.

- OJ_ML_TEST_WP_PATH: 압축 해제하고 공식 체크섬을 확인한 WordPress 경로
- OJ_ML_TEST_DB_HOST: 127.0.0.1:별도포트 (CI 서비스 mysql도 허용)
- OJ_ML_TEST_DB_USER / OJ_ML_TEST_DB_PASSWORD: 시험 전용 DB 계정
- OJ_ML_TEST_DB_NAME: release_고유값_oj_ml_test 형식의 새 DB
- OJ_ML_TEST_STORAGE: WordPress/문서 루트 밖의 새 시험 디렉터리

```text
php -d extension=mysqli -d extension=zip tests/Integration/wordpress.php
```

매 실행마다 새 DB를 만들며 기존 DB를 덮어쓰거나 DROP하지 않습니다. DB 생성 권한이 필요하고 운영 DB 계정을 사용하면 안 됩니다.
검사 중 삭제는 방금 만든 가상 학생과 파일에만 수행합니다. 종료 후 출력한 fixture DB·폴더는 개발자가 식별하여 정리합니다.
현재 시험은 실제 WordPress·SQL 저장/마이그레이션 계약이며, 실제 OJ 로그인·학급 UI end-to-end를 대신하지 않습니다.
구버전 업그레이드 시험은 자료가 들어 있는 스키마를 v2 형태로 만든 뒤 스키마 3으로 복구합니다. 운영 DB 백업의 복원 시험은 별도입니다.

## ZIP 만들기

검증을 통과한 뒤 플러그인 밖의 비어 있는 출력 폴더를 지정합니다.

```text
php scripts/package.php /absolute/release-output
```

ZIP 내부 루트는 oj-ml-playgrounds입니다. 버전 일치, UTF-8/BOM, 금지 경로, 비공개 OJ 클래스 결합을 검사합니다.
각 항목은 고정 시간·권한·정렬 순서로 저장하고 ZIP을 다시 열어 SHA-256을 대조합니다.
ZIP 옆의 .sha256과 .manifest.json으로 전달된 파일을 검증할 수 있습니다.
동일 PHP/libzip 환경에서 두 번 생성한 ZIP의 SHA-256이 같아야 합니다.
서로 다른 libzip/zlib 버전의 압축 바이트까지 동일하다고 보장하지 않으며 파일별 manifest를 비교합니다.

## 정식 1.0.0 승격 검증

[출시 기록](release-1.0.md)의 실제 기술 인수 근거를 보존하고 버전 헤더·상수·plugin-package.php·readme.txt·문서를 함께 1.0.0으로 변경합니다. 운영자 직접 수행으로 인계한 서버·백업·복원 및 제외한 부하 시험을 에이전트 검증 PASS로 표시하지 않습니다.
최종 소스 검증과 새 1.0.0 ZIP 패키징·해시·manifest 대조를 수행합니다. 실행 코드·내장 번들은 RC6와 동일하게 유지하고 그 사실을 대조하며, 내장 앱을 실제로 바꾼 경우에만 해당 업스트림 변경의 전체 검증·재빌드가 필요합니다. 단순히 RC ZIP의 파일명을 바꾸지 마세요.
