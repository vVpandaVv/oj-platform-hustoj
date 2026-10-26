# 루브릭과 성취기준 구현 기준

문서 상태: 확정
대상 플러그인: `oj-ai-dashboard`

<!-- HARNESS: rubric-curriculum -->

## 1. 루브릭 정책

활동지의 루브릭 모드는 `worksheet` 또는 `question` 중 하나만 사용할 수 있다.

- `worksheet`: 활동지 전체 루브릭만 저장하고 문항 루브릭은 스키마 생성 시 제거한다.
- `question`: 문항별 루브릭만 저장하고 활동지 전체 루브릭은 스키마 생성 시 제거한다.
- 혼합 사용은 런타임 스키마와 저장 데이터 양쪽에서 허용하지 않는다.
- 활동지 전체 루브릭 모드에서는 저장되는 Plate 문서의 문항 루브릭 필드도 제거한다.
- 문항별 루브릭은 `question_id`를 기준으로 매핑한다.
- 문항별 모드에서 채점 대상 문항에 루브릭이 없으면 채점 전 경고에 사용할 `missing_question_rubrics`를 남긴다.

## 2. 성취기준 연결

성취기준은 활동지 단위와 문항 단위로 연결할 수 있다.

- 활동지 연결은 `worksheet_achievement_links`에 저장한다.
- 문항 연결은 `question_achievement_links`에 `question_id` 기준으로 저장한다.
- 활동지 저장 시 Plate 스키마의 `curriculum_links`와 각 문항의 `curriculum_links`를 링크 테이블과 동기화한다.
- REST에서는 활동지별 성취기준 연결 ID를 조회하고 교체할 수 있어야 한다.

## 3. 특기사항 컨텍스트

성취기준 본문과 교육과정 설명은 특기사항 생성 컨텍스트에서 사용한다.

- 채점 컨텍스트에는 성취기준 본문을 기본 포함하지 않는다.
- 채점 결과 추적용으로는 성취기준 ID만 사용할 수 있다.
- 특기사항 컨텍스트는 활동지 단위 성취기준과 문항별 성취기준을 분리해서 제공한다.
- 문항별 컨텍스트는 `question_id` 키를 유지해야 한다.

## 4. REST 범위

- `GET /oj-ai/v1/curriculums`
- `POST /oj-ai/v1/curriculums`
- `GET /oj-ai/v1/achievement-standards`
- `GET /oj-ai/v1/curriculums/{id}/standards`
- `POST /oj-ai/v1/curriculums/{id}/standards`
- `GET /oj-ai/v1/worksheets/{id}/achievement-links`
- `POST /oj-ai/v1/worksheets/{id}/achievement-links`
- `GET /oj-ai/v1/worksheets/{id}/curriculum-context`

교육과정과 성취기준 관리는 `MANAGE_CURRICULUM` 권한을 사용한다. 활동지별 연결 조회와 수정은 `MANAGE_WORKSHEETS` 권한을 사용한다.
