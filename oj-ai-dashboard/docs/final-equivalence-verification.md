# OJ AI Dashboard 최종 기능 동등성 검증서

<!-- HARNESS: functional-equivalence-final -->

문서 상태: 16단계 검증 완료
작성일: 2026-06-15
대상: `oj-ai-dashboard`

## 1. 검증 목적

기존 3개 플러그인을 비활성화한 상태를 가정하고, 통합 플러그인 하나만으로 교사, 학생, 관리자, OJ 연결, 독립 실행 흐름이 동일 업무를 수행할 수 있는지 확인한다.

완료 판정은 메뉴 표시가 아니라 실제 업무 흐름 기준으로 한다.

## 2. 검증 범위

| 흐름 | 확인 항목 | 통합 구현 기준 |
|---|---|---|
| 교사 흐름 | 활동지 템플릿, Plate 편집, 배포, 제출 관리, ZIP/개별 업로드, 채점, 피드백, 특기사항, 보고서 | `Worksheet_Admin_Page`, `Admin_Workflow_Page`, `Dashboard_Admin_Page`, REST와 JS 액션이 직접 연결되어야 함 |
| 학생 흐름 | 내 활동지 목록, 작성, 자동저장, 수동 저장, 첨부, 그림 그리기, 제출, 공개 피드백 확인 | `Dashboard_Admin_Page::render_student_worksheet_app`, `student-worksheet.js`, Submission REST가 연결되어야 함 |
| 관리자 흐름 | 독립 관리자 shell, 설정, 데이터 가져오기, 학생 명단 가져오기, 템플릿 가져오기/내보내기, 출력 | `Admin_Module`, `Admin_Shell`, `Admin_Workflow_Page`, Import/Academic/Worksheet/Dashboard REST가 연결되어야 함 |
| OJ 연결 흐름 | OJ 콘솔 메뉴, OJ 그룹 우선, OJ 문제와 코드 제출 기록 분석 | `Oj_Console_Connector`, `Oj_Connector`, `Academic_Mode_Service`, `Dashboard_Service`가 연결되어야 함 |
| 독립 흐름 | 학년도, 학년, 반, 자체 그룹, 학생 명단, 활동지, 채점, 보고서 | Academic 저장소와 로컬 그룹 흐름이 OJ 없이 동작해야 함 |
| 호환 흐름 | 기존 shortcode, 기존 데이터 수동 가져오기, 기존 업무 화면 대체 | `Compatibility_Module`, `Shortcode_Compatibility_Service`, `Import_Module`이 활성 모듈에 포함되어야 함 |

## 3. 기능 동등성 기준

- 기존 3개 플러그인의 기능은 병렬 독립 UI가 아니라 통합 화면과 REST 흐름 안에서 수행된다.
- OJ 연결 모드에서는 OJ 그룹, 문제, 제출 기록을 우선 사용하고 로컬 그룹 생성은 비활성화된다.
- 독립 모드에서는 학년도, 학년, 반, 학생 명단과 로컬 그룹을 사용한다.
- 활동지는 Plate 전체 편집기 기반으로 작성되며 입력 블록, 정답, 예시, 배점, 루브릭, 성취기준 흐름과 연결된다.
- 학생 작성 화면은 자동저장, 수동 저장, 첨부, 그림 그리기, 제출을 제공한다.
- 채점, 피드백, 특기사항은 각각 독립 버튼과 REST 호출로 분리된다.
- 재채점 점수 변경은 즉시 덮어쓰지 않고 변경 검토 후 반영 또는 미반영된다.
- 보고서는 학생 PDF, 학급 PDF, 제출물 일괄 PDF/ZIP, 점수 CSV를 제공하고 포함 옵션을 지원한다.
- 기존 데이터 가져오기는 preview/commit 방식이며 사용자가 버튼을 눌렀을 때만 실행된다.
- 화면에는 개발자 설명 문구나 긴 교사용 안내 문구가 상시 표시되지 않는다.

## 4. 코드 검증 항목

| 항목 | 검증 대상 |
|---|---|
| 활성 모듈 | `Security`, `Admin`, `Environment`, `Platform`, `Academic`, `Curriculum`, `Worksheet`, `Submission`, `Assessment`, `Dashboard`, `Import`, `Compatibility` |
| 콘솔/독립 진입점 | OJ 콘솔 route/menu, 독립 관리자 route, 기존 관리자 페이지 redirect |
| 활동지 | Plate asset, 템플릿 생명주기, 배포, 제출 관리, ZIP/개별 제출 |
| 학생 작성 | 자동저장, 첨부 업로드, 그림 저장, 제출 잠금 |
| 채점 | 평가 계획, 루브릭 단일 모드, 성취기준, 질문/답안/정답/예시 동시 검토 |
| 피드백/특기사항 | 승인 후 공개, 공개 취소, 특기사항 생성 컨텍스트 |
| 재채점 | 변경 검토 조회, 반영, 미반영 |
| 대시보드 | 교사/학생 분리, 학년도/학년/반 필터, OJ 제출/문제 분석 |
| 출력/가져오기 | PDF, ZIP, CSV, 템플릿 import/export, 학생 명단 import, 기존 데이터 import |
| 호환 | 기존 shortcode alias와 수동 가져오기 |

## 5. 자동 검증 명령

```powershell
npm run check:equivalence
npm run check
```

`check:equivalence`는 16단계 전용 하네스로, 위 검증 항목이 실제 코드와 문서에 연결되어 있는지 정적으로 확인한다. `check`는 기존 Plate, 입력 블록, 제출, 교육과정, 채점, 대시보드, 호환, 빌드, 테스트 하네스를 함께 실행한다.

## 6. 남은 운영 검증

정적 검증과 빌드 검증이 통과해도 실제 사이트 배포 후 아래 항목은 운영 환경에서 추가 확인한다.

| 항목 | 이유 |
|---|---|
| 실제 OJ 콘솔 클릭 흐름 | 외부 플랫폼 hook과 route 서비스는 설치 환경에 따라 달라질 수 있음 |
| 실제 WordPress REST nonce와 권한 | 로그인 사용자, 역할, 담당 범위 데이터가 필요함 |
| 실제 파일 업로드와 PDF 다운로드 | 서버 업로드 경로, 파일 권한, ZipArchive 확장 상태가 필요함 |
| Plate 브라우저 시각 회귀 | 브라우저, 테마, 관리자 shell CSS 영향을 받을 수 있음 |

## 7. 판정

16단계의 개발 기준은 통합 플러그인 단독 기능 동등성이다. 자동 하네스가 통과하면 코드 구조와 연결성 기준에서는 기존 3개 플러그인을 다시 켜지 않아도 같은 업무 흐름을 수행하도록 구성된 것으로 판정한다.

2026-06-15 기준 `npm run check:equivalence`와 `npm run check`가 통과했으므로, 코드 구조와 자동 검증 기준에서는 16단계를 완료로 판정한다.
