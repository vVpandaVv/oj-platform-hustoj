# 아키텍처와 상태 흐름

## 경계

- **학생 브라우저**: 데이터 로딩, 전처리, 학습, 예측, OJ 사용자별 AES-GCM IndexedDB vault, MLPX 생성
- **OJ Platform**: add-on 수명주기, 화면 shell, capability, 수업/그룹 Context, 현재 명단과 참여 권한
- **OJ ML Playgrounds 플러그인**: 과제/명단 snapshot, 시도, MLPX 원본, 사전검사, 교사 평가, 감사 이벤트

플러그인은 OJ Platform 내부 클래스나 테이블에 의존하지 않고 Addon API 1.1 공개 계약만 사용합니다. Runtime 또는 Context gateway가 준비되지 않으면 보호된 요청은 fail-closed로 거부됩니다.

## 공용 PC 부팅·전환 경계

```text
현재 WordPress 인증 확인
→ no-store storage context(scope/session/key) 발급
→ iframe ready + 128-bit frameNonce
→ bridge v2 configure-once 검증
→ 사용자 scope 설정 + legacy 전역 DB 삭제
→ scope별 Web Lock 공유 lease 획득
→ Pinia/router/app mount
→ scope별 암호화 vault만 열기
```

scope와 키는 사이트 ID와 WordPress 사용자 ID에 HMAC으로 결합하지만 브라우저에는 원시 ID를 노출하지 않습니다. scope와 키는 같은 사이트·사용자에서 안정적이므로 재로그인 후 자기 프로젝트를 복원할 수 있고, 세션 지문은 로그인 cookie session이 바뀔 때 회전하여 열린 이전 탭을 잠급니다. 이 값은 서버 권한이나 제출 소유권 판정에 사용하지 않습니다.

부모의 45초 주기 검사는 iframe을 숨기거나 포커스를 요구하지 않는 silent background verify입니다. 정상 응답을 기다리는 동안 현재 프로젝트 화면과 내부 상태를 유지하고, scope·세션·키 불일치 또는 인증 오류가 확정된 경우에만 iframe을 CSS로 가린 뒤 `about:blank`로 전환하고 DOM에서 제거합니다. 문서가 hidden 상태에서 visible로 복귀할 때에는 먼저 보호막을 적용한 strict verify가 성공해야 같은 iframe을 다시 표시합니다. terminal 실패와 `storage-revoked`는 자동 page reload를 예약하지 않으므로 포커스를 잃은 순간의 검증이 빈 화면이나 reload loop로 고착되지 않습니다.

임베디드 툴바의 `OJ에 제출하기`는 현재 메모리 프로젝트를 `.mlpx` Blob으로 직렬화해 같은 origin의 제출 REST endpoint로 바로 보냅니다. Blob은 부모 window나 다운로드 폴더를 거치지 않습니다. 부모 화면의 수동 `.mlpx` 업로드는 접힌 장애 대응용 fallback이며 기본 제출 흐름이 아닙니다.

강제 종료로 명시적 삭제가 실행되지 않아도 다음 사용자는 이전 scope의 키를 받지 못하므로 암호문을 정상 UI에서 열 수 없습니다.

각 iframe은 scope별 Web Lock 공유 lease를 가진 상태에서만 저장 연산을 시작합니다. 삭제 요청은 BroadcastChannel로 모든 탭의 scope를 즉시 폐기하고, raw key와 CryptoKey 참조를 비운 뒤 진행 중 연산·DB 연결이 정리될 때까지 기다립니다. 그 다음 배타 Web Lock 안에서 IndexedDB를 삭제하므로 성공 응답 뒤 늦은 자동저장이 DB를 다시 만드는 경합을 막습니다. BroadcastChannel 또는 Web Locks가 없으면 공용 PC 저장소는 열리지 않습니다.

같은 origin의 관리자 권한 코드나 개발자 도구까지 막는 별도 origin/OS sandbox는 아닙니다. 강한 적대적 사용자 모델에서는 브라우저 프로필 격리 또는 자동 초기화 OS가 추가 경계입니다.

## 제출 상태

```text
in_progress -> uploading -> quarantined
                              |-> accepted -> under_review -> graded
                              |-> needs_resubmit
                              `-> system_pending
