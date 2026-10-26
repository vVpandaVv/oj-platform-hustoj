# OJ Platform · HUSTOJ

WordPress 기반 프로그래밍 수업 플랫폼입니다.

## 폴더

| 경로 | 용도 |
| --- | --- |
| [source/](source/) | 플랫폼·애드온별 코드 |
| [_releases/](_releases/) | 플러그인별 배포 ZIP |
| [_release-kit/guides/](_release-kit/guides/) | 설치 안내 |
| [docs/](docs/) | 개발 문서 |

## 설치 환경

Ubuntu 24.04 LTS · HUSTOJ 공식 최신 배포 · WordPress 6.6 이상 · PHP 8.3 이상

새 서버는 [자동 설치 스크립트](_release-kit/install/ubuntu24.sh)로 HUSTOJ·WordPress·OJ Platform을 한 번에 설치합니다. 명령은 [서버 준비](_release-kit/guides/_server.md)를 참고합니다.

1. [Ubuntu 서버 준비](_release-kit/guides/_server.md)
2. [OJ Platform 설치](_release-kit/guides/oj-platform.md)
3. 플러그인별 ZIP을 WordPress 관리자에서 설치합니다.

배포 ZIP 경로: `_releases/<플러그인>/<버전>/<파일>.zip`
