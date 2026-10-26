# 릴리스 킷: 패키징·설치 문서 정책

이 폴더는 아래 플러그인의 **설치 패키지 생성**과 **설치 안내서 생성**을 한 곳에서 처리하는 기준점입니다. 어떤 편집기, 어떤 PC, 어떤 AI 에이전트로 작업하든 패키징과 설치 문서는 이 킷으로만 만듭니다.

대상: `oj-platform`, `edu-games`, `learning-materials`, `oj-ai-dashboard`, `oj-code-arena`, `oj-code-lab`, `oj-ml-playgrounds`, `readcontents-library`, `teaching-tools`, `focus-tracker`, `oj-primm`

## 사용법

`_release-kit\release.cmd`를 실행합니다(탐색기 더블클릭, cmd, PowerShell 모두 가능).

| 명령 | 하는 일 |
|---|---|
| `release.cmd status` | 플러그인별 현재 버전과 패키지 생성 여부 |
| `release.cmd package` | 아직 없는 버전만 패키징하고 `latest` 갱신 |
| `release.cmd package -Plugin edu-games,teaching-tools` | 지정한 플러그인만 패키징 |
| `release.cmd docs` | 설치 안내서 전체 재생성 |
| `release.cmd content` | 등록된 콘텐츠 전체를 `latest/content`에 갱신하고 안내서 재생성. 플러그인 설치 ZIP은 유지 |
| `release.cmd all` | package + docs + check 한 번에 |
| `release.cmd check` | 정책 검사(버전 일치, 인코딩, 체크섬, 출력 위치). 실패하면 종료코드 1 |
| `release.cmd migrate` | 규칙 밖 위치에 생긴 패키지를 찾아 보여줌. `-Apply`로 실제 이동 |

PHP, Python, Node는 PATH에서 찾고, 없으면 `PHP_BINARY`, `PYTHON_BINARY`, `NODE_BINARY` 환경변수나 `-Php`, `-Python`, `-Node` 인자를 씁니다. PHP는 `D:\php\php.exe`도 자동으로 찾습니다.

## 출력 위치 (고정)

```
_releases/
  <플러그인>/<버전>/        버전별 원본 패키지 (zip, .sha256, manifest). 한 번 만든 파일은 덮어쓰지 않음
  latest/                  배포용 묶음. 실행할 때마다 다시 만들어짐
    packages/              현재 버전 설치 ZIP 전부
    install-guides/        설치 안내서 (md + install-guide.html)
    content/               모든 등록 콘텐츠의 최신 파일 (readcontents-content/catalog.json)
      README.md            주제별 파일과 가져오기 방법
      content-index.json   상태·편집 위치·파일별 SHA-256
      <콘텐츠 ID>/         교재·문제 묶음·검토 기록
    SHA256SUMS.txt
    release-set.json
```

다른 사람에게 전달할 때는 `_releases/latest/` 폴더만 넘기면 됩니다.

## 콘텐츠 관리

`readcontents-content/catalog.json`을 전체 콘텐츠 배포 목록으로 사용합니다. `plugins.json`의 `readcontents-library.contentCatalog`가 이 목록을 가리킵니다. 기존 `contentFiles` 배열도 다른 플러그인과의 호환성을 위해 지원합니다.

원고는 목록의 `editingRoot`에서 직접 수정합니다. 해당 프로젝트의 빌드·검증이 끝난 뒤 `release.cmd content`와 `release.cmd check`를 실행합니다. 원고를 저장하는 것만으로 결과가 자동 갱신되지는 않습니다. **최종 확인 경로는 항상 `_releases/latest/content/`**이며 오래된 부별 ZIP, preview, 개정 전 백업을 새 배포 자료와 섞지 않습니다.

목록의 `sourceRoot`는 플러그인 저장소 기준 상대 경로이며 형제 콘텐츠 프로젝트도 연결할 수 있습니다. `files`에는 명시적으로 배포할 파일만 등록합니다. 문자열은 원래 상대 경로로 복사하고, `{ "source": "...", "target": "..." }`는 배포 경로를 지정합니다. 대상은 `<콘텐츠 ID>/` 안으로 제한됩니다. 기획 단계의 프로젝트는 빈 `files`와 현재 상태를 기록합니다. 초안이나 기존 버전의 검증 범위를 새로 검수 완료한 것으로 표시하지 않습니다.

