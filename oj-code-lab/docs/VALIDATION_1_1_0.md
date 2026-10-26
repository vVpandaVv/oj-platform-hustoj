# 1.1.0 데이터 형식 변경 검증 안내

대상: **OJ Code Lab 1.1.0 단독 직접 배포 ZIP** · 기록일: **2026-09-14**.

**1.1.0 단독 직접 ZIP 배포: GO.** [배포 게이트](release-gates.json)의 R1–R7과 [최종 릴리즈 검토](../../_notes/oj-code-lab-formats-release-review-20260914.json)를 충족했다. 최종 ZIP은 생성·검증했으며 운영 사이트의 플러그인 교체는 아래 조건으로 대기 중이다.

## 최종 배포물과 운영 설치 상태

- [OJ Code Lab 1.1.0 ZIP](../../_releases/oj-code-lab/1.1.0/oj-code-lab-1.1.0.zip): **62개 파일, 139,587바이트**.
- SHA-256: `935944c79a89746cdb58e15fcb6878c05463808b58d214827a9ae9d86ba04400`.
- 두 차례 빌드 결과의 ZIP 바이트와 해시가 동일하다. 최종 ZIP의 62개 파일은 배포 대상 소스와 일치하며 PHP·JS·CSS 실행 파일 50개는 통합 검증 입력과 일치한다. 브라우저 검사 대상 자산도 해당 입력 해시와 일치한다. 기존 1.0.0 릴리즈는 변경하지 않았다.
- [공개 검증 보고서](../../_releases/oj-code-lab/1.1.0/VALIDATION.json), [파일별 manifest](../../_releases/oj-code-lab/1.1.0/oj-code-lab-1.1.0.manifest.json), [릴리즈 노트](../../_releases/oj-code-lab/1.1.0/RELEASE-NOTES.md)를 함께 제공한다.

**운영 설치는 미완료다.** WordPress 관리자 화면에서 현재 설치 버전 **1.0.0**과 업로드된 **1.1.0**을 확인했으나 교체는 실행되지 않았다. 자동 승인 검토가 운영 서버의 전체 DB·파일 백업 완료 증거 부족을 이유로 교체 작업을 두 차례 거절했다. 해당 백업 증거를 확인하는 조건으로 설치를 대기하며, 업로드를 설치 완료로 표시하지 않는다. 정확한 상태와 이후 확인 항목은 [운영 설치 기록](../../_notes/oj-code-lab-formats-production-20260914.json)을 따른다.

## 1.1.0 변경 범위

- Kaggle·URL·교사 업로드에 JSONL·NDJSON과 일반 ZIP 읽기를 추가한다. UTF-8/CP949 정규화, 파일 상한, JSONL 한 줄 1MiB 및 JSON 파서 깊이 한도 64를 적용한다.
- PHP `ZipArchive`로 요청한 내부 경로가 정확히 일치하는 지원 파일을 우선 선택한다. 없으면 지원 파일이 하나인 경우만 자동 선택한다. 다중 파일은 원본 파일 선택 또는 압축을 풀어 업로드하도록 안내한다. 경로·중복·손상, 항목 수·압축률·크기를 검사하며 중첩 압축·ZIP64·분할·암호화·Store/Deflate 이외 압축은 지원하지 않는다.
- JSONL·NDJSON은 `pd.read_json(..., lines=True)`, CSV는 `pd.read_csv(...)`로 읽는다. `print(df.head())`를 포함한 새 셀을 자동 실행하여 미리보기를 출력하고 기존 코드·메모·출력을 보존한다.
- 교사의 ‘파일 선택’과 ‘첫 지원 파일 추가’를 구분하고 실제 선택 파일명을 저장한다. 원격 캐시는 정규화한 내용·실제 파일명·형식을 v2 키로 유지하며 기존 캐시는 자연 만료한다. 파일 내용이 1MiB와 적용 파일 상한의 1/4 중 작은 값을 넘으면 메모리 보호를 위해 캐시를 생략하고 가져오기는 정상 진행한다.

DB 스키마 2, 노트북 형식 2, 기존 CSV·Ace·텍스트 셀과 권한 경계는 유지한다. OJ Platform 코드는 이번 릴리즈에서 수정하지 않으며 기존 공개 Editor API와 Ace 자산을 사용한다. 자체 코드의 GPL-2.0-or-later 및 플랫폼 연동 추가 허락을 유지하고 외부 라이브러리 번들을 추가하지 않는다. 설치 요구 사항과 사용자 안내는 [README](../README.md), [SUPPORT](SUPPORT.md)를 따른다.

## 1.1.0 증거 위치와 해석

