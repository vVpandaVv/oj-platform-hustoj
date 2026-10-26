# OJ AI Dashboard 기존 플러그인 통합 기준

문서 상태: 확정  
작성일: 2026-06-14  
대상 단계: 7단계 이후 전체 구현

<!-- HARNESS: legacy-plugin-integration -->

## 1. 목적

이 문서는 기존 AI 대시보드, 활동지 제출, AI 채점 플러그인의 기능을 `oj-ai-dashboard`에 물리적으로 복사한 뒤 하나의 업무 흐름으로 통합하기 위한 기준이다.

통합 목표는 세 기능을 독립 메뉴로 병렬 배치하는 것이 아니라 다음 흐름으로 합치는 것이다.

```text
활동지 작성 -> 배정 -> 작성/자동저장 -> 제출/업로드 -> 채점 -> 변경 검토
-> 피드백 승인/공개 -> 특기사항 생성 -> 보고서/대시보드
```

## 2. 소스 인벤토리

기존 소스는 다음 기능 묶음으로만 식별한다.

| Source key | 기존 기능 | 대상 모듈 |
|---|---|---|
| `legacy-dashboard` | 학생/교사 대시보드, 성취/리포트 분석, 프롬프트 | `Dashboard`, `Reports`, `Curriculum`, `AI` |
| `legacy-worksheet-submission` | 활동지 템플릿, Plate 빌더, 배정, 제출, PDF 렌더링, 자동 제출 | `Worksheet`, `Submission`, `Reports`, `Export` |
| `legacy-assessment` | AI 채점, 업로드/ZIP, 루브릭, 피드백, 특기사항, 학생 점수/피드백 shortcode | `Assessment`, `Feedback`, `SpecialNote`, `Import`, `Compatibility` |

기존 플러그인 폴더명, namespace, option, table, shortcode 접두사는 새 런타임 이름으로 그대로 가져오지 않는다.

## 3. 복사와 통합 원칙

- 기존 플러그인 원본은 수정하지 않는다.
- 새 플러그인 내부로 필요한 코드를 복사한 뒤 namespace, prefix, option, table, REST route, shortcode를 `oj-ai-dashboard` 기준으로 정리한다.
- 기존 플러그인의 메뉴 구조는 보존하지 않고, 단일 관리자 메뉴와 역할별 화면으로 재배치한다.
- 기존 플러그인의 데이터 접근 코드는 repository/service 계층으로 옮긴다.
- 기존 플러그인의 AI 요청 코드는 채점, 피드백, 특기사항 요청으로 분리한다.
- 기존 플러그인의 학생 화면 코드는 학생 API 공개 범위를 다시 검증한 뒤 사용한다.
- 기존 shortcode는 `Compatibility` 모듈에서 새 controller로 연결한다.

## 4. 대상 모듈별 통합 기준

| 대상 모듈 | 통합 기준 |
|---|---|
| `Dashboard` | OJ 연결 모드에서는 OJ 제출/문제 결과와 활동지/AI 채점 결과를 함께 조회한다. 독립 모드에서는 활동지/업로드/AI 채점 결과만 조회한다. |
| `Worksheet` | Plate 편집기는 기존 템플릿 빌더/편집기 구조를 통째로 가져와 확장한다. 개별 Plate 패키지를 임의 조립하지 않는다. |
| `Submission` | 자동저장, 첨부 상태, 대리 제출, 마감 자동 제출, ZIP preview/map/commit을 하나의 제출 흐름으로 합친다. |
| `Assessment` | 루브릭 snapshot, grading fingerprint, 재채점 변경 검토 상태를 기준으로 점수 흔들림을 막는다. |
| `Feedback` | accepted 채점 결과만 공식 피드백 입력으로 사용하고, 승인 후 학생에게 공개한다. |
| `SpecialNote` | accepted 채점 결과, 승인된 피드백, 성취기준, 교육과정을 입력으로 사용한다. |
| `Reports`/`Export` | 학생별 PDF, 학급별 PDF, 제출물 일괄 PDF, 점수 CSV를 하나의 export/job 흐름으로 합친다. |
| `Import`/`Compatibility` | 기존 데이터 가져오기는 자동 실행하지 않고 preview/commit으로 수동 실행한다. |

## 5. 금지 사항

- 기존 플러그인을 새 플러그인의 하위 독립 플러그인처럼 유지하지 않는다.
- 기존 세 기능은 새 플러그인의 단일 업무 흐름으로 합친다. <!-- HARNESS: integrated-legacy-workflow -->
- 기존 메뉴, 설정 화면, 설명 문구를 그대로 노출하지 않는다.
- 기존 접두사와 플랫폼명을 새 플러그인의 런타임 이름, 화면, REST route, option, table, shortcode에 남기지 않는다.
- 학생 응답에 정답, 예시답안, 배점, 교사용 메모, AI 원문 응답을 포함하지 않는다.
- 기존 AI 채점 결과를 검토 없이 공식 점수로 자동 승격하지 않는다.

## 6. 단계별 적용

| 단계 | 적용 내용 |
|---|---|
| 7단계 | 기존 테이블과 새 테이블의 migration mapping 작성 |
| 8단계 | OJ connector와 기존 대시보드 조회 코드를 새 connector 계약에 맞춤 |
| 9단계 | 기존 명단/그룹 코드를 학년도, 학년, 반 mapping으로 통합 |
| 11단계 | 기존 Plate 편집기 구조 복사 및 확장 |
| 13단계 | 기존 제출, PDF, ZIP 코드를 제출 job 흐름으로 통합 |
| 15단계 | 기존 AI 채점 코드를 snapshot/fingerprint 기반으로 통합 |
| 16단계 | 기존 피드백/특기사항 코드를 승인 상태와 accepted 결과 기준으로 통합 |
| 17단계 | 기존 대시보드/보고서를 교사용/학생용 대시보드와 export 흐름으로 통합 |
| 18단계 | 기존 shortcode와 데이터 가져오기 호환을 새 controller에 연결 |

## 7. 완료 기준

- 복사된 코드는 새 namespace와 새 prefix를 사용한다.
- 기존 세 기능이 하나의 관리자 메뉴와 하나의 사용자 흐름으로 연결된다.
- 기존 플러그인 원본을 수정하지 않는다.
- 기존 데이터 가져오기는 버튼 또는 preview/commit 흐름으로만 실행된다.
- 하네스와 품질 검사를 통과한다.