```

- `accepted`는 MLPX 구조·무결성·과제 정책의 사전검사를 통과했다는 뜻이며 모델의 학업적 정답을 뜻하지 않습니다.
- `needs_resubmit`은 학생이 새 시도를 생성해 다시 제출할 수 있는 입력 문제입니다.
- `system_pending`은 ZIP 확장 부재나 검사 인프라 문제처럼 학생 책임으로 확정할 수 없는 상태입니다.
- 교사 평가는 append-only version으로 저장되며 공개된 최신 평가만 학생에게 보입니다.

## 과제 수명주기와 학생 목록 투영

`draft`는 게시 전에 수정·삭제할 수 있습니다. `published`에서는 제목, 설명, 마감 시각과 최대 시도 횟수만 변경할 수 있고 최대 시도 횟수는 감소시킬 수 없습니다. 과제 유형과 제출 정책, 게시 시점의 명단·Context snapshot은 고정됩니다.

보관은 과제나 제출 데이터를 삭제하는 상태가 아니라 학생의 활성 목록에서 제외하는 투영입니다. 마감된 과제는 해당 학생의 제출 이력이 없으면 목록에서 제외하고, 제출 이력이 있으면 접힌 **과거 제출 결과**에 투영합니다. 시도·artifact·검사·평가와 교사 결과는 이 학생 목록 필터와 무관하게 보존됩니다.

## 학생별 결과 투영

결과 화면의 기준 집합은 제출 테이블이 아니라 과제 생성 시 저장한 학습자 명단 snapshot입니다. 따라서 제출하지 않은 학생도 `미제출` 행으로 표시되고, 제출한 학생은 최신 접수 제출과 검사·평가 결과가 연결됩니다. 더 나중에 시작했지만 제출하지 않았거나 취소한 시도는 이전 제출을 가리지 않습니다. 요청 시 현재 OJ Context 권한도 다시 확인하므로 명단 snapshot만으로 접근을 허용하지 않습니다. 기본 표는 학생별 최신 접수 제출을 표시하며 전체 제출 이력은 학생별 페이지 조회로 제공합니다. 접수된 자기 기록은 명단 탈퇴 뒤에도 조회할 수 있고 교사 조회는 현재 수업 권한을 검사합니다.

교사용 요약 DTO는 검사 facts/findings의 허용 필드만 투영합니다. 내부 storage key·실경로, `projectId`, dataset path, 엔트리별 해시와 finding params는 응답하지 않습니다. REPORT 전용 사용자는 요약과 점수 상태만 받고 평가 초안 피드백, 안전 바로보기와 원본은 받지 않습니다.

안전 바로보기는 REVIEW/MANAGE capability, actor-bound POST nonce와 시도 소유 수업 Context의 `evaluate` 권한을 모두 다시 확인한 뒤에만 지연 호출합니다. 서버는 비공개 저장소에서 연 파일의 정확한 바이트 수와 SHA-256을 확인하면서 권한 `0600` 임시 사본을 만들고, ZIP 경로·중복·암호화·압축률·크기 제한을 사전검사합니다. 그 사본에서 `manifest.json`, `settings.json`, `runs.json`, `portfolio/document.json`을 읽어 허용 목록 DTO로 변환합니다. 1.4.0부터 명시 참조된 UTF-8 CSV도 서버 안에서 제한된 스트림으로 읽어 통계를 집계하지만 원본 행·범주 값·경로는 응답이나 외부 AI 입력에 포함하지 않습니다. 모델·전처리기·이미지·첨부파일은 열거나 실행하지 않습니다. 화면의 성능 지표는 제출 파일에 기록된 값이며 서버가 모델을 실행하거나 재계산한 결과가 아닙니다.

통계는 전처리 전 CSV와 현재/과거 실험의 연결을 기록합니다. 시간 제한으로 달라지는 부분 집계는 외부 AI 입력에서 고정된 확인 불가 상태로 정규화합니다. 데이터 설명·통계·항목별 근거 상태는 기존 AI 계약의 프로젝트 근거와 교사 기준에 포함되어 입력 해시와 함께 변경됩니다. 필요 근거가 누락되면 AI 점수를 비우고 교사 확인 상태를 반환하며, 적용 API도 같은 서버 근거를 다시 검증합니다. 교사 설명과 학생 답변은 별도의 출처로 표시하며 설명의 진실성이나 통계만으로 데이터 누수 부재를 보증하지 않습니다.

원본 MLPX 자체는 REST JSON/Blob이나 same-origin iframe으로 렌더링하지 않습니다. 같은 REVIEW/MANAGE와 Context 검사를 통과한 경우에만 검증된 원본을 고정 ASCII 파일명의 attachment로 chunk 전송합니다. 안전 바로보기와 원본 다운로드의 허용 상태는 `accepted`, `under_review`, `graded`입니다.

## 1.0 운영 경계

스키마 3은 InnoDB, 전용 DB의 단일 WordPress 설치를 전제로 합니다. 설치 소유권은 이벤트에 prefix로 기록하고 프런트 요청에서는 DDL을 실행하지 않습니다.

접수 파일 연결·접수 시각·감사 이벤트는 하나의 DB 트랜잭션입니다. COMMIT 결과가 불명확하면 파일을 삭제하지 않고 조정 점검 대상으로 남깁니다. 검사는 바이트 수·SHA-256을 대조한 임시 사본으로 실행하며 연결 범위 advisory lock으로 동시 실행을 제한합니다. 대기 검사는 최대 5회 자동 재시도합니다.

개인별 허용은 기존 과제 기본값과 개인 확장값 중 큰 시도 수/늦은 마감을 사용하고 별도 revision으로 동시 수정을 거부합니다. 평가와 제출 이력은 덮어쓰지 않습니다.

공개 Addon API 1.1에는 ops/setup/감사 contribution이 없으므로 OJ 내부 테이블·클래스를 호출하는 대신 WordPress 도구·Site Health·REST·WP-CLI·개인정보 훅을 제공합니다. 공개 집계 API가 추가되기 전까지 oj-ops 직접 통합은 하지 않습니다.

## MLPX 신뢰 모델

`hashes.json`은 전송 중 손상과 내부 불일치를 찾는 무결성 자료이며 전자서명이 아닙니다. 학생이 파일과 해시를 함께 다시 만들 수 있으므로 서버는 허용 형식/과제 유형/데이터 유형/알고리즘, 실제 CSV 레코드 수와 선택 특성 수 같은 정책 사실을 독립적으로 계산합니다. 이 검사는 악성 ZIP 방어와 제출 형식 확인을 위한 것이며, 모델 성능과 학업적 타당성은 교사 평가 영역입니다.
