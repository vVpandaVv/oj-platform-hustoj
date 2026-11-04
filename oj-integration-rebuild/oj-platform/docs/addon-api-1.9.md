# Addon API 1.9: 기본 문제·수업 메뉴 연결

OJ Platform 2.5.9 / Addon API 1.9.0부터 사용할 수 있다. 기존 필수 인터페이스와 이전 생성자 호출은 유지된다. 등록 시 actor에 따라 manifest를 바꾸지 않는다.

## 네이티브 목록 제공자

등록 builder가 AddonNativeLearningManifestBuilderV1이면 nativeLearningProvider(AddonNativeLearningProviderDefinitionV1)로 기여한다. 제공자 key는 addon.<namespace>.<name>이며 애드온당 최대 4개다. 호스트는 승인된 제공자를 order/key 순으로 정렬하고 한 화면에서 최대 4개만 호출한다.

    $builder->nativeLearningProvider(new AddonNativeLearningProviderDefinitionV1(
        'addon.example.catalog', '마이크로비트', $provider, 50
    ));

AddonNativeLearningProviderV1::query(AddonNativeLearningQueryV1): AddonNativeLearningPageV1를 구현한다. Query는 현재 로그인 actor ID, surface, contextId, beforeId, limit을 제공한다. actor는 요청 파라미터로 선택할 수 없다.

| surface | contextId | 용도 |
| --- | --- | --- |
| problems.public | 0 | 기본 공개 문제 목록 |
| problems.teacher | 0 | 기본 교사 문제 목록 |
| class.student | 수업 ID | 기본 수업의 학습자 과제 |
| class.teacher | 수업 ID | 기본 수업의 교사 과제·적용·결과 작업 |

호스트는 limit 20으로 호출한다. 계약은 1~50을 허용하며 제공자는 항상 limit을 준수한다. beforeId=0은 첫 페이지다. 이후 페이지는 제공자별 독립 query key의 내림차순 keyset cursor를 사용한다. nextBeforeId는 양수/null이며 이후 페이지에서 현재 cursor보다 작아야 한다. 총 개수 COUNT와 OFFSET은 요구하지 않는다. 한 수업의 메타데이터를 한정 조회하고 각 행에서 문제/결과/프로젝트를 다시 조회하는 N+1을 만들지 않는다. 소스, HEX, 시나리오 JSON, 해설 또는 채점 비밀은 목록 DTO에 싣지 않는다.

Page 생성자: (items=[], nextBeforeId=null, actions=[], available=true, message=''). 최대 50개 행과 4개 페이지 작업을 허용한다. 빈 수업이라도 교사에게 과제 적용/결과 링크를 actions로 제공할 수 있다. unavailable page는 행/작업/cursor를 포함하지 않는다.

Item 생성자: (externalId, title, typeLabel, primaryLink, secondaryLinks=[], statusLabel=''). externalId는 <manifest namespace>:<kind>:<local id>처럼 애드온 namespace로 시작해야 한다. 일반 프로그래밍 문제 ID와 합치거나 judge route에 대입하지 않는다. 목록에는 짧은 로컬 번호를 보여 주며 전체 ID는 행 메타데이터로 유지한다. 상태에는 공개 여부·읽기 전용·보관 상태 등 사용자에게 필요한 짧은 정보를 넣는다. 보관된 항목의 링크 권한도 제공자와 실제 대상 페이지가 다시 검사한다.

Link 생성자: (routeKey, label, parameters=[], query=[]). 같은 애드온이 선언한 public/console **페이지 route**만 허용한다. 외부 URL, 다른 애드온 route, action route, 원시 HTML을 전달할 수 없다. 호스트는 등록 URL resolver 및 현재 페이지 권한을 재검사하고 메타데이터를 escape한다. 제공자 예외·잘못된 ID·비정상 cursor·unsafe URL은 해당 섹션의 짧은 오류 안내로 격리한다.

기본 문제 목록은 전체 유형/프로그래밍/제공자 유형을 표시한다. 프로그래밍 총 개수는 프로그래밍 문제에만 해당한다. 외부 유형만 선택하면 기존 프로그래밍 COUNT/OFFSET/메타데이터 쿼리를 실행하지 않는다. 애드온을 비활성화하거나 권한이 없으면 기존 기본 목록 동작을 유지한다. 익명 사용자에게는 이 로그인 전용 제공자를 호출하지 않는다.

## 상세 화면 메뉴 및 URL

AddonPublicPageDefinitionV1과 AddonConsolePageDefinitionV1의 선택적 마지막 인자 navigationMenuKey는 problems.index 또는 classes.index를 받는다. 문제 상세와 수업 과제 상세를 별도 route로 선언하여 올바른 상위 메뉴를 선택한다. 이 별칭은 shell 표시만 바꾸며 요청의 실제 route, path, 인증, 권한을 바꾸지 않는다.

AddonPageRequestV1::navigationUrls()는 현재 사용자가 허용받은 같은 애드온 페이지 key의 등록 경로와 기본 problems.index/classes.index 경로를 제공한다. console에서는 접근 가능한 기본 메뉴 경로만 포함한다. 각 URL은 WordPressRouteTransport로 변환한 브라우저용 내부 절대 경로다. 루트 설치와 /school 같은 하위 경로 설치를 모두 반영하며 JSON 설정에 담기기 전에 변환한다. 마지막 HTML 변환 단계에서도 설치 경로를 중복해서 붙이지 않는다. shell 메뉴 선택과 권한 판정의 내부 원래 경로는 별도로 유지한다. hardcoded WordPress 설치 경로나 클라이언트 URL을 사용하지 않는다. 선택적 7번째 생성자 인자의 기본값은 빈 배열이다.

AddonConsoleMenuDefinitionV1의 선택적 마지막 인자 fallbackForNativeMenu도 위 두 native menu key를 받는다. 해당 기본 메뉴가 실제 feature/handler/권한/담당 범위 규칙을 통과하면 대체 메뉴를 숨긴다. 애드온 전용 교사처럼 기본 메뉴에 접근할 수 없는 사용자는 자신의 애드온 권한으로 대체 메뉴와 상세 선택 상태를 유지한다. 기본 문제·수업의 조회/작성/POST 권한을 새로 부여하지 않는다.

## 권한 및 정책 경계

제공자는 매 query에서 actor, 공개 상태, 해당 수업의 현재 참여/관리 권한을 다시 확인한다. 호스트의 native 수업 화면도 기존 ClassService 권한을 먼저 검사한다. 기존 PerformanceModePolicy가 학습자의 상세 조회를 제한하면 새 수업 개요/현황/내 제출 섹션의 제공자를 호출하지 않고 확장 메타데이터와 결과 링크도 표시하지 않는다. 관리자는 기존 수업 관리 권한을 유지한다.

이 연결은 기존 애드온 직접 URL의 별도 접근 정책을 변경하거나 기본 평가 제한을 그 URL 전체에 적용하는 새 정책 게이트가 아니다. 실제 상세·채점·결과의 권한은 각 애드온의 기존 서비스가 계속 담당한다.

DB 스키마 변경과 judge 데이터 쓰기는 없다. 데이터 저장과 채점 서버는 각 애드온에 남고, 호스트는 표시용 메타데이터와 등록된 내부 링크만 소비한다.

검증: tests/Contract/addon-native-learning.php는 DTO·actor·권한·URL·cursor·최대 제공자 수·쿼리 생략·메뉴 선택을 검사한다. tests/Unit/class-management.php는 기본 수업의 권한 차단, 저장 전 조회 생략, 수행 모드의 새 확장 조회 차단을 검사한다.
