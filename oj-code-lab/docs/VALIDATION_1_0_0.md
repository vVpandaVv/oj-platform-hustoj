# 1.0.0 직접 배포판 검증 기록

**최종 직접 ZIP 배포: GO.** R1–R7을 충족했으며 ZIP은 60개 파일·128,467바이트다. SHA-256은 `7dbebc71d1bdbf9a08bcd1035c63ce5e76b282c37d15acefad84ee38862e151a`이다. qualification의 실행 코드·CSV 49개와 최종 ZIP의 해당 바이트가 같고, 배포 대상 소스 60개 전체와 ZIP이 일치하며 독립 재빌드 해시도 동일하다. 공개 ZIP 옆 manifest·검증 보고서에 연결한다.

검토일: **2026-09-10** · 대상: **OJ Code Lab 1.0.0 단독 공개 ZIP**. 아래 수용 검사는 완료됐으며 최종 산출물의 승인·해시는 [배포 게이트](release-gates.json)와 ZIP 옆 manifest·검증 보고서에 연결한다. 공개 경로는 `_releases/oj-code-lab/1.0.0/oj-code-lab-1.0.0.zip`이다. 1.0.0 운영 설치·활성화는 아래 별도 증거로 확인했다. WordPress.org 등록 완료를 뜻하지 않는다.

## 검증 입력과 배포 범위

실제 DB·플랫폼·복원·packet·SQL 검사는 qualification 전용 candidate-2 ZIP을 해제한 1.0.0 입력에서 새로 실행했다. [통합 보고서](../../_notes/oj-code-lab-stable-validation-20260910.json)의 입력은 `_notes/oj-code-lab-stable-validation-inputs-20260910/candidate-2/qualification-1.0.0.zip`, SHA-256 `9db240ac6c89137cbd30b1e951cb4bd7bc1bf02967a32295572e5a52ac4843ce`다. 60개 파일·127,857바이트이며 PHP/JS/CSS/CSV 49개의 검사 전후 해시를 확인했다. 이 내부 입력을 최종 공개 ZIP으로 표시하지 않는다. 최종 ZIP과 49개 파일의 바이트 일치를 확인했고, 공개 설명·라이선스 등 메타데이터의 차이는 ZIP 옆 검증 증거에서 별도로 관리한다.

GPL-2.0-or-later 및 공개 API 연동 추가 허락은 최신 사용자 제작 위임에 따라 정리했다. 적용 범위와 제외 항목은 [직접 배포 정책](RELEASE_POLICY_1_0.md)과 실제 라이선스 원문을 따른다. 펭귄 샘플은 CC0로 별도 관리하며 이전 28개 미검토 CSV는 새 배포물에 포함하지 않는다. 별도 제공 OJ Platform의 코드나 라이선스를 이 ZIP이 재배포·변경하지 않는다.

## 새로 수행한 검증

| 검사 | 결과 | 실제 환경·범위 |
| --- | --- | --- |
| WordPress/DB 핵심 통합 | 조합별 86 통과 | WP 6.6.2/PHP 8.1.34, WP 7.1/PHP 8.1.34, WP 7.1/PHP 8.3.33; MariaDB 11.4.10 |
| 실제 Platform 전체 부팅 | 조합별 47 통과 | dev.104; WP 6.6.2/PHP 8.1.34 및 WP 7.1/PHP 8.3.33 |
| RC6→1.0.0·전체 DB 복원·새 설치 | 54 통과 | 업그레이드·양방향 복원 49, 새 설치 5 |
| 실제 16MiB packet 경계 | 23 통과 | 혼합 셀·한글·메타데이터를 포함한 정확한 바이트 한도와 원본 보존 |
| 로컬 요약 SQL | 2,000회, 실패 0 | 노트북 100개·200,009,100바이트, 8 worker; p95 1.7087ms |
| Chromium | 13 통과 | Windows, 148.0.7778.96, 주석 추가 후 소스 직접 검사 |
| Firefox | 13 통과 | Windows, 153.0, 주석 전 입력과 주석 전용 차이 증거 연결 |
| Playwright WebKit | 13 통과 | Windows, 26.5; 실제 macOS/iOS Safari 검사 아님 |
| 로컬 회귀·패키지 | 각 실행 통과 | PHP 8.1.34/8.5.5: PHP 42, DB 읽기 실패 12, Kaggle fixture 21, JS 44, 패키지 32, 운영 사전점검 mock 57 |

WordPress 핵심 통합의 Context Gateway는 공개 계약 fixture이며, 실제 Platform 검사는 Kernel·설치·인증·역할·학급/모둠·Gateway를 실제 구현으로 부팅한다. 교사에게 배정된 학생의 메모 열람, 학생 이동, 권한 회수 후 저장 차단을 확인했다. 핵심 통합의 86개를 3배 또는 실제 Platform의 47개를 2배 해서 고유 검사 수로 표시하지 않는다.

복원 검사는 RC6에 이미 저장된 텍스트 셀의 종류·본문 해시, 기존 노트북·자료·업로드·암호화 설정이 업그레이드 및 변경 전후 전체 DB 복원에서 유지됨을 확인했다. 로컬 폐기 환경의 복원이며 운영 서버 백업 존재나 복구 권한을 증명하지 않는다. 새 설치는 penguins만 생성한다.

