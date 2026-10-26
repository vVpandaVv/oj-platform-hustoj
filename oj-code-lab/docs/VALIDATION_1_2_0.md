# 1.2.0 단계별 디버거 검증 — 2026-10-12

코드 셀에 브라우저 측 단계별 디버거(한 줄씩 실행, 함수 안으로·밖으로, 변수 표, 중단점, 단계 기록)를 추가하고 콘솔 경고를 없앤 릴리즈다. PHP·REST·저장·DB 스키마·플랫폼 연동 파일은 1.1.1과 바이트가 같고 `oj-code-lab.php`·`plugin-package.php`의 버전 문자열만 바뀌었다.

- [배포 검토](../../_notes/oj-code-lab-debugger-release-review-20261012.json): 1.1.1 대비 변경 파일 목록, 테스트한 소스 해시, 실행하지 않은 검사.
- [현재 로컬 검사](../../_notes/oj-code-lab-debugger-local-20261012.json): PHP 8.5.5(zip 확장)·Node 24에서 PHP 문법 61개, JS 문법 17개, JS 66개, CPython 3.14 디버거 하네스 34개, PHP 회귀 42개, 패키지 32개, ZIP 67개, 형식 28개, Kaggle 21개, 읽기 실패 12개, 사전점검 57개 통과.
- [Chromium 편집기 수용](../../_notes/oj-code-lab-editor-chromium-20261012.json): Playwright Chromium 148, 실제 Ace 1.44와 Pyodide 0.26.4에서 기존 13개 항목 통과.
- [Chromium 디버거 수용](../../_notes/oj-code-lab-debugger-chromium-20261012.json): `tests/debugger-acceptance.cjs` 14개 항목을 두 번 연속 통과했다. 실제 키보드·마우스 입력, 디버깅 중 `input()`, 중단점, 단계 기록, 오류 지점 기록, 편집 잠금을 확인했고 페이지 오류와 콘솔 경고는 없었다.
- 소유자 보고: 2026-10-12 사용자 사이트에 1.2.0-rc.2를 오류 없이 설치했고 정상 동작한다고 보고했다. 해당 사이트는 이 작업에서 직접 관찰하지 않았다.

Firefox·WebKit·실제 Apple Safari·모바일 기기, PHP 8.1, 실제 WordPress·DB·플랫폼 검사는 1.2.0에서 다시 실행하지 않았다. 서버 측 실제 환경 검증은 [1.1.0 기록](VALIDATION_1_1_0.md)의 이력이다. 최종 1.2.0 ZIP의 운영 설치는 확인하지 않았다.
