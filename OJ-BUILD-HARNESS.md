# OJ Build Harness

이 문서는 현재까지 구현된 `oj-*` WordPress 모듈 플랫폼의 제작 규칙, 운영 규칙, 배포 규칙을 표준화한 하네스다.
목적은 다음과 같다.

- 다른 프로젝트에서도 동일한 기준으로 모듈을 설계하고 구현할 수 있게 한다.
- 여러 작업자나 에이전트가 동시에 작업해도 구조 일관성을 유지하게 한다.
- 기능 구현, 설정, 운영, 검수, 배포를 하나의 기준으로 관리하게 한다.

## 1. 적용 범위

이 하네스는 다음 범위에 적용한다.

- `oj-core` 기반의 WordPress 플러그인 모듈
- 외부 채점엔진과 연동되는 OJ 서비스
- 인증, 그룹, 반, 대회, 문제집, AI, 게시판, Blockly, 운영, 라이선스 모듈
- 신규 `oj-*` 플러그인 생성 시의 기본 원칙

적용 대상이 아닌 것:

- 기존 `hustoj-*` 레거시 직접 수정 방식
- HUSTOJ 내부 DB 결합 구조
- 단일 플러그인 안에 모든 기능을 밀어 넣는 통합형 구조

## 2. 비타협 원칙

### 2.1 코어 원칙

- 모든 기능 플러그인은 `oj-core` 위에서 동작해야 한다.
- 모듈 간 직접 결합보다 `contract`를 우선 사용한다.
- 설정 화면, 운영 화면, 연동 화면은 가능하면 각 모듈에 분산하지 않고 공통 패턴을 사용한다.
- 기능이 없어도 시스템이 깨지지 않도록 `soft dependency`를 허용한다.
- 없는 모듈을 전제로 코어 테이블에 컬럼을 미리 박아 넣지 않는다.

### 2.2 채점엔진 원칙

- 채점엔진은 외부 시스템이다.
- WordPress 모듈은 채점엔진 DB 세부 스키마에 직접 결합하지 않는다.
- 채점엔진 접근은 `oj-judge-adapter`를 통해서만 수행한다.
- HUSTOJ DB는 더 이상 사용하지 않는다.
- 권한 판단은 WordPress 모듈이 하고, 채점엔진은 검증된 요청만 받는다.

### 2.3 운영 원칙

- 운영 상태는 `oj-ops`에서 집계 가능해야 한다.
- 중요 설정 변경은 감사 로그를 남겨야 한다.
- 건강 상태는 `health_check_provider`를 통해 점검 가능해야 한다.
- 라이선스가 필요한 기능은 `oj-license` 게이트를 거쳐야 한다.

## 3. 표준 플러그인 분류

### 3.1 필수 기반

- `oj-core`
- `oj-auth`
- `oj-judge-adapter`

### 3.2 핵심 도메인

- `oj-groups`
- `oj-problems`
- `oj-classes`
- `oj-contests`
- `oj-problem-books`
- `oj-home`

### 3.3 AI 계층

- `oj-ai-provider`
- `oj-ai-assessment`
- `oj-ai-dashboard`

### 3.4 운영/상용 계층

- `oj-ops`
- `oj-license`
- `oj-setup-toolbox`

### 3.5 선택 UX/확장 모듈

- `oj-board-addon`
- `oj-blockly-submit`

## 4. 표준 의존성 규칙

### 4.1 하드 의존성

하드 의존성은 다음 조건을 만족할 때만 사용한다.

- 모듈이 없으면 플러그인이 부팅 자체를 할 수 없는 경우
- 서비스 계약 없이 직접 핵심 기능을 사용할 수 없는 경우
- 활성화 시 반드시 확인되어야 하는 경우

예시:

- `oj-problems` -> `oj-core`, `oj-auth`, `oj-judge-adapter`
- `oj-ai-assessment` -> `oj-core`, `oj-auth`, `oj-groups`, `oj-ai-provider`

