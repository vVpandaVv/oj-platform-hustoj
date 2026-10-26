# 편집기 회귀 검사

설치 ZIP에는 이 테스트 폴더가 포함되지 않는다. 운영 DB나 실제 자료에 연결하지 않는다.

## 실행

플러그인 루트의 상위 `plugins` 폴더에서 실행한다.

```powershell
& 'D:/php/php.exe' readcontents-library/tests/archive.php
node readcontents-library/tests/preview.mjs
```

별도 터미널에서 다음 검사를 실행한다. Playwright는 `_notes/readcontents-server-import-2026-09-12/editor-source`의 의존성을 사용한다. 브라우저가 다른 경로에 설치되어 있으면 `PLAYWRIGHT_BROWSERS_PATH`를 지정한다.

```powershell
node readcontents-library/tests/archive-ui.mjs
node readcontents-library/tests/editor-types-ui.mjs
node readcontents-library/tests/editor-types-integration.mjs
node readcontents-library/tests/workspace-scroll-ui.mjs
```

미리보기는 `127.0.0.1:8744`에만 바인딩하며 메모리 내 모의 API를 사용한다. Playwright 결과와 화면은 `_notes/tmp/readcontents-archive-2026-10-18`에 생성되므로 검토 후 정리한다.

`editor-types-ui.mjs`는 테스트 전용 진입점을 별도로 빌드하여 임의의 로컬 포트에서 실행하고 서버·브라우저를 종료한다. `editor-types-integration.mjs`는 위 미리보기의 실제 배포 번들을 검사한다. 이 두 검사의 결과는 `_notes/tmp/readcontents-types-2026-10-18`에 생성한다. 모의 데이터를 초기화하므로 브라우저 검사는 순서대로 실행한다.

`workspace-scroll-ui.mjs`는 배포 번들과 메모리 내 80개 레슨을 사용해 자체 서버·브라우저를 실행하고 종료한다. 결과와 스크린샷은 `_notes/tmp/readcontents-scroll-2026-10-20`에 생성한다. `--serve` 옵션은 수동 확인용 모의 편집기를 `127.0.0.1:8762`에서 유지한다.

편집기 원본 폴더에서 `npm run typecheck`로 운영 소스를, `node node_modules/typescript/bin/tsc --noEmit -p tsconfig.tests.json`으로 테스트 소스까지 검사한다. `npm run check`에도 타입 검사를 포함한다. 의존성이 외부 폴더의 연결 디렉터리인 환경에서 Vite 설정 캐시 쓰기가 거부되면 `npm run build -- --configLoader native`를 사용한다.

## 확인 범위

- PHP: 실제 저장소·REST·공개 정책 코드, WordPress 저장 함수 대역. 네 종류의 보관/초안 복원, 공개 권한, 보관된 상위 항목의 공개 차단, 잘못된 복원 거부, 삭제 거부 시 본문 보존, 보관된 자식까지 삭제, 첨부파일 보존, 성공 감사 로그.
- 브라우저: 1400/390/320px에서 주제 카드·선택 목차·본문의 버튼, 모든 종류의 보관함 표시, 확인 취소, 복원, 재귀 삭제, 본문 저장 선행, 저장/삭제 실패, nonce, 권한별 비활성화, 플랫폼 확인창과 기본 확인창 대체 동작.
- 전체 TypeScript 검사: 기존 진단 22건을 모두 수정했다. 엄격한 검사 설정과 검사 대상 범위를 유지한다.
- 편집기 타입 회귀: 1400/390px에서 날짜 자동 포커스·선택·표 스타일, 툴바 ref·툴팁, debounce 취소·flush·인수·this 보존, 복제 격리·객체 검색, DP·분할 정복·벡터·산점도·간트·기하 평면의 정상/예외 입력과 경계선 드래그를 확인한다.
- 배포 번들: 본문 안 날짜 선택·포커스, 슬래시 메뉴 취소와 블록 삽입을 확인한다.
- 분리 스크롤: 1440/1024/768/390/320px에서 마지막 레슨 선택, 목차 위치 유지, 본문 위치 초기화, 휠·키보드 스크롤, 상단 도구 모음, 모바일 목차, 보관함 서랍, 높이 600px로 변경, 서버 안내 펼침과 라이브러리 복귀를 확인한다.

편집기 원본은 `_notes/readcontents-server-import-2026-09-12/editor-source/assets/src/admin/content`에 있다. 빌드 결과를 현재 플러그인의 `assets/editor`에 반영할 때 `workspace-layout.css`는 보존한다. 배포는 루트 지침에 따라 `_release-kit/release.cmd`만 사용한다.

## 공개 레슨 화면 모드 검사