갱신 전 모든 등록 파일의 존재와 UTF-8을 확인하고, 임시 폴더에서 복사본 SHA-256을 검증한 다음 콘텐츠 폴더를 교체합니다. 파일 누락은 경고 후 무시하지 않고 실패시킵니다. `check`는 원본과 최신 사본, 목록, 체크섬을 대조하여 미갱신·손상·미등록 파일을 찾습니다. 경로 탈출, 중복 대상, `latest` 자체를 원본으로 사용하는 설정도 거부합니다. 관리 도구 회귀 검사는 `powershell -NoProfile -ExecutionPolicy Bypass -File _release-kit/tests/content.ps1`입니다.

## 정책

`check-addon-notices.ps1`는 필수 외부 고지·편집기 고지 재고와 서버 전달 목록을 검사하며 package/check에서 실행됩니다. 편집기 원문 고지는 `collect-addon-notices.ps1`로 수집하고 `-Check`로 공급원과 대조할 수 있습니다. 재고는 복구된 의존성 트리의 보수적 목록이며 과거 번들의 정확한 빌드 재현이나 전체 라이선스 적합성을 인증하지 않습니다.

1. **패키지 출력은 `_releases/<플러그인>/<버전>/` 한 곳뿐입니다.** 플러그인 내부 `dist/`나 `_releases/` 바로 아래에 ZIP을 두지 않습니다. 플러그인 자체 빌드 스크립트를 직접 실행했다면 `release.cmd migrate -Apply`로 옮깁니다.
2. **릴리스된 버전은 불변입니다.** 같은 버전을 다시 만들지 않습니다. 고쳐야 하면 버전을 올립니다.
3. **버전은 플러그인 메인 파일 헤더(`Version:`)가 유일한 기준입니다.** `plugin-package.php`의 version은 헤더와 같아야 하며, 다르면 패키징이 거부됩니다. 설치 문서의 버전·요구사항·파일명·체크섬은 자동으로 채워지므로 손으로 적지 않습니다.
4. **설치 안내서는 `_release-kit/guides/`에서만 편집합니다.** `_releases/latest/install-guides/`는 생성물이라 수정해도 다음 실행 때 지워집니다.
   - `_overview.md`: 전체 개요와 공통 설치 방법
   - `_plugin-header.md`, `_plugin-footer.md`: 모든 플러그인 문서에 공통으로 붙는 머리말(버전표)과 점검표
   - `<플러그인>.md`: 플러그인별 본문. 버전 숫자 대신 `{{version}}`, `{{package}}`, `{{requires_php}}`, `{{platform_requires}}` 같은 자리표시자를 씁니다.
5. **플러그인 기능, 설정, 삭제 동작이 바뀌면 같은 작업에서 해당 `guides/<플러그인>.md`도 고칩니다.**
   - 설치의 기본 전제는 **WordPress 관리자 화면에서 ZIP 업로드**입니다. 플러그인 안내서는 짧게 씁니다: 서버 준비(단계 번호만) → 업로드 → 관리자 화면 설정 → 삭제 시.
   - 서버 작업은 모두 `guides/_server.md`의 **번호 매긴 순서 단계**로 씁니다. 각 단계는 필요한 플러그인, 바꾸는 파일, 명령, 다시 불러오기(재시작할 프로그램), 확인 항목을 갖춥니다. 숨은 도우미 스크립트나 `sed` 자동 편집을 쓰지 않고, 파일 수정은 `sudo vi 파일경로`로 열어 **어디를 찾아(`/검색어`) 무엇을 추가·수정하는지** 적습니다. 경로는 HUSTOJ 기준 실제 경로(웹 루트 `/home/judge/src/web`, PHP 8.3)를 쓰고 `$DOMAIN` 같은 변수를 쓰지 않습니다. "관리자에게 요청" 대신 실제 해결 명령을 씁니다. 단계를 추가하거나 번호를 바꾸면 `_overview.md`의 단계 표도 같이 고칩니다.
