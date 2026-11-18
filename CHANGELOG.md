# 업데이트 기록

GitHub에 공개한 배포 기준으로 최신 기록부터 정리합니다. [배포 ZIP과 체크섬](_releases/README.md)

## 2026-11-18 · 2차 · 업데이트 기록 안내 추가

- 플러그인별 이전·새 버전과 주요 변경을 모은 이 문서를 추가했습니다.
- README와 배포 ZIP 목록에 업데이트 기록 링크를 추가하고, GitHub 갱신 시 기록을 남기는 작업 규칙을 등록했습니다.

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