packet 검사에서 서버 한도는 16,777,216바이트, 유효 본문 상한은 8,355,840바이트, 최대 관찰 SQL 쓰기는 16,711,933바이트였다. 제한 초과·조회 실패 시 이전 노트북·revision·자료가 유지됐다. SQL 부하는 40ms 간격의 제한된 repository 호출이며 REST·인증·브라우저·프록시 비용과 운영 최대 처리량은 측정하지 않았다.

## 브라우저 검증과 소스 연결

세 엔진 모두 실제 Ace 1.44.0과 Pyodide 0.26.4 Worker를 사용해 Ctrl+Enter 실행, 한글 `input()`·입력 echo, 메모 추가 중 입력 보존, 무한 루프 중단·복구, 메모를 제외한 전체 실행, 혼합 본문·순서·출력 저장/재조회, 409 충돌·미저장 내용 다운로드·최신 내용 복구를 통과했다. 브라우저 저장은 loopback 메모리 REST fixture다. 위의 실제 WordPress/DB 검사와 별개이며 브라우저에서 운영 DB를 검증했다고 표시하지 않는다.

[Chromium 결과](../../_notes/oj-code-lab-stable-chromium-20260910.json)는 최종 SPDX·추가 허락 주석을 포함한 JS/CSS 5개 파일을 직접 검사했다. [Firefox 결과](../../_notes/oj-code-lab-stable-firefox-20260910.json)와 [WebKit 결과](../../_notes/oj-code-lab-stable-webkit-20260910.json)는 주석 전 입력을 사용했다. [주석 비교 증거](../../_notes/oj-code-lab-license-comment-comparison-20260910.json)는 새 주석만 제거하면 해당 48개 배포 파일이 변경 전 바이트와 같음을 입증한다. 따라서 이전 브라우저 보고서 해시를 새 해시로 바꾸지 않는다.

Firefox의 자동화 전용 `keyboard.insertText` 전체 선택 치환은 일반 키 이벤트와 동작이 달라, 실제 keydown/keypress/input 경로인 `keyboard.type`으로 검증했다. [진단 기록](../../_notes/oj-code-lab-stable-firefox-input-diagnostic-20260910.json)에 실패와 분리 검증을 보존했다. 실제 OS 한글 IME 조합이나 Apple Safari 앱의 수용으로 확대하지 않는다.

## 증거와 미실행 항목

기본 보고서는 [DB·플랫폼·복원·packet·SQL 통합](../../_notes/oj-code-lab-stable-validation-20260910.json), [PHP 8.1 로컬](../../_notes/oj-code-lab-stable-local-php81-20260910.json), [PHP 8.5 로컬](../../_notes/oj-code-lab-stable-local-php85-20260910.json)이다. 로컬 검증 당시 PHP 문법 57개·JavaScript 문법 14개·UTF-8 134개를 확인했다. 후속 문서 추가에 따라 파일 개수가 늘어날 수 있으므로 이 수치는 당시 보고서에만 귀속한다. 자신이 생성한 테스트 서버·DB·브라우저·임시 바이너리는 정리했다.

다음 범위는 실행하지 않았다.

- 실제 macOS/iOS Safari, 모바일 기기, OS IME·접근성 보조기기 조합.
- Kaggle 실계정의 보호 자료·계정별 한도·키 만료. HTTP fixture와 공개 무인증 계약 검토는 실계정 수용을 대신하지 않는다.
- 운영 서버의 최신 백업·복원 접근, 공개 오류 로그 조치, TLS·프록시·CDN 정책 및 실사용 트래픽 부하.
- 실제 학생 실행·저장을 포함한 전체 운영 인수·교사 흐름 재검수, WordPress.org 디렉터리 등록·심사.

마지막으로 확인한 운영 설치는 1.0.0/Platform dev.104다. [RC6 기록](ACE_TEXT_CELLS_2026-09-10.md)은 당시 이력으로 보존한다. 이전 [RC4 검증](VALIDATION.md) 및 JSON 본체의 NO-GO·라이선스 보류는 당시 이력이다. 최신 직접 ZIP 배포 기준에는 [1.0.0 정책](RELEASE_POLICY_1_0.md)을 적용한다.

## 1.0.0 운영 설치의 별도 확인

2026-09-10 21:40(+09:00) WordPress 관리자 UI에서 **1.0.0 설치·활성화**를 확인했다. Platform은 dev.104이며 새 실습실 탭에서 기존 8셀(코드 7·메모 1)의 ID·종류·본문·출력이 같았다. 코드·텍스트 추가 위치는 각각 9개이고 Ace chrome/16px가 계정 설정과 일치했다. 기존 편집 탭을 유지했으며 운영 노트북 실행·편집·저장은 하지 않았다. 전체 운영 인수와 교사 흐름 재검수는 별도 미완료다. [운영 설치 증거](../../_notes/oj-code-lab-stable-production-20260910.json)를 참조한다.

운영 서버 파일별 재해시, 운영 백업·실사용 부하, 교사 전체 흐름의 재검수는 수행하지 않았다. 이 설치 기록은 로컬 검증의 범위를 확대하지 않는다.