### 4.2 소프트 의존성

소프트 의존성은 다음 조건에서 사용한다.

- 있으면 더 풍부하게 동작하지만 없어도 기본 기능은 살아 있는 경우
- 특정 화면, 링크, 집계, 부가기능만 추가되는 경우

예시:

- `oj-board-addon` -> `oj-problems`, `oj-classes`, `oj-contests`
- `oj-ops` -> `oj-license`, `oj-judge-adapter`

### 4.3 의존성 선언 규칙

모든 플러그인은 `plugin-package.php`를 가져야 한다.
포함 필드:

- `id`
- `name`
- `version`
- `main_file`
- `requires`
- `soft_requires`
- `provides`

`requires`는 `package_id:^major.minor` 형식을 기본으로 한다.

## 5. 표준 설치 순서

기본 권장 순서:

1. `oj-core`
2. `oj-auth`
3. `oj-groups`
4. `oj-judge-adapter`
5. `oj-problems`
6. `oj-classes`
7. `oj-contests`
8. `oj-problem-books`
9. `oj-ai-provider`
10. `oj-ai-assessment`
11. `oj-ai-dashboard`
12. `oj-ops`
13. `oj-license`
14. `oj-board-addon`
15. `oj-blockly-submit`
16. `oj-setup-toolbox`

실제 활성화 순서는 `oj_installation_plan()` 결과를 우선한다.

## 6. 설정 입력 원칙

### 6.1 설정 분산 금지

실제 운영 값은 가능하면 별도 설정 도구에서 입력 가능해야 한다.
각 모듈이 자기 관리자 페이지를 갖는 것은 허용되지만, 공통 진입점은 `OJ Setup`이어야 한다.

### 6.2 Setup Provider 규칙

설정 가능한 모듈은 `setup_provider`를 등록해야 한다.
권장 인터페이스:

- `id(): string`
- `title(): string`
- `description(): string`
- `is_configured(): bool`
- `status_label(): string`
- `fields(): array`
- `save(array $input): string`

### 6.3 현재 toolbox 연동 대상

- `oj-judge-adapter`
- `oj-license`
- `oj-ai-provider`
- `oj-blockly-submit`

신규 모듈도 실값이 필요하면 반드시 setup provider를 제공한다.

## 7. 계약 기반 통신 규칙

직접 참조보다 `oj_register_contract()` / `oj_contract()` / `oj_contracts()`를 우선 사용한다.

현재 핵심 계약:

- `judge_engine_client`
- `judge_submission_service`
- `roster_provider`
- `ai_provider_client`
- `audit_logger`
- `license_manager`
- `health_check_provider`
- `setup_provider`
- `submission_context_provider`
- `problem_book_repository`

규칙:

- 계약이 없어도 치명적이지 않으면 예외 대신 graceful fallback 처리
- 다중 provider가 가능한 계약은 `oj_contracts()` 사용
- 단일 provider만 필요한 경우 `oj_contract()` 사용

## 8. DB 및 스키마 규칙

### 8.1 WordPress 쪽 규칙

- 각 모듈은 자기 전용 테이블을 소유한다.
- 다른 모듈의 테이블을 직접 변경하지 않는다.
- 공통 정보가 필요하면 contract 또는 adapter를 사용한다.
- 플러그인 활성화 시 `Installer::install()`에서 자기 테이블만 생성한다.

### 8.2 채점엔진 쪽 규칙

- 채점엔진 코어 테이블은 엔진 책임이다.
- WordPress 기능별 맥락은 WP 쪽 링크 테이블로 푼다.
- 예: `oj_class_submissions`, `oj_contest_submissions`

### 8.3 코어 테이블 ALTER 금지 원칙

다음은 금지한다.

- 기능 추가 때마다 코어 채점 테이블에 컬럼을 계속 붙이는 방식

다음을 권장한다.

- 기능별 전용 확장 테이블 추가
- `submission_id`, `user_id`, `group_id`, `contest_id`, `plan_id` 같은 안정 키 기반 연결

