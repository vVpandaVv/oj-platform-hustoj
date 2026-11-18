# 업데이트 기록

GitHub에 공개한 배포 기준으로 최신 기록부터 정리합니다. 날짜·시각은 GitHub 공개 시점의 한국 시간입니다. [배포 ZIP과 체크섬](_releases/README.md)

## 2026-11-18 · 3차 · 과거 업데이트 기록 보완

- 2026-10-26 최초 공개부터 이전 배포의 버전·주요 변경 사항을 정리했습니다.
- 설치·업데이트 도구와 API 개발 자료의 변경 이력에 당시 커밋·배포 ZIP 목록을 연결했습니다.

검증: 기존 공개 커밋 20개의 이력·버전 대조, 과거 파일·문서 링크 확인, 릴리스 킷 검사 통과.

## 2026-11-18 · 2차 · 업데이트 기록 안내 추가

- 플러그인별 이전·새 버전과 주요 변경을 모은 이 문서를 추가했습니다.
- README와 배포 ZIP 목록에 업데이트 기록 링크를 추가하고, GitHub 갱신 시 기록을 남기는 작업 규칙을 등록했습니다.

[변경 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/a6fedeb976f3835a162b11e6979ace4b5e793ec0)

## 2026-11-18 · 1차 · HUSTOJ 애드온 업데이트

| 플러그인 | 이전 → 새 버전 | 주요 변경 사항 |
| --- | --- | --- |
| [Edu Games](edu-games/docs/releases/1.13.1.md) | 1.10.2 → 1.13.1 | 타자 연습·검정 정확도와 서버 검증 개선, 암호 금고 개인·팀전과 댄스봇 개선 |
| [OJ AI Dashboard](_release-kit/guides/oj-ai-dashboard.md) | 1.2.26 → 1.2.29 | 등급별 채점표 검증, 명단 조회 장애 복구, 선택 학생의 결과·점수·피드백 일괄 공개 |
| [ReadContents Library](readcontents-library/README.md) | 3.3.10 → 3.3.16 | 단원 링크의 공개 상태·새 탭 처리 개선, 가져오기 시 목차 제목 갱신, 레슨 ID 오류와 관리자 카드 정렬 수정 |
| [Teaching Tools](teaching-tools/README.md) | 1.2.9 → 1.2.10 | 앞자리 우선 구역을 1~2행으로 지정하고 최근 확정 배치 5회를 활용해 같은 자리·짝 반복 감소 |
| [OJ Microbit](oj-microbit/CHANGELOG.md) | 1.0.0-rc.9 → 1.0.0-rc.15 | 센서·출력 시각화, 상태를 유지하는 실험 실행기, 실행기 업데이트 실패 복구, 문제 작성·학생 편집 화면 개선 |
| [OJ Concept Lab](oj-concept-lab/README.md) | 신규 1.0.0 | Edu Games에서 분리한 정보 개념 체험: 데이터·자료구조·탐색·개인정보·네트워크·압축·병렬처리 학습 |
| [OJ Network Lab](oj-network-lab/README.md) | 신규 1.4.0-rc.4 | 브라우저 네트워크 구성·명령어·패킷 분석 실습과 IPv6·OSPF·BGP·VPN 시뮬레이션 |
| [OJ Logic Lab](oj-logic-lab/README.md) | 신규 1.0.2 | 논리회로 실습, 진리표·상태표·파형·버스·사용자 부품과 회로도 SVG 내보내기 |

검증: 최신 ZIP 14개의 SHA-256·압축 무결성과 소스 일치, 릴리스 킷 검사, 자리 배치 이력·제약 회귀 검사 통과.

