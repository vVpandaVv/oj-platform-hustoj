# 공통 애드온 API 1.2.0

OJ Platform 2.0.5는 기존 애드온 API에 선택적 연구 확장 등록 단계를 추가한다. OJ Research는 별도 WordPress 플러그인으로 설치·업데이트하며, 연동 API의 버전은 `OJ_PLATFORM_ADDON_API_VERSION=1.2.0` 하나로 관리한다. `V1` namespace는 주 버전 1을 뜻한다.

## 호환 범위

| 소비자 | API 1.2.0에서의 동작 |
| --- | --- |
| 기존 일반 애드온 | `oj_platform_register_addons` → `register()` → `manifest()`·`onReady()` 호출 방식 유지 |
| 기존 Context·AI 작업·편집기 사용 애드온 | 기존 인터페이스, DTO, 인자 이름·기본값, 애드온별 런타임 전달 방식 유지 |
| OJ Research 1.0.0 | 공통 API `>=1.2.0 <2.0.0`과 확장 인터페이스 존재 확인 후 연결 |
| 연구 플러그인 없는 사이트 | 연구 설정·화면·스키마를 등록하지 않고 일반 OJ 및 애드온 실행 |

기존 애드온이 선언한 `^1.0`, `^1.1.0`, `>=1.1.0 <2.0.0`은 1.2.0을 허용한다. `=1.1.0` 또는 `<1.2.0`으로 제한한 소비자는 그 선언대로 비호환 판정을 받으며, 플랫폼이 버전을 위장하거나 요구 범위를 무시하지 않는다. 플랫폼 제품의 개발 버전 허용 여부는 기존 `requiresPlatform` 판정을 그대로 따른다.

1.1.0 서버를 따로 실행하거나 1.1.0 전용 어댑터를 복제하지 않는다. 기존 호출 계약을 1.2.0 구현이 계속 제공한다. 일반 애드온은 연구용 초기 훅을 구독할 필요가 없다.

## 등록과 실행 순서

1. 모든 활성 WordPress 플러그인의 진입 파일이 로드된다.
2. `plugins_loaded`에서 플랫폼을 조립하며 `oj_platform_register_extensions`를 호출한다. 인자는 `AddonExtensionRegistrationGatewayV1`이고 `apiVersion()`은 공통 버전을 반환한다.
3. 연구 플러그인이 `registerResearch(ResearchExtensionV1 $extension)`를 호출한다. 사이트당 연구 정책 제공자는 하나이며, 훅 종료 후 등록을 닫는다. 중복 등록, 늦은 등록, 등록 중 예외는 조용히 무시하지 않는다.
4. 등록된 연구 확장의 설정과 스키마를 기존 플랫폼 설치·모듈 구성 흐름에 포함하고, 연구 정책을 AI·제출·추천·계정 정리에 연결한다.
5. 기존 일반 애드온 등록·검증·기여 구성·레지스트리 동결·개별 `onReady()` 전달 절차를 기존 시점에 수행한다.

초기 훅을 별도로 두는 이유는 저장 구조와 AI 제어 정책을 먼저 연결해야 하기 때문이다. 이는 **동일 API 안의 실행 단계**다. 기존 일반 애드온 훅을 앞으로 옮기거나 두 번 호출하지 않는다. 초기 등록 오류는 플랫폼의 부팅 오류 처리로 전달하며, 제어 정책 없이 연구를 계속하지 않는다.

## 공개 확장 계약

- `AddonApi\V1\AddonExtensionRegistrationGatewayV1`: `apiVersion()`, `registerResearch()`.
- `AddonApi\V1\Research\ResearchExtensionV1`: `configuration()`으로 구성 정보를 제공하고 `build(ResearchHostV1)`로 `ResearchRuntimeV1`을 반환한다.
- `ResearchHostV1`: 기존 수준평가·문제·제출 조회, 공통 AI 라우터, 권한·계정·그룹·응답·설치 관련 계약을 연구 구현에 전달한다.
- `ResearchLearningDataV1`: `levelSnapshot()`, `problemHashes()`, `submissions()`, `guides()`, `problems()`로 기존 OJ의 자료를 읽는다.
- `ResearchRuntimeV1`: 연구 모듈, 피드백·AI 접근·제출 기록·추천 정책, 비활성화·계정 정리·문제 화면 연동, 경로·액션·공개 메뉴를 플랫폼에 반환한다.

이 단계는 설치와 학습 제어에 참여하는 신뢰된 서버 플러그인용 계약이다. 일반 애드온의 Context/AI 게이트웨이 권한 범위를 넓히지 않는다. `ResearchHostV1`·`ResearchRuntimeV1`에 명시된 기존 플랫폼 계약도 이 확장과 함께 호환성을 관리한다. 호스트가 제공하지 않은 내부 구현이나 다른 플러그인의 저장소에 직접 의존하지 않는다.

연구 설정의 추가 항목은 `actions`, `features`, `menus`, `modules`, `platform-tables`, `routes`, `route-access`, `additive-install-modules`, `migration-extra-tables`이다. 기존 이름을 덮어쓰는 설정은 허용하지 않는다. 연구 테이블 정의와 버전은 OJ Research가 소유하며 기존 플랫폼 설치기가 적용한다.

실제 소비자 구현은 `oj-research/src/Core/Plugin.php`와 `ResearchExtension.php`에 있다. 연구 플러그인의 활성화·안내문·패키지 명세도 동일한 `>=1.2.0 <2.0.0` 요구를 사용한다.

## 이전 분리 시안과의 관계

2.0.5-dev.1 / OJ Research 1.0.0-dev.1의 별도 `ResearchApi\V1`, `OJ_PLATFORM_RESEARCH_API_VERSION`, `oj_platform_register_research_v1`은 개발 시안이며 이번 버전으로 교체한다. 이 시안용 별도 호환 계층을 유지하지 않는다. 이미 배포된 일반 애드온 API 1.1.0 계약은 유지한다.

플랫폼 ZIP을 먼저 업데이트하고 연구 ZIP을 이어서 업데이트한다. 이전 연구 시안과 새 플랫폼을 섞으면 연구 확장이 등록되지 않으므로 두 패키지를 같은 작업에서 갱신한다. 기존 `research_studies`, `research_participants`, `research_records` 자료와 수준평가 결과는 그대로 사용한다.

## 검증

`tests/Contract/addon-api-v1.php`는 기존 공개 메서드·인자·DTO 계약과 새 초기 게이트웨이를 검사한다. `addon-extension-registration.php`는 선택 설치, 공통 버전, 등록 동결·중복·예외 처리와 공개 namespace를 검사한다. `addon-runtime-dispatch.php`는 기존 1.1 요구 애드온이 1.2에서 개별 런타임을 전달받는지 검사한다.

연구 플러그인의 `tests/Integration/lifecycle.php`는 실제 플랫폼 조립 코드에서 일반 1.1 애드온과 연구 확장의 공존, 양방향 로드 순서, 비활성·재활성, 기존 데이터 보존과 추가 설치를 검사한다. 실제 배포 및 학생 수업에서의 효과 검증은 이 로컬 소프트웨어 검사와 구분한다.