## 9. 제출 흐름 규칙

### 9.1 제출 생성 경로

모든 제출은 `oj-problems` -> `oj-judge-adapter` 경로를 기본으로 한다.

### 9.2 컨텍스트 처리

반/대회/기타 컨텍스트 제출은 `submission_context_provider`를 통해 처리한다.

권장 메서드:

- `accepts(array $payload): bool`
- `prepare_context(array $payload): array`
- `after_submission(array $context, array $result, array $payload): void`

### 9.3 컨텍스트 링크 원칙

- 제출 자체는 채점엔진에 생성
- 맥락 연결은 각 모듈 링크 테이블에 저장
- 반/대회 스코어 계산은 링크된 제출만 읽음

## 10. 프론트엔드 구현 규칙

### 10.1 최소 완성 기준

프론트 shortcode는 단순 placeholder가 아니라 다음 중 하나를 충족해야 한다.

- 실제 데이터 목록 렌더링
- 상세 보기 렌더링
- 입력 폼과 저장 흐름
- 사용자 피드백 메시지

### 10.2 필수 UX 요소

- nonce 검증
- 로그인 필요 여부 안내
- 성공/실패 메시지
- 빈 상태 처리
- 링크 가능한 상세 진입

### 10.3 현재 표준 shortcode 예시

- `oj_problem_list`
- `oj_problem_detail`
- `oj_problem_submit`
- `oj_recent_submissions`
- `oj_classes`
- `oj_class_hub`
- `oj_contests`
- `oj_contest_board`
- `oj_board`
- `oj_board_topic`
- `oj_blockly_submit`
- `oj_problem_books`

## 11. 운영 규칙

### 11.1 감사 로그

다음 이벤트는 감사 로그 기록 대상이다.

- 핵심 설정 저장
- 채점 연결 설정 변경
- 라이선스 변경
- 그룹/반/대회/문제집 생성
- AI 평가 계획 생성 및 roster sync
- 게시판 토픽/답글 생성
- 제출 생성
- ops snapshot export

호출 방식:

- `oj_log_audit_event($event_type, $message, $context, $severity)`

### 11.2 Health Check

모듈 상태는 `health_check_provider`로 등록한다.

각 체크 항목 표준 필드:

- `component`
- `label`
- `status`: `ok|warning|error`
- `message`

### 11.3 Ops Dashboard

`oj-ops`는 최소한 아래를 보여야 한다.

- health summary
- 상세 health check 목록
- 설치된 패키지 목록
- 설치 순서 계획
- 최근 감사 로그
- 배포 snapshot export

## 12. 라이선스 규칙

### 12.1 라이선스 적용 대상

라이선스로 막을 기능은 명시적 feature key를 사용한다.

예:

- `ai_provider`
- `ai_assessment`
- `ai_dashboard`
- `board`
- `blockly`

### 12.2 라이선스 판단 원칙

- `trial`은 내부 검증 없이 허용 가능
- `active`는 가급적 `site binding + signature validation`을 통과해야 함
- `expired`, `suspended`는 차단

### 12.3 서명 규칙

라이선스 검증은 다음 구조를 따른다.

- canonical payload 생성
- 외부 private key로 서명
- Base64 signature 저장
- public key PEM으로 검증

## 13. 게시판 및 Blockly 규칙

### 13.1 게시판

- `context_type`, `context_id` 기반으로 문제/반/대회/전역 게시판을 지원한다.
- 토픽 상세와 답글은 프론트에서 가능해야 한다.
- 권한과 라이선스는 진입점에서 먼저 검사한다.

### 13.2 Blockly

- 외부 Blockly runtime URL을 설정 가능해야 한다.
- runtime이 없어도 최소 수동 코드 입력 fallback이 가능해야 한다.
- 생성 코드 -> `oj-problems` 제출 흐름을 재사용해야 한다.

## 14. 신규 모듈 제작 규칙

신규 `oj-*` 플러그인은 최소 다음을 포함한다.

