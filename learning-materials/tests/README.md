# Learning Materials 정식 릴리스 검증

루트에서 `powershell -File learning-materials/tests/verify.ps1`을 실행합니다. PHP 기본 경로는 `D:\php\php.exe`이며 `-Php`, `-Node`로 바꿀 수 있습니다. fileinfo·sqlite3는 검사 프로세스에만 활성화하며 php.ini를 변경하지 않습니다.

검증 범위:

- 파일 형식·MIME 검사, Google 링크 정규화와 유해 URL 차단
- 검색·필터·페이지 나누기·고정 순서와 비공개 자료의 건수·분류·태그·그룹 노출 방지
- 초안·공개·보관, WordPress 시간대 변환, 공개 시작·종료 경계
- 직접 열람·다운로드·임시 링크의 권한·기간 검사와 관리자 미리보기 서명 위조 차단
- 플랫폼 활동 목록과 보호 스트림의 로그인·열람 권한·사용자·그룹·공개 기간 경계 일치
- 파일↔링크 전환과 그룹 저장 실패 시 원본·DB 상태 유지
- 자동 미리보기·빈 프레임 재시도·새 창 링크·오류 안내와 진단 UI
- 파일 누락·정보 불일치·링크 만료·로그인 리디렉션·Google 204·iframe 제한 진단
- 신규 설치·구버전 업데이트·기존 자료 및 그룹 보존·재실행 안전성
- PHP 메모리 제한 12MiB에서 16MiB 파일을 손상 없이 전송하는지 검사

화면 및 HTTP 검증은 로컬 서버를 실행한 후 수행합니다.

```powershell
D:\php\php.exe -d extension=php_fileinfo.dll -S 127.0.0.1:8898 learning-materials/tests/preview-site.php
powershell -File learning-materials/tests/http-smoke.ps1
```

화면 주소는 `/oj-addons/learning-materials?test_role=learner`, `/oj-console/addons/learning-materials/new?test_role=admin`입니다. `test_width=342`를 붙이면 좁은 컨테이너 배치도 점검할 수 있습니다. 이 서버는 테스트용 WordPress·Addon API 대역을 사용하며 운영용으로 배포하지 않습니다. 종료 후 `_notes/tmp/lmt-ui-site`의 테스트 파일을 정리합니다.

DB 업그레이드는 SQLite에서 WordPress dbDelta의 열 추가 동작을 모사해 검증합니다. 실제 WordPress/MySQL 설치나 운영 사이트 변경을 뜻하지 않습니다. 외부 Google 응답은 진단 테스트에서 대역으로 재현하며, Google 서비스의 모든 브라우저 렌더링을 보장하지 않습니다.
