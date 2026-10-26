# OJ Platform — HUSTOJ 기반 개발 소스

WordPress에서 프로그래밍 수업을 운영하기 위한 OJ Platform과 애드온의 개발 소스 모음입니다. 계정·문제·제출·수업·대회·학습 지원은 플랫폼과 애드온이 담당하며, 현재 코드 실행과 채점은 HUSTOJ에 연결합니다.

공개 저장소: [vVpandaVv/oj-platform-hustoj](https://github.com/vVpandaVv/oj-platform-hustoj). 첫 공개 범위는 플랫폼과 모든 애드온의 개발 소스입니다. 교재·문제 콘텐츠, 교사용 정답, 운영 데이터와 기존 비공개 Git 이력은 포함하지 않습니다.

이 공유 기준의 플랫폼 버전은 **2.5.1**, 공통 Addon API는 **1.8.0**입니다. 버전의 기준은 [플랫폼 메인 파일](oj-integration-rebuild/oj-platform/oj-platform.php)입니다. **Judge0 전환은 후속 개발 대상이며, 이 저장소를 Judge0 지원 완료 버전으로 안내하지 않습니다.**

## 구성

| 구성 요소 | 소스 경로 | 역할 |
| --- | --- | --- |
| OJ Platform | [`oj-integration-rebuild/oj-platform/`](oj-integration-rebuild/oj-platform/) | 계정·권한·문제·제출·채점 연동·수업·그룹·대회·학습 지원 |
| Edu Games | [`edu-games/`](edu-games/) | 학습 게임과 게임 활동 |
| Learning Materials | [`learning-materials/`](learning-materials/) | 학습자료 관리와 제공 |
| OJ AI Dashboard | [`oj-ai-dashboard/`](oj-ai-dashboard/) | AI 활동지·평가 기능 |
| OJ Code Arena | [`oj-code-arena/`](oj-code-arena/) | 실시간 코딩 경기 |
| OJ Code Lab | [`oj-code-lab/`](oj-code-lab/) | 코딩 학습·실습 기능 |
| OJ ML Playgrounds | [`oj-ml-playgrounds/`](oj-ml-playgrounds/) | 머신러닝 실습 |
| ReadContents Library | [`readcontents-library/`](readcontents-library/) | 읽기자료 편집과 학습 |
| Teaching Tools | [`teaching-tools/`](teaching-tools/) | 실시간 교실 도구 |
| Focus Tracker | [`focus-tracker/`](focus-tracker/) | 학습 집중 활동 기록 |
| OJ PRIMM | [`oj-primm/`](oj-primm/) | PRIMM 수업 자료·단계 활동·실행 검증 |
| OJ Research | [`oj-integration-rebuild/oj-research/`](oj-integration-rebuild/oj-research/) | 수업 연구·집단별 정책·성취 분석 확장 |

애드온은 필요한 것만 설치합니다. OJ Research는 별도 플러그인 소스이며, 현재 릴리스 킷의 등록 대상과 동일한 의미가 아닙니다. 실제 패키징 대상과 의존 버전은 [`_release-kit/plugins.json`](_release-kit/plugins.json)을 확인합니다.

공통 실시간 서버 소스는 [`_shared/oj-realtime-hub/`](_shared/oj-realtime-hub/)에 있습니다. 각 플러그인의 테스트·개발 도구와 외부 구성 요소 고지도 해당 소스에 함께 보관합니다.

## 실행 환경

- WordPress **6.6 이상**, PHP **8.3 이상**이 플랫폼의 최소 조건입니다.
- WordPress 및 플랫폼 저장소용 MySQL/MariaDB와 **HUSTOJ 채점 환경**이 필요합니다. 현재 플랫폼은 HUSTOJ 형식의 문제·제출·결과 테이블 및 테스트데이터 경로와 연동합니다.
- HUSTOJ, WordPress, 데이터베이스, 언어별 컴파일러·런타임은 별도로 준비하는 외부 의존성입니다. 이 소스 모음만 내려받아 전체 서버가 설치되지는 않습니다.
- AI 기능은 별도로 설정한 제공자 연결이 필요합니다. Code Arena·Teaching Tools의 실시간 기능은 공통 서버 런타임이 필요합니다. 과학 라이브러리·그림 채점은 추가 실행 환경을 확인합니다.

필요한 확장·서버 설정·저장소 권한은 [서버 준비 원본](_release-kit/guides/_server.md), [설치 개요 원본](_release-kit/guides/_overview.md), [OJ Platform 안내 원본](_release-kit/guides/oj-platform.md)을 따릅니다. 애드온별 안내 원본은 [`_release-kit/guides/`](_release-kit/guides/)에 있습니다.

## 설치 패키지와 개발 소스

**GitHub의 `Code → Download ZIP`으로 받은 저장소 전체를 WordPress 플러그인 업로드 화면에 올리지 않습니다.** 저장소에는 여러 플러그인·공통 소스·개발 도구가 들어 있습니다. 설치에는 릴리스 킷이 만든 플러그인별 ZIP을 사용합니다.

Windows PowerShell에서 저장소 루트를 기준으로 실행합니다.

```powershell
.\_release-kit\release.cmd content
.\_release-kit\release.cmd check
.\_release-kit\release.cmd package
.\_release-kit\release.cmd docs
```

공개 소스 스냅샷의 `readcontents-content/catalog.json`은 콘텐츠를 포함하지 않는 빈 목록입니다. 첫 `content` 명령은 검사에 필요한 콘텐츠 인덱스를 생성합니다. 원래 개발 환경의 외부 교재 경로를 복제한 것으로 해석하지 않습니다.

패키지 생성·문서 생성·검사를 함께 실행하려면 다음 명령을 사용합니다.

```powershell
.\_release-kit\release.cmd all
```

명령의 요구사항과 플러그인별 빌드 조건은 [릴리스 킷 설명](_release-kit/README.md)을 먼저 확인합니다. OJ Platform 정식 패키지는 현재 커밋을 가리키는 `oj-platform-v<버전>` 태그 등 공식 빌더의 조건을 충족해야 합니다.

버전별 원본 패키지는 `_releases/<플러그인>/<버전>/`, 전달용 묶음과 설치 안내서는 `_releases/latest/`에 생성됩니다. **이미 릴리스한 버전은 덮어쓰지 않습니다.** 설치 ZIP과 설치 안내서를 개별 압축 명령이나 플러그인의 하위 빌더로 직접 만들지 않습니다. 안내서 내용은 `_release-kit/guides/`에서 수정하며, 생성물은 직접 편집하지 않습니다.

## 개발과 검증

작업 전에 [AGENT.md](AGENT.md), [OJ Build Harness](OJ-BUILD-HARNESS.md), [릴리스 킷 설명](_release-kit/README.md)을 읽습니다. 모든 텍스트 파일은 UTF-8(BOM 없음)으로 저장합니다.

플랫폼 테스트의 진입점은 다음과 같습니다. PHP 실행 파일이 PATH에 있어야 하며, 구성 요소별 추가 도구는 각 테스트·빌드 문서를 확인합니다.

```powershell
php .\oj-integration-rebuild\oj-platform\tools\run-tests.php --release
.\_release-kit\release.cmd check
```

애드온의 테스트는 각 폴더의 README와 검증 스크립트를 따릅니다. 정책 검사 통과, 로컬 단위·계약 테스트 통과, 실제 Linux 채점기 연동, 동시 접속 시험은 서로 다른 검증입니다. 이 README는 모든 기능·언어·운영 환경의 인수가 완료되었다는 선언이 아닙니다. 변경에 맞는 테스트를 실행하고 실제 실행한 범위와 남은 확인 사항을 함께 기록합니다.

## 공개 저장소 관리

현재 HUSTOJ 기반 버전의 기준선 보존, 유지보수 브랜치, 향후 Judge0 별도 저장소, 공개 전 점검 방법은 [GitHub 관리 방침](docs/github-management.md)을 참고합니다.

비공개 개발 폴더에서 공개 후보를 만들 때는 `python _release-kit/export-public-source.py`로 포함 목록을 먼저 확인하고, 검토 후 `--write --output <새로운 빈 폴더>`를 사용합니다. 이 도구는 소스만 복사하며 설치 ZIP이나 설치 안내서를 만들지 않습니다. 자세한 선택 규칙은 [`_release-kit/public-source.json`](_release-kit/public-source.json), 실제 공개 파일의 해시는 `SOURCE-SNAPSHOT.json`에 기록합니다.

공개 저장소에는 개발 소스와 필요한 개발·검증 자료를 보관합니다. 운영 설정, 비밀키, 데이터베이스 덤프, 학생·교사·연구 참여자의 실제 자료, 제출 이력과 운영 로그는 포함하지 않습니다. 문제·교재·이미지 등 콘텐츠는 코드와 별도로 공개 권한을 확인합니다.

AI Dashboard의 문서는 검토한 설계 자료만 공개하고 구현 일지·실서버 인수 보고서·실제 화면 캡처는 제외합니다. Code Lab의 운영 인수 기록도 제외하며 [`release-gates.example.json`](oj-code-lab/docs/release-gates.example.json)을 제공합니다. Code Lab 정식 패키지를 새로 만들 때는 예제를 로컬 `docs/release-gates.json`으로 복사한 뒤 해당 환경에서 실제로 확인한 근거를 작성해야 합니다. 예제의 보류 상태를 통과로 바꾸는 것만으로 검증을 대신하지 않습니다.

## 라이선스와 외부 고지

OJ Platform 자체 코드의 라이선스는 **GPL-3.0-or-later**이며, [LICENSE.txt](oj-integration-rebuild/oj-platform/LICENSE.txt)와 [THIRD_PARTY_NOTICES.txt](oj-integration-rebuild/oj-platform/THIRD_PARTY_NOTICES.txt)를 확인합니다. 저장소 전체의 모든 구성 요소에 동일한 라이선스를 새로 부여하는 것은 아닙니다.

별도 라이선스 파일이 있는 애드온은 해당 파일과 메인 플러그인 헤더를 함께 확인합니다.

- [OJ PRIMM](oj-primm/LICENSE.txt)
- [OJ AI Dashboard](oj-ai-dashboard/LICENSE.txt)
- [OJ Code Arena](oj-code-arena/LICENSE.txt)
- [OJ Code Lab](oj-code-lab/LICENSE.txt)
- [OJ ML Playgrounds](oj-ml-playgrounds/LICENSE.txt)

다른 애드온과 OJ Research의 이용 조건은 해당 파일·고지에 명시된 범위를 확인합니다. 외부 라이브러리·글꼴·아이콘·콘텐츠의 원래 라이선스와 저작권 고지는 유지합니다. 공개 전 라이선스 표기가 부족한 구성 요소는 별도로 확인하며, 플랫폼의 라이선스를 임의로 적용하지 않습니다.
