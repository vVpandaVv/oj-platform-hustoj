# AGENTS.md (OJ AI Assessment Add-on)

이 파일은 `oj-ai-dashboard` 저장소에서 작업하는 에이전트의 운영 규칙이다. 제품 설계와 결정의 원본은 Markdown이 아니라 `docs/ai-assessment/*.html`이다.

## 1. 정본 문서

- 시작점: `docs/ai-assessment/index.html`
- 설계 절차: `docs/ai-assessment/design-procedure.html`
- 코드 일관성: `docs/ai-assessment/code-consistency.html`
- 현황 조사: `docs/ai-assessment/a-d-001-*.html`부터 `a-d-009-*.html`
- 상세 설계 시작점: `docs/ai-assessment/a-ds-00-scope-baseline.html`
- 상세 구현 진행표: `docs/ai-assessment/implementation-roadmap.html`
- 현재 구현 증거: `docs/ai-assessment/g2-imp-02-s01-plate-runtime-entrypoint.html`
- 플랫폼 제공자 계약: `../oj-integration-rebuild/oj-platform/docs/development-c-28-addon-extension-api.html`
- 플랫폼 통합 동결: `../oj-integration-rebuild/oj-platform/docs/development-ds-16-integration-freeze.html`

기존 `docs/*.md`와 현재 runtime 코드는 조사 증거일 수 있지만 목표 설계를 결정하지 않는다. 새 제품 설계 문서와 결정 기록은 같은 HTML 탐색 구조에 추가한다. Markdown은 `AGENTS.md`, `_notes/*`, README 같은 운영·역사 기록에만 사용한다.

## 2. 현재 단계

- `A-D-001~A-D-009` 현황 기준선은 OJ Platform `2.0.0-dev.26` 기준으로 2026-08-10 사용자 확인·동결되었다.
- `A-DS-00~A-DS-16`은 사용자 확인·동결되었고 G0 플랫폼 API 인수를 마쳤다.
- G1 `IMP-01~IMP-09`의 Manifest, Composition Root, 45-table DB 기반, 활성화, Runtime 검산, 공통 접근 Policy·REST foundation과 교사 Context 목록 Q01 계약이 통과했다.
- `G1-REST-GAP-01`은 일반 cursor 191자를 유지하고 Q01에만 512자 endpoint profile을 적용해 해결했다.
- `G2-IMP-01` C01·Q04~Q07과 보완 `R01~R05`가 통과했다. 활성 REST 업무 경로는 Context Q01과 활동지 생성·목록·root·revision 조회다.
- 현재 플랫폼 관찰값은 `2.0.0-dev.57`, Addon API `1.1.0`·37 symbol이다. 플랫폼 dirty fingerprint는 승인하지 않으며 공개 API 변경은 발견되지 않았다.
- `G2-IMP-02-S01`에서 Plate 53.1.2 package·Vite build 재현과 legacy wp-admin·OJ Console·새 REST 진입점 차이를 확인하고 재사용·교체 경계를 고정했다.
- `G2-IMP-02-S02`에서 C02 `expected_revision_id`, canonical request digest, actor·operation·worksheet 범위의 멱등 key, 200·201·409 receipt를 command·result DTO와 fixture로 고정했다. C02 route·Repository write는 아직 열지 않았다.
- 다음 구현은 `G2-IMP-02-S03` `worksheet_plate@1` node 16종·mark·응답칸·한도 validator와 canonicalizer다.
- 구현은 동결된 G0~G6 순서와 수직 기능 관문을 따르며, 각 단계의 코드·계약 테스트·HTML 기록을 함께 갱신한다.
- 현재 runtime의 미완성 상태를 목표 계약으로 승격하지 않는다.

## 3. 변경 경계