```powershell
& 'D:/php/php.exe' readcontents-library/tests/lesson-theme.php
& 'D:/php/php.exe' readcontents-library/tests/lesson-theme.php --legacy
node readcontents-library/tests/lesson-theme-ui.mjs
```

`lesson-theme.php`는 실제 플랫폼 Addon API와 플러그인의 `PageResponse`를 사용해 레슨의 화면 모드 허용, 본문·상태·레이아웃 보존을 검사한다. `--legacy`는 별도 프로세스에서 이전 API의 `html()` 대체 경로를 확인한다. WordPress DB는 사용하지 않는다.

브라우저 검사는 실제 `PlateHtmlRenderer`, 플랫폼 문서 렌더러·화면 모드 선택 부품·CSS·JS와 배포용 레슨 자산을 사용한다. 모든 HTTPS 요청을 테스트 경로에서 처리하므로 운영 사이트나 외부 서버에 접속하지 않는다. 1440/390/320px에서 라이트·다크·시스템 설정, 새로고침 없는 전환, OS 변경, 저장한 설정 복원, 본문·표·인용·코드·알림·접기·퀴즈·입력·미디어 캡션·시각화 조작부의 대비를 검사한다. 선택·정답·오답·해설 상태와 단계 이동, 저자 지정 색 및 원본 이미지 보존도 확인한다.

기본 브라우저는 설치된 Chrome이며 없으면 Playwright Chromium을 사용한다. `PHP_BINARY`, `PLAYWRIGHT_PATH`, `PLAYWRIGHT_CHROMIUM_EXECUTABLE`로 경로를 바꿀 수 있다. 결과와 화면은 `_notes/tmp/lesson-theme-2026-10-21`에 생성한다. 검토 후 임시 산출물만 정리한다.

## 편집기 목차 너비 조절 검사

```powershell
node readcontents-library/tests/workspace-resize-ui.mjs
```

`workspace-resize-ui.mjs`는 기존 `workspace-scroll-ui.mjs --serve`의 메모리 내 80개 레슨·실제 배포 번들과 요청 처리기를 그대로 사용한다. 브라우저의 HTTPS 요청은 같은 프로세스 안에서 처리하며 네트워크 서버나 운영 자료·DB를 사용하지 않는다. 종료 시 브라우저를 닫는다.

기본 너비, 마우스 드래그·취소·최소/최대 범위, 방향키·Shift·Home·End와 두 번 클릭, 새로고침·레슨·라이브러리 이동 후 설정 유지, 컨테이너 및 창 축소 후 복원, 본문·스크롤 위치 보존을 확인한다. 1024px 화면의 너비 제한과 390/320px 화면의 기존 모바일 목차도 검사한다. 설치된 Chrome 또는 Playwright Chromium을 사용하며 `PLAYWRIGHT_PATH`, `PLAYWRIGHT_CHROMIUM_EXECUTABLE`로 경로를 지정할 수 있다. 결과와 화면은 `_notes/tmp/readcontents-resize-2026-10-25`에 생성한다.

## 학생 레슨 목차·본문 배치 검사

```powershell
& 'D:/php/php.exe' readcontents-library/tests/reader-layout.php
node readcontents-library/tests/reader-layout-ui.mjs
```

PHP 검사는 실제 저장소·레슨 요청 처리기·목차 및 Plate 렌더러와 플랫폼 API를 사용한다. WordPress 저장 함수만 메모리 내 대역으로 바꾼다. 전체 주제의 공개 레슨, 비공개 상위 항목 필터링, 제목 이스케이프, 현재 레슨·장 펼침, 하위 폴더 설치 URL, 학생 접근 제한 및 교사 초안 미리보기를 확인한다.

브라우저 검사는 이 실제 요청 처리기가 생성한 화면과 등록된 CSS·JS, 플랫폼 화면 모드 부품을 사용한다. HTTPS 요청을 로컬 파일에서 처리하므로 네트워크 서버나 운영 DB를 사용하지 않는다. 1440/1024/390/320px에서 목차 표시·숨김, 본문 확장, 드래그·키보드 너비 조절, 취소, 설정 저장·레슨 이동, 창과 컨테이너 축소·복원, 모바일의 인라인 목차, 포커스·본문 DOM·메모·퀴즈·스크롤 보존을 검사한다. 라이트·다크·시스템 설정과 목차·본문의 대비, 가로 넘침, 브라우저 오류 및 자산 누락도 확인한다. 결과와 화면은 `_notes/tmp/readcontents-reader-2026-10-25`에 생성한다. 실행 경로는 `PHP_BINARY`, `PLAYWRIGHT_PATH`, `PLAYWRIGHT_CHROMIUM_EXECUTABLE`로 지정할 수 있다.
