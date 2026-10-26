# Addon API 1.7: 제출 화면 초안 전달

OJ Platform 2.4.3은 `AddonSubmissionDraftRuntimeContextV1` 선택형 확장을 제공한다. 기존 `AddonRuntimeContextV1` 구현에 필수 메서드를 추가하지 않으며 Addon API 1.6 애드온도 계속 사용할 수 있다.

## 공개 계약

- `AddonSubmissionDraftRuntimeContextV1::submissionDrafts(): AddonSubmissionDraftGatewayV1`
- `AddonSubmissionDraftGatewayV1::prepare(AddonSubmissionDraftRequestV1): AddonSubmissionDraftResultV1`
- 요청 생성자: 현재 `actorUserId`, `problemId`, `contextType` (`class` 또는 `group`), 숫자 `contextId`, `language` (`python`, `c`, `cpp`, `java`), `source`.
- 결과는 `isSuccessful()`, `payload()`, `problem()`으로 확인한다. 성공 payload는 `url`, `language`, Unix 초 단위 `expires_at`이다. 코드 원문은 결과 URL에 포함하지 않는다.

애드온은 학생이 제출 화면을 열겠다고 선택한 시점에 nonce·현재 참여 권한을 확인하고 최신 초안을 저장한 뒤 호출한다. PRIMM은 저장 버전을 검사해 제작 단계의 본인 저장 코드와 불변 수업 언어를 전달한다. 일반 조회·결과 폴링 때 초안 전달 링크를 새로 발급하지 않는다.

## 코드 보관과 접근

플랫폼은 기존 암호화 FormDraft 저장소에 코드를 보관하고 짧은 불투명 참조를 가진 `/submit/{problemId}` 링크를 반환한다. 참조는 로그인한 본인·문제·학급 또는 그룹·언어에 묶이며 300초 후 만료된다. 같은 본인은 유효기간 동안 같은 링크를 다시 열 수 있어 제출 화면 새로고침도 지원한다. 만료 자료는 기존 FormDraft 정리 경로를 사용한다.

발급은 현재 활성 계정당 60초에 12번으로 제한한다. 이미 준비한 링크를 다시 여는 조회는 발급 횟수에 포함하지 않는다.

준비 시점과 제출 화면을 여는 시점에 현재 계정, 공간 참여와 문제 열람, 전체 코드 작성 방식, 허용 언어, 코드의 UTF-8·NUL·크기 제한을 검사한다. 배열·빈칸 방식의 문제를 전체 코드 제출로 우회하지 않는다. 코드의 들여쓰기·공백·개행·한글과 HTML처럼 보이는 텍스트는 실행하지 않고 편집기 값으로 전달한다.

## 제출 효과와 편집

초안 전달은 기존 OJ 제출 폼의 초기 코드와 언어를 채운다. 제출 번호·채점 요청·점수를 만들지 않으며 학생이 기존 **제출하기** 버튼을 눌렀을 때만 기존 제출 서비스가 실행된다. 학생은 제출 전에 코드를 편집할 수 있다. OJ 화면에서 편집한 코드를 원래 애드온의 초안에 자동으로 덮어쓰지 않는다.

팝업 차단 대체 링크에도 같은 전달 URL을 사용한다. 링크가 만료되거나 애드온의 초안이 바뀌었다면 최신 저장본으로 다시 준비해야 한다. 재준비는 PRIMM의 기존 제출 감시 기준선을 임의로 변경하지 않는다.
