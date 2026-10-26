# 수업 연구 기능 분리

OJ Platform 2.0.5와 OJ Research 1.0.0에서는 연구 화면·실험 설계·배정·기록·분석·보고서 구현이 별도 **OJ Research** WordPress 플러그인에 있습니다. 플랫폼의 `modules/Research`는 제거되었습니다.

플랫폼은 공통 애드온 API **1.2.0**의 `AddonApi/V1/Research`에서 데이터 조회 및 정책 연결 계약을 제공합니다. 기존 학생 수준평가, 수준별 지침, 문제·제출·AI 실행은 플랫폼의 기존 서비스를 사용합니다. 기존 1.1.0 애드온의 호출 계약과 등록 시점을 유지하며 연구용 별도 API 버전을 두지 않습니다. 자세한 호환 범위는 [공통 API 1.2 안내](addon-api-1.2.md)를 따릅니다.

- 연구 플러그인 등록: `oj_platform_register_extensions` → `AddonExtensionRegistrationGatewayV1::registerResearch()`
- 구성 제공: `ResearchExtensionV1::configuration()` — 모듈, 기능, 경로, 메뉴, 접근 권한, 추가 설치 테이블
- 서비스 연결: `ResearchExtensionV1::build(ResearchHostV1)` → `ResearchRuntimeV1`
- 연결 시점: `plugins_loaded`에서 플랫폼 구성을 시작할 때 수집하고 레지스트리 동결 전에 조립
- 활성화 순서: 플랫폼 ZIP 업데이트 → 연구 ZIP 설치·활성화 → 필요한 경우 추가 설치 → 연구 기능 켜기

연구 DB의 기존 테이블명·스키마와 소유권 표식을 유지합니다. 진행 중인 연구의 플러그인 비활성화를 방지하며, 강제 제거 시에는 활성 참여자의 AI·제출·추천을 보호합니다. 연구 자료가 남은 상태의 계정 삭제는 연구 플러그인을 켜고 수행해야 합니다.

설치·운영 안내는 형제 폴더의 [OJ Research README](../../oj-research/README.md), 연구 분석 문서는 [학습 효과 분석 안내](../../oj-research/docs/research-effect-analysis.md)에 있습니다.

과거 `research-integration.html`, `research-pilot-redesign.html`, `research-effect-preview/`는 당시 설계·검증 기록입니다. 현행 코드의 미리보기는 `oj-research/docs/preview/`이며 합성 자료만 포함합니다.
