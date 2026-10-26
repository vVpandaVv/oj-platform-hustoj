# Plate 활동지 편집기 통합

<!-- HARNESS: plate-whole-editor-runtime -->

## 범위

6단계는 활동지 템플릿 편집 화면에 Plate 기반 편집기 구조를 통합하는 단계다. 기존 Plate 템플릿 빌더 소스는 `resources/plate/whole-editor`에 물리적으로 병합하고, 빌드 산출물은 `resources/assets/builder`에서 WordPress 화면에 로드한다. `resources/js/worksheet-editor.js`는 편집기를 다시 만들지 않고 템플릿 목록, 저장, 가져오기, 내보내기 컨트롤러 역할만 담당한다.

## 유지해야 하는 Plate 구조

- `PlateProvider`
- `EditorShell`
- `Toolbar`
- `FloatingToolbar`
- `SlashMenu`
- `ContextMenu`
- `PortalRoot`

개별 Plate 패키지를 골라 유사 편집기를 새로 조립하지 않는다. 커스텀 입력 블록, 스키마 추출, 저장 연동, 템플릿 가져오기/내보내기는 위 구조 위에 확장한다.

## 서버 스키마

`src/Worksheet/Plate_Document_Service.php`는 Plate 원본 문서에서 응답 블록을 추출해 다음 스키마를 생성한다.

- `teacher_schema`: Plate 원본, 배점, 정답, 예시답안, 교사용 메모, 루브릭 포함
- `student_schema`: 학생 입력에 필요한 구조만 포함하고 배점, 정답, 예시답안, 교사용 메모, 루브릭 제외
- `grading_schema`: 채점에 필요한 정답, 예시답안, 배점, 루브릭 포함

각 응답 블록에는 `question_id`와 `block_id`를 유지한다. 명시 ID가 없으면 Plate 노드 정보와 위치를 기준으로 안정적인 해시 ID를 생성한다.

## 입력 블록

입력 블록 기준은 `docs/input-blocks.md`에 둔다. Plate 노드는 편집기 전체 구조를 유지한 상태에서 `hws_response` 원본 노드와 `ojai_response` 호환 노드를 모두 지원하고, 학생 답안 저장은 `answer_schema`와 REST 정규화 서비스를 기준으로 처리한다.

6단계에서 Plate 삽입 컨트롤은 단답, 서술, 단일 선택, 복수 선택, 체크, 숫자, 표, 이미지, 파일, 그림 응답을 지원한다. 표 열은 `열 이름|입력유형|선택지` 형태로 정의할 수 있으며 입력유형은 `text`, `number`, `checkbox`, `single_choice`, `multiple_choice`를 지원한다.

## REST

- `GET /oj-ai/v1/worksheets`
- `POST /oj-ai/v1/worksheets`
- `GET /oj-ai/v1/worksheets/{id}`
- `POST /oj-ai/v1/worksheets/{id}/duplicate`
- `POST /oj-ai/v1/worksheets/{id}/archive`
- `POST /oj-ai/v1/worksheets/{id}/restore`
- `DELETE /oj-ai/v1/worksheets/{id}`
- `POST /oj-ai/v1/worksheets/{id}/template-export`
- `POST /oj-ai/v1/worksheets/template-import/preview`
- `POST /oj-ai/v1/worksheets/template-import/commit`

학생 조회는 `view=student`일 때만 허용하며, 이 응답은 `student_schema`만 반환한다.

## 검증

자동 검증은 `npm run check:plate`와 `npm run e2e:plate`에서 수행한다.

- Plate 전체 구조 소스 존재
- 실제 빌드 산출물 `resources/assets/builder/plate-template-builder.js`, `plate-template-builder.css` 존재
- WordPress 화면의 `ws-plate-template-builder-root`, `ws-builder-schema`, `ws-render-schema` 연결 존재
- 템플릿 컨트롤러가 Plate hidden schema를 읽고 저장 payload로 전달함
- 서버의 `teacher_schema`, `student_schema`, `grading_schema` 생성기 존재
- REST 경로 존재
- 실제 브라우저에서 `contenteditable` 편집 영역과 응답폼 삽입 동작 확인
- 금지된 플랫폼명 유입 여부 확인

브라우저 점검은 `tests/fixtures/plate-editor-visual.html`에서 데스크톱과 모바일 폭으로 확인한다. `npm run e2e:plate`는 fixture, CSS, 실제 Plate 빌드 산출물을 검사하고, Playwright가 설치된 환경에서는 실제 브라우저 레이아웃과 응답폼 삽입까지 확인한다.
