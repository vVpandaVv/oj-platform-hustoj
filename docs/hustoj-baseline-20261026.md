# HUSTOJ 개발 소스 공개 기준선 — 2026-10-26

공개 저장소는 [vVpandaVv/oj-platform-hustoj](https://github.com/vVpandaVv/oj-platform-hustoj)이며, 기준선 태그는 `hustoj-baseline-20261026`입니다. 현재 작업 파일을 포함한 개발 소스 스냅샷입니다. 기존 비공개 Git 이력은 원래 개발 저장소에 보존합니다.

## 포함한 버전

| 구성 요소 | 메인 파일의 버전 |
| --- | --- |
| OJ Platform | 2.5.1 |
| Edu Games | 1.6.7 |
| Learning Materials | 1.1.2 |
| OJ AI Dashboard | 1.2.15 |
| OJ Code Arena | 1.3.4 |
| OJ Code Lab | 1.4.0 |
| OJ ML Playgrounds | 1.4.2 |
| ReadContents Library | 3.3.10 |
| Teaching Tools | 1.2.8 |
| Focus Tracker | 1.0.1 |
| OJ PRIMM | 1.0.5 |
| OJ Research | 1.0.0 |

Addon API는 1.8.0입니다. 각 파일의 버전 표시는 공개 시점의 개발 소스 식별자이며, 현재 작업 파일 전체에 대응하는 설치 ZIP이 이미 만들어졌다는 의미는 아닙니다. 기존 릴리스 패키지의 버전을 덮어쓰거나 파일을 바꾸지 않습니다. OJ Research는 소스를 포함하지만 현재 공식 릴리스 킷 목록에는 등록되어 있지 않습니다.

## 공개 범위

플랫폼과 애드온의 소스, 테스트, 빌드 도구, 필요한 편집기 원본, 브라우저 런타임 자산과 외부 고지를 포함합니다. 공개 파일의 크기와 SHA-256은 저장소 루트 `SOURCE-SNAPSHOT.json`에 기록합니다.

교재·문제 콘텐츠와 교사용 정답, 운영 설정·로그·실제 학습자와 연구 참여자의 데이터, 서버 운영 증빙, 의존성 캐시, 과거 배포 묶음은 포함하지 않습니다. 일부 과거 문서의 운영 증빙 링크는 공개 저장소에서 제공하지 않습니다. 현재 API·스키마를 검사하는 합성 fixture는 유지합니다.

콘텐츠 카탈로그는 빈 목록으로 공개하며, 첫 검사 전에 다음 명령으로 로컬 콘텐츠 인덱스를 생성합니다.

```powershell
.\_release-kit\release.cmd content
.\_release-kit\release.cmd check
```

## 검증 범위와 후속 개발

원래 개발 저장소의 `release.cmd check`는 공개 준비 과정에서 통과했습니다. 이 검사는 버전·인코딩·고지·출력 위치와 관련 정책 검사이며, 모든 언어·채점 방식·동시 부하·운영 서버의 인수를 대신하지 않습니다. 공개 스냅샷의 검사는 내보낸 폴더에서도 별도로 실행합니다.

GitHub의 자동 생성 Source code ZIP은 여러 플러그인을 포함한 개발 소스입니다. WordPress에 설치하는 플러그인별 ZIP은 `_release-kit/release.cmd`로 생성해야 합니다. 이번 기준선은 설치 패키지를 새로 배포하는 정식 제품 릴리스로 표시하지 않습니다.

HUSTOJ 유지보수 브랜치는 `codex/hustoj-maintenance`로 관리합니다. Judge0 전환은 이후 `oj-platform-judge0` 별도 저장소에서 진행할 계획이며, 이 기준선의 태그와 소스는 그대로 보존합니다. 자세한 절차는 [GitHub 관리 방침](github-management.md)을 따릅니다.