6. **새 플러그인 추가**는 `plugins.json`에 항목을 넣고 `guides/<id>.md`를 만듭니다. 전용 빌드 스크립트가 없으면 `"type": "generic"`과 `include` 목록을 씁니다.
7. **인코딩**: 모든 텍스트는 UTF-8(BOM 없음)입니다(루트 `AGENT.md` 1항). 킷의 `.ps1`/`.cmd`는 ASCII만 씁니다. Windows PowerShell 5.1이 BOM 없는 스크립트를 ANSI로 읽기 때문입니다. 한글 문자열은 `templates/strings.json`이나 `.md` 파일에 둡니다.
8. 커밋이나 배포 전에 `release.cmd check`가 통과해야 합니다.
9. **애드온 UI 규칙 검사(D-010 P4)**: `release.cmd check`는 애드온 CSS·JS도 검사합니다(`lib/UiLint.ps1`).
   - 오류: 애드온 CSS의 전역 선택자(`:root`, `html`, `body`, `*`, 클래스 없는 `button`·`input`·`a` 등). 애드온 루트 클래스 아래로 범위를 좁혀야 합니다.
   - 경고: `var()` 밖의 hex 색(→ `--ojp-*` 토큰), `!important`, `OJP`를 쓰지 않는 `confirm()`/`alert()`.
   - 빌드된 번들과 게임 그림은 `plugins.json`의 `uiLintExclude`(플러그인 폴더 기준 glob)로 뺍니다.
10. **PHP 확장**: 릴리스 스크립트가 쓰는 openssl·zip·gd·intl·sqlite가 php.ini에서 꺼져 있으면, 킷이 실행하는 동안만 `PHP_INI_SCAN_DIR`로 켭니다. 사용자의 php.ini는 고치지 않습니다.

## 빌더 종류

`plugins.json`의 `builder.type`에 따라 동작합니다.

| type | 동작 | 사용 플러그인 |
|---|---|---|
| `powershell` / `python` / `php` | 플러그인 자체 릴리스 스크립트를 실행합니다. 각 스크립트의 품질 게이트(테스트, 라이선스, clean git 등)를 그대로 거칩니다. | edu-games, oj-ai-dashboard, oj-code-arena, oj-code-lab, oj-ml-playgrounds |
| `generic` | 킷 내장 패키저를 씁니다. `include` 목록만 담고, 인코딩을 검사하고, 압축 후 재검증하고, `.sha256`과 manifest를 생성합니다. | learning-materials, readcontents-library, teaching-tools, oj-primm |

플러그인별 주의:
- 2026-10-13부터 plugins 폴더 전체가 하나의 git 저장소입니다(플러그인별 옛 기록은 `_attic/git-history/`). 릴리스 스크립트는 자기 플러그인 폴더의 변경만 검사합니다.
- `oj-ai-dashboard`는 자기 폴더에 커밋하지 않은 변경이 없어야 정식 패키지가 만들어집니다.
- OJ Platform 정식 버전은 태그 `oj-platform-v<버전>`이 현재 커밋을 가리켜야 패키징됩니다(통합 저장소라 태그에 플러그인 이름을 붙입니다). `release.cmd package -Plugin oj-platform`이 공식 빌더의 전체 검증을 실행하고 `_releases/oj-platform/<버전>/`에 직접 출력합니다.
- `oj-ml-playgrounds` 패키징 전 검사(`scripts/verify.ps1`)는 업스트림 프런트엔드 원본(고정 커밋 2276d1f의 `frontend`, `node_modules` 포함)이 필요합니다. `plugins.json`의 `builder.env.OJ_ML_UPSTREAM_FRONTEND` 경로를 킷이 자동으로 지정합니다(환경변수가 이미 있으면 그 값을 씁니다).
- `readcontents-library`의 원본은 루트의 `readcontents-library/` 폴더입니다. 2026-10-12에 `_notes/readcontents-server-import-2026-09-12/`에서 옮겼습니다. 편집기 프런트엔드 소스는 그 노트 폴더의 `editor-source/`에 있습니다.
