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

새 Ubuntu 24.04 서버에서 [설치 스크립트](install.sh)를 실행합니다.

```bash
curl -fL https://github.com/vVpandaVv/oj-platform-hustoj/raw/main/install.sh -o install.sh && bash install.sh
```

기본값은 서버 IP의 HTTP 주소와 테스트 이메일입니다.

| 주요 옵션 | 기본값 |
| --- | --- |
| `--site-url URL` | 서버 IP의 HTTP 주소 |
| `--admin-email EMAIL` | `admin@example.test` (HTTPS는 실제 연락처 입력) |
| `--admin-user NAME` | `ojadmin` |
| `--title "이름"` | `OJ Platform` |
| `--plugin-zip PATH` | 동봉된 최신 OJ Platform ZIP |

HUSTOJ·Docker 채점 환경, nginx·MariaDB·PHP 8.3 확장, 한국어 WordPress·WP-CLI·OJ Platform, 컴파일·실행·SPJ 도구를 설치합니다. HTTPS 주소를 지정하면 인증서와 자동갱신을 설정합니다.

HUSTOJ 설치 출력과 주기적인 실행 상태를 화면에서 확인할 수 있습니다. [서버 준비](_release-kit/guides/_server.md)에 전체 설치 목록, 옵션, 로그 확인과 재개 명령이 있습니다.

1. [Ubuntu 서버 준비](_release-kit/guides/_server.md)
2. [OJ Platform 설치](_release-kit/guides/oj-platform.md)
3. 플러그인별 ZIP을 WordPress 관리자에서 설치합니다.

배포 ZIP 경로: `_releases/<플러그인>/<버전>/<파일>.zip`