| 검증 범위 | 보고서 | 경계 |
| --- | --- | --- |
| PHP 8.1 로컬 정적·회귀·패키지 검사 | [PHP 8.1 보고서](../../_notes/oj-code-lab-formats-local-php81-20260914.json) | PHP 8.1.34의 CSV/JSONL·ZIP·HTTP·캐시·JS·배포 회귀. 실제 WordPress/DB 검사와 분리한다. |
| PHP 8.5 로컬 정적·회귀·패키지 검사 | [PHP 8.5 보고서](../../_notes/oj-code-lab-formats-local-php85-20260914.json) | PHP 8.5.5에서 같은 검사군을 실행한 환경별 증거다. 반복 수를 고유 검사 수에 합산하지 않는다. |
| 새 데이터 가져오기 브라우저 수용 | [데이터 브라우저 보고서](../../_notes/oj-code-lab-formats-browser-20260914.json) | Windows Chromium의 실제 Ace·Pyodide·pandas를 사용하는 새 데이터 시나리오 13개. REST는 loopback 메모리 fixture이며 WordPress·DB·실제 Kaggle·운영 사이트 접근은 포함하지 않는다. |
| 기존 편집기 브라우저 회귀 | [편집기 브라우저 보고서](../../_notes/oj-code-lab-formats-editor-browser-20260914.json) | 같은 실행 자산의 기존 편집기 시나리오 13개를 별도로 연결한다. 새 데이터 시나리오나 과거 1.0.0의 검사로 합산하지 않는다. |
| 실제 WordPress·DB 통합 | [통합 보고서](../../_notes/oj-code-lab-formats-integration-20260914.json) | PHP 8.1.34·MariaDB 11.4.10에서 WordPress 6.6.2·7.1 각각 99개 통과. Platform Gateway는 dev.99 fixture이며 실제 플랫폼 검사와 분리한다. |
| 실제 OJ Platform 통합 | [통합 보고서의 actual_platform](../../_notes/oj-code-lab-formats-integration-20260914.json) | OJ Platform 2.0.3·WordPress 7.1·PHP 8.1.34에서 47개 통과. 실제 인증·역할·학급·모둠·권한 서비스를 사용한다. |
| 1.0.0→1.1.0 업데이트·전체 DB 복원·새 설치 | [통합 보고서의 operations_rehearsal](../../_notes/oj-code-lab-formats-integration-20260914.json) | 실제 ZIP·MariaDB 덤프의 업데이트 및 양방향 복원 49개와 새 설치 5개, 총 54개 통과. WordPress 7.1·PHP 8.1.34·Platform Gateway fixture를 사용한다. |
| 최종 배포물과 입력 바이트 일치 | [최종 릴리즈 검토](../../_notes/oj-code-lab-formats-release-review-20260914.json)·[공개 검증 보고서](../../_releases/oj-code-lab/1.1.0/VALIDATION.json) | 최종 62파일 ZIP의 소스 일치, 실행 파일 50개의 통합 검증 입력 일치, 브라우저 대상 자산 일치와 두 빌드의 바이트·해시 재현성을 확인했다. |

완료된 통합 검증은 비공개 로컬 환경에서 수행했으며 PHP·JS·CSS 실행 파일 50개의 SHA-256이 검사 전후 동일하다. 최종 배포 ZIP과의 일치는 위 최종 릴리즈 검토에 기록했다. WordPress 통합과 실제 플랫폼 검사의 PHP `memory_limit`는 **512M**, 업데이트·복원 검사는 **256M**이었다. 이 기능 검사는 운영 서버의 **128M 제한에서 최대 크기 데이터**를 처리하는 스트레스 검증을 포함하지 않는다. 보호 자료에 접근하는 Kaggle 실계정 검증도 수행하지 않았다. 외부 공급자 응답은 fixture이며 브라우저 cookie 로그인·프록시·운영 설치는 이 통합 검사의 범위가 아니다.

새 데이터 브라우저 검사의 ZIP 응답은 이미 정규화된 fixture다. 실제 ZIP 압축 해제와 비정상 ZIP 경계는 별도 PHP 검사로 구분한다. 검사에 사용한 실행 파일 해시와 최종 ZIP의 일치는 해당 보고서 및 최종 배포 증거를 기준으로 판단한다. Chromium 검사를 Firefox·WebKit·실제 Apple Safari 또는 모바일 기기 수용으로 확대하지 않는다.

## 이전 버전 및 운영 기록과 구분

[1.0.0 검증 기록](VALIDATION_1_0_0.md), [당시 게이트](history/1.0.0-release-gates.json), 기존 검증·준비 상태·체크리스트의 1.0.0/RC 본문은 해당 시점의 이력이다. 그 안의 GO, 통과 개수, ZIP 해시와 운영 설치 완료 표시는 1.1.0의 결과로 사용하지 않는다. [validation-results.json](validation-results.json) 역시 기존 기록을 유지하며 이 문서의 새 증거를 대체하지 않는다.

운영 설치·실제 학생 실행·저장·교사 흐름·백업·복원 확인은 로컬 검증 및 직접 ZIP 배포 판단과 구분하여 해당 사이트의 별도 증거로 기록한다. Kaggle 실계정·보호 자료·계정별 한도와 WordPress.org 등록·심사는 로컬 fixture 검사에서 확인한 것으로 표시하지 않는다.
