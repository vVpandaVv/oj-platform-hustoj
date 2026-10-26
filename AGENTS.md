# 에이전트 작업 지침 (Codex 등)

이 폴더(`wp-content/plugins` 루트)의 최상위 규칙은 `AGENT.md`이다. 작업 전에 `AGENT.md`와 `_release-kit/README.md`를 읽고 따른다.

핵심 요약:
- 모든 텍스트 파일은 UTF-8(BOM 없음)으로 저장한다.
- 설치 ZIP과 설치 안내서는 `_release-kit\release.cmd`(package / docs / all / check)로만 만든다.
- 패키지 출력은 `_releases/<플러그인>/<버전>/`에만 둔다. 릴리스된 버전은 덮어쓰지 않는다.
- 설치 안내서 원본은 `_release-kit/guides/`에서 편집한다. `_releases/latest/`는 생성물이다.
- 작업 종료 전에 `_release-kit\release.cmd check`를 통과시킨다.