- 메인 플러그인 파일
- `plugin-package.php`
- `src/Core/Autoloader.php`
- `src/Core/Plugin.php`
- 필요한 경우 `Database/Installer.php`
- 관리자 화면 또는 프론트 shortcode 중 최소 1개
- README

필요 시 추가:

- `Setup/Setup_Provider.php`
- `Support/Options.php`
- `Frontend/Shortcodes.php`
- `Admin/Settings_Page.php`
- `Ops/Health_Check_Provider.php`

## 15. 구현 Definition Of Done

다음 항목을 만족해야 “구현 완료”로 본다.

- 의존성 메타 존재
- 활성화 가드 존재
- 코어/계약 구조와 일치
- DB 설치 루틴 존재
- 관리자 또는 프론트 진입점 존재
- 빈 상태, 오류 상태, 성공 상태 처리
- 필요 시 setup provider 제공
- 필요 시 health check 제공
- 필요 시 audit log 연동
- PHP 문법 검사 통과

## 16. 배포 전 Definition Of Ready

다음 항목을 만족해야 “테스트 가능한 배포 후보”로 본다.

- 모든 관련 플러그인 `php -l` 통과
- 설치 순서 확인 가능
- `OJ Setup`에서 실값 입력 가능
- `oj-judge-adapter` 연결 테스트 가능
- 라이선스 상태 확인 가능
- ops dashboard에서 상태 확인 가능

## 17. 테스트 게이트

코드 구현 완료 후 필수 테스트는 다음 순서로 진행한다.

1. 플러그인 활성화 테스트
2. 테이블 생성 확인
3. Setup Toolbox 입력 테스트
4. Judge Adapter DB 연결 테스트
5. 문제 조회/제출 테스트
6. 반 컨텍스트 제출 테스트
7. 대회 컨텍스트 제출 및 스코어보드 테스트
8. AI provider 설정 테스트
9. AI assessment plan 및 target sync 테스트
10. 게시판 토픽/답글 테스트
11. Blockly submit 테스트
12. Ops health / audit / snapshot 테스트
13. License gating 테스트

## 18. 변경 관리 규칙

다른 장소나 다른 프로젝트에서도 다음 절차를 지킨다.

- 변경 전 영향 모듈 식별
- 하드 의존성 추가 여부 판단
- 코어 계약 재사용 가능성 우선 검토
- 직접 결합 대신 contract/adaptor 우선
- 새 실값 필요 시 setup provider 추가
- 새 운영 상태 필요 시 health check 추가
- 핵심 이벤트면 audit log 추가
- `php -l` 검증 후 커밋

## 19. 금지 목록

다음은 금지한다.

- HUSTOJ DB 직접 사용 복귀
- 채점엔진 스키마를 기능마다 직접 참조
- 신규 모듈에서 `plugin-package.php` 생략
- 컨텍스트 제출을 시간 범위 추정만으로 판정
- 실값 설정을 모듈 내부에만 고립
- 운영 상태를 로그 없이 조용히 바꾸는 행위
- 라이선스가 필요한 기능을 무조건 오픈하는 행위

## 20. 추천 운영 절차

권장 실무 절차:

1. 모듈 활성화
2. `OJ Setup`에서 실값 입력
3. `OJ Ops`에서 health 확인
4. 연결 테스트 수행
5. 관리자 흐름 테스트
6. 프론트 흐름 테스트
7. 라이선스 gating 확인
8. 배포 snapshot 저장

## 21. 현재 구현 상태 기준 결론

현재 하네스 기준으로 코드 구현은 완료 단계에 도달했다.
남은 것은 구현이 아니라 아래 항목이다.

- 실환경 설정 입력
- WordPress 실제 활성화 검증
- 채점엔진 실DB 연결 검증
- 통합/E2E 테스트

이 문서를 기준으로 다른 프로젝트에서도 `oj-*` 구조를 동일한 방식으로 제작·검수·배포 관리한다.