# 최신 배포 세트 (자동 생성 폴더)

이 폴더는 `_release-kit\release.cmd`가 매번 다시 만듭니다. **여기 있는 파일을 직접 수정하지 마세요.**

| 경로 | 내용 |
|---|---|
| `packages/` | 각 플러그인의 현재 버전 설치 ZIP과 `.sha256` (먼저 설치할 OJ Platform 포함) |
| `content/` | 모든 작업 콘텐츠의 최신 배포 자료. 주제별 ZIP·JSON·문제 XML 묶음과 검토 기록. 먼저 `content/README.md`에서 용도를 확인하세요 |
| `install-guides/` | 설치 안내서 (`00-overview.md`부터 순서대로, 전체 묶음은 `install-guide.html`) |
| `SHA256SUMS.txt` | 모든 패키지의 체크섬 |
| `release-set.json` | 이 세트에 포함된 버전 목록 |

버전별 원본 패키지는 `_releases/<플러그인>/<버전>/`에 영구 보관됩니다.

콘텐츠는 `readcontents-content/catalog.json`으로 관리합니다. 원고를 빌드·검증한 뒤 `_release-kit\release.cmd content`로 이 폴더의 최신 자료를 갱신합니다. 이 명령은 플러그인 설치 ZIP을 다시 만들지 않습니다. `content` 안의 파일은 플러그인 업로드 화면에 올리지 마세요.