- 애드온 설치·업데이트·실행은 `oj-platform` 파일, config, migration, schema, option을 수정하거나 복사하지 않는다.
- 애드온 작업에서 플랫폼 runtime은 읽기 전용이다. 플랫폼 범용 API 변경은 명시적으로 분리된 플랫폼 제공자 작업과 문서·테스트를 통해서만 진행한다.
- OJ Platform이 없을 때 독립 실행, 별도 wp-admin 메뉴, shortcode fallback을 목표 구조로 만들지 않는다.
- 교사·관리자 기능은 OJ Console, 학생 기능은 PublicUI에 명시적으로 각각 등록한다.
- 애드온 소유 물리 테이블은 WordPress prefix 없이 고정 `oj_ai_*` 이름을 사용한다. DB 접근은 소유 Repository를 통한다.
- 플랫폼 원장과 Judge 원본은 공개 Gateway를 통한 조회만 허용하며 애드온에서 쓰지 않는다.

## 4. 제품 소유권

- 플랫폼: 사용자·수업·학급·그룹 원장, capability 배정, AI 연결 프로필·비밀값·모델 routing, 공통 AI 작업 실행 기반.
- 애드온: 활동지·배포·제출, 평가 정책·루브릭, 활동지 자체 AI 평가, 학생 제출물 AI 평가, 교사 검토·확정·공개, 보고서·PDF.
- 활동지 자체 평가와 학생 제출물 평가는 서로 다른 subject, input/output schema, 이력으로 설계한다.
- 활동지 편집기는 Plate 53.1.2 기반의 선택형 `worksheet_plate@1` profile을 사용하며 교사용 원본, 학생용 schema, 채점용 schema를 분리한다.
- 활동지와 학생 작성 결과물 PDF는 불변 revision snapshot에서 생성하고 private 권한으로 제공한다.

## 5. 작업 절차

1. `git status`와 관련 HTML의 현재 단계·결정을 확인한다.
2. 읽을 플랫폼·애드온 소스와 쓸 파일 범위를 먼저 고정한다.
3. 현재 단계의 미결정 계약, 대안, 권장안, 실패 정책, 검증 기준을 HTML에 기록한다.
4. 관련 HTML의 탐색 링크·상태·추적 ID를 함께 갱신한다.
5. 설계 단계에서는 runtime을 수정하지 않았는지 source fingerprint 또는 scoped diff로 확인한다.
6. 아래 검증을 실행하고 통과·실패·미실행을 구분해 기록한다.

## 6. 검증 명령

```powershell
npm test
npm run check:access-policy
npm run check:rest-foundation
npm run check:worksheet-g2
npm run check:release-boundary
.\scripts\validate-plugin-harness.ps1
npm run audit:legacy
npm run audit:boundary
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\check-quality.ps1 -Strict
```

- `npm test`: 공개 API 변화는 실패시키고 플랫폼 전체 dirty·제품 버전 drift는 구현 경고로 분리한 HTML·계약 회귀 게이트.
- `check:rest-foundation`: 서버 actor, 표준 `wp_rest`, 공통 Policy, 안정 오류, correlation, POST 멱등키와 미완성 route 0건을 검사한다.
- `validate-plugin-harness.ps1`: 정본 문서, UTF-8, PHP 문법, 무접두어 테이블, 금지 runtime 문구와 clean 플랫폼 release-baseline을 검사한다.
- `audit:legacy`: 폐기한 구형 런타임·자산의 재유입과 남은 코드의 참조를 검사한다. 현재 데이터 이관·문서 호환 처리는 유지 대상이다.
- `audit:boundary`: 애드온과 플랫폼 source 범위를 읽기 전용으로 fingerprint하고 공유 작업공간의 기준선 이동을 드러낸다.
- Strict 품질 검사: PHPCS, PHPStan, PHPUnit, Node 도구가 없거나 검사가 실패하면 출시 불가.

## 7. 코드 규칙

- PHP namespace는 `OJAI\Dashboard`를 유지한다.
- 새로 작성하거나 수정하는 1차 소유 파일은 UTF-8 BOM 없이 저장한다. 기존 Plate 공급원·생성물의 인코딩 부채는 runtime 구현 단계에서 별도 정리한다.
- 사용자 화면에 개발 설명, 구현 메모, 내부 prompt, 비밀값을 노출하지 않는다.
- 학생 응답에는 정답, 예시 답안, 배점, 교사 메모, AI 원본 응답을 포함하지 않는다.
- 변경은 모듈 소유권과 수직 흐름에 맞게 작게 나누고 관련 테스트를 함께 작성한다.
