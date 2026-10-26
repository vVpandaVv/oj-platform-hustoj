# 최신 콘텐츠

최종 확인과 업로드 파일은 항상 `plugins/_releases/latest/content/`에서 찾습니다. 이 폴더는 자동 생성되므로 직접 편집하지 않습니다.

원고를 수정한 뒤 해당 프로젝트에서 패키지를 생성·검증하고, `plugins` 폴더에서 아래 명령을 실행합니다.

```powershell
.\_release-kit\release.cmd content
.\_release-kit\release.cmd check
```

`content` 명령은 등록된 최신 파일을 복사하고 안내서를 갱신합니다. 원고를 자동 집필하거나 아직 빌드하지 않은 변경을 변환하지 않습니다. 플러그인 설치 ZIP은 변경하지 않습니다.

- 관리 목록: `plugins/readcontents-content/catalog.json`
- `content-index.json`: 콘텐츠별 상태, 편집 위치, 파일 목록, SHA-256
- `SHA256SUMS.txt`: 이 폴더의 파일 무결성 확인값
- ReadContents ZIP은 읽기자료 가져오기, 문제 XML ZIP은 HUSTOJ/OJ 문제 가져오기에 사용합니다.
- 관리자 정답 자료와 도서 초안은 학생용 공개 콘텐츠와 구분합니다.
- 이번 경로 통합은 실제 서버 업로드나 모든 콘텐츠의 새로운 내용 검수를 뜻하지 않습니다.