[배포 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/eb304bf69097a4c14d3932edfa37d36f15d76912) · [이전 배포와 비교](https://github.com/vVpandaVv/oj-platform-hustoj/compare/bff9e8ecd320242184af63df4d95c2e41cf71010...eb304bf69097a4c14d3932edfa37d36f15d76912)

## 2026-11-05 08:31 · 플랫폼 2.5.9와 애드온 업데이트

| 구성 요소 | 이전 → 새 버전 | 주요 변경 사항 |
| --- | --- | --- |
| OJ Platform | 2.5.4 → 2.5.9 | 애드온 자료를 기본 문제 목록·수업 페이지에 연결, 학생 비밀번호 초기화와 계정 보호 진단 개선, 요청 횟수 제한의 동시 처리 오류 수정 |
| Addon API | 1.8.0 → 1.9.0 | 문제·수업 목록 연동 API와 사용 예제 문서 추가, 공개 PHP 타입 95개 → 102개 |
| Edu Games | 1.6.7 → 1.10.2 | 이진수·장치 카드 실시간 팀전, 공용 WebSocket 연동, 교사 경기 운영실·학생 봇 연습·현황판, 게임 순위와 팀전 조작 개선 |
| OJ AI Dashboard | 1.2.15 → 1.2.26 | 교사 채점 초안 저장·일괄 승인, 미제출·장기결석 점수, 학생 답안 저장 이력, 마감 자동 제출과 DB 구조 검사 오류 복구 |
| OJ Code Lab | 1.4.0 → 1.4.1 | 셀별 출력 지우기, 최신 실행 요청 우선 처리, 이전 실행의 늦은 응답이 새 결과를 덮는 오류 수정 |
| Focus Tracker | 1.0.1 → 1.1.2 | 학습 페이지 카테고리와 주간·월간 통계, 동시 저장 교착 방지, 명단·통계 조회와 갱신 부하 개선 |
| OJ Research | 1.0.0 → 1.3.1 | 그룹별 수업 설정·시작/종료 설문·비교 조건 배정, 간편 수업 운영과 결과·전문가 자문 보고서 내보내기 |
| OJ Microbit | 신규 1.0.0-rc.9 | MakeCode 편집·프로젝트 저장, 장치 입력·출력 시뮬레이션 채점, 공개 문제와 수업별 프로젝트·제출 분리, 다중 핀 설정과 집중형 화면 |

함께 공개된 플랫폼 중간 버전의 세부 변경입니다. 아래 날짜는 플러그인 개발 기록일입니다.

| 버전 | 개발 기록일 | 주요 변경 사항 |
| --- | --- | --- |
| 2.5.9 | 2026-11-03 | 애드온 문제 유형별 탐색과 문제·수업 메뉴 연결 |
| 2.5.8 | 2026-11-02 | 사용자 상세와 비밀번호 초기화 거부 결과에서 계정 보호 사유 진단 |
| 2.5.7 | 2026-11-02 | 여러 애드온의 학생 권한을 사용자 관리·비밀번호 초기화에서 일관되게 판정 |
| 2.5.6 | 2026-11-02 | OJ 사용자 관리자의 일반 학생 비밀번호 초기화와 실패 사유 안내 |
| 2.5.5 | 2026-10-28 | 요청 횟수 제한의 동시 저장 안정화, 기존 횟수를 보존하며 만료 정보 복구 |

[당시 배포 ZIP·체크섬](https://github.com/vVpandaVv/oj-platform-hustoj/blob/bff9e8ecd320242184af63df4d95c2e41cf71010/_releases/README.md) · [플랫폼 세부 변경](oj-integration-rebuild/oj-platform/CHANGELOG.md) · [변경 비교](https://github.com/vVpandaVv/oj-platform-hustoj/compare/55cb998040a6c127a4cbbc67c906631d4ca51520...bff9e8ecd320242184af63df4d95c2e41cf71010)

## 2026-10-27 21:53 · OJ Platform 2.5.3 → 2.5.4

- 문제 화면에 공개·활성 상태의 연결 퀴즈를 표시합니다.
- 퀴즈 풀이·제출·결과·통계에서도 수업·대회 접근 권한과 원래 문제로 돌아가는 경로를 유지합니다.
- 퀴즈 링크가 있는 모바일 문제 화면의 제출 버튼 배치를 개선했습니다.

[당시 배포 ZIP·체크섬](https://github.com/vVpandaVv/oj-platform-hustoj/blob/55cb998040a6c127a4cbbc67c906631d4ca51520/_releases/README.md) · [변경 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/55cb998040a6c127a4cbbc67c906631d4ca51520)

## 2026-10-27 19:43 · OJ Platform 2.5.1 → 2.5.3

| 버전 | 주요 변경 사항 |
| --- | --- |
| 2.5.3 | 코드 완성 문제의 언어 선택 목록, 퀴즈 저장·가져오기 폼 분리, 문항 유형 전환과 숨긴 문항의 입력 검증 수정 |
| 2.5.2 | 입력 없는 AI 생성 문제 허용, 생성 응답 형식 통일, 잘못된 필드·값을 구분하는 오류 안내 |

플랫폼 버전에 맞춰 공개 API 문서와 형식 파일을 갱신했습니다.

[당시 배포 ZIP·체크섬](https://github.com/vVpandaVv/oj-platform-hustoj/blob/a2a1b3915439707da2f2b973c28a9ce15a7da7b2/_releases/README.md) · [변경 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/a2a1b3915439707da2f2b973c28a9ce15a7da7b2)

## 2026-10-27 17:10 · Addon API 1.8.0 개발 자료 공개

- 공개 PHP 타입 95개의 형식과 프런트엔드 API를 정리했습니다.
- 콘솔 메뉴·REST·권한·개인 메모 저장을 포함하는 애드온 시작 예제와 검사 도구를 추가했습니다.

[당시 개발 키트](https://github.com/vVpandaVv/oj-platform-hustoj/tree/ef638fd843191f39983290f653fc5056c7ca1ed6/developer-kit) · [변경 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/ef638fd843191f39983290f653fc5056c7ca1ed6)

## 2026-10-27 16:58 · 애드온 ZIP 배포

- 플랫폼을 포함한 플러그인 9개의 설치 ZIP·SHA-256과 다운로드 목록을 공개했습니다.
- Teaching Tools 1.2.8 → 1.2.9: 공통 실시간 서버 설치 안내를 최신 내용으로 동봉했습니다.

[당시 배포 ZIP·체크섬](https://github.com/vVpandaVv/oj-platform-hustoj/blob/b223d597cb1b831956e439a5780dd5c9a8c6c6ef/_releases/README.md) · [변경 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/b223d597cb1b831956e439a5780dd5c9a8c6c6ef)

## 2026-10-27 16:17 · 별도 업데이트 명령 추가

- `update.sh`에서 ZIP 체크섬·요구 버전을 확인한 뒤 플랫폼을 갱신합니다.
- DB·기존 플랫폼·설정 파일을 백업하고 코드·스키마를 갱신하며, 중단된 업데이트를 재개할 수 있습니다.

[변경 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/8235ea379ff182641169329a9481432d96afafc0)

## 2026-10-27 00:09~12:19 · 설치 진행 표시와 오류 수정

| 공개 시각 | 주요 변경 사항 |
| --- | --- |
| [12:19](https://github.com/vVpandaVv/oj-platform-hustoj/commit/0ebae9fbb4d5129c07c0acdefe010da95060bbd3) | 관리자 입력 문구를 새 계정 생성 의미로 수정. GitHub API 다운로드·원본 URL 재시도·저장한 HUSTOJ 리비전 재사용으로 다운로드 복구 개선 |
| [09:56](https://github.com/vVpandaVv/oj-platform-hustoj/commit/7e905e21f2d2ae3c8f5025c3d052da2c2037a55c) | 설치 중 관리자 아이디·비밀번호 직접 지정, 비밀번호 숨김 입력·재입력 확인·형식 검증 |
| [09:09](https://github.com/vVpandaVv/oj-platform-hustoj/commit/58a44a542c05f331f911181ce90ee77aab4a562c) | WP-CLI에서 플랫폼 초기화 파일을 포함해 실행하도록 수정 |
| [09:07](https://github.com/vVpandaVv/oj-platform-hustoj/commit/b86974510a6349db58f902d57497c551d654c1ec) | WP-CLI 작업 경로를 웹 루트로 바꿔 홈 디렉터리 접근 권한 때문에 설치가 실패하는 문제 수정 |
| [09:00](https://github.com/vVpandaVv/oj-platform-hustoj/commit/b6830df18f49ee250dd88452a74afd0d7ce3a9b6) | 채점 서비스가 설치 잠금을 계속 보유해 재개가 막히는 문제 수정 |
| [00:09](https://github.com/vVpandaVv/oj-platform-hustoj/commit/0c9e00869cb7ae2271918f92a8b623359ad0590c) | HUSTOJ 설치 로그 실시간 표시, 10초 간격 경과 시간·실행 상태 안내, 설치 옵션·인증서 자동갱신 안내 보강 |

## 2026-10-26 21:19~23:23 · Ubuntu 설치 도구와 폴더 구성

| 공개 시각 | 주요 변경 사항 |
| --- | --- |
| [23:23](https://github.com/vVpandaVv/oj-platform-hustoj/commit/7500917c20c3291ed26ec0e4e9c08c1b791c6815) | 루트 `install.sh`로 설치 명령 단축, 서버 IPv4의 HTTP 주소와 테스트 이메일을 기본값으로 설정 |
| [22:55](https://github.com/vVpandaVv/oj-platform-hustoj/commit/134b49ab9316dc0e72c407cc9bf36d24b054379b) | 서버 IP의 HTTP 주소로 시험 설치하는 방법과 HTTPS 연락처 조건 안내 |
| [22:40](https://github.com/vVpandaVv/oj-platform-hustoj/commit/f55522f9c38563f699073e9db39ddf6aaadcd258) | GitHub에서 로그인 없이 다운로드해 설치하는 시작 스크립트 추가 |
| [21:59](https://github.com/vVpandaVv/oj-platform-hustoj/commit/61bfefd76e8d6dead2818e186b4297ba2a8675b6) | 공식 HUSTOJ·필수 프로그램·WordPress·OJ Platform 일괄 설치. 기존 웹 루트 백업 후 WordPress로 교체하고 `judge.conf` DB 계정 공유·HTTPS·설치 재개 지원 |
| [21:19](https://github.com/vVpandaVv/oj-platform-hustoj/commit/cc3a7e33257e73ae6000da71aea752bb6c908e43) | 코드 목록·배포 ZIP·설치 안내 폴더 구성, Ubuntu 24.04와 공식 HUSTOJ 기준 설치 문서 정리 |

## 2026-10-26 20:39 · 최초 공개

- OJ Platform 2.5.1·Addon API 1.8.0을 기준으로 플랫폼과 학습 애드온 소스를 공개했습니다.
- 플랫폼에는 제출 편집기의 복사·잘라내기·붙여넣기 설정, 사용자별 메시지 횟수 제한, 상태·오류 원인과 조치 안내가 포함됩니다.

[최초 공개 버전 목록](docs/hustoj-baseline-20261026.md) · [공개 커밋](https://github.com/vVpandaVv/oj-platform-hustoj/commit/8c47e4bb777348d7b669fac65651cad43cdc18f0)
