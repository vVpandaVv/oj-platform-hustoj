# OJ AI Dashboard 보안과 권한 기준

문서 상태: 확정  
작성일: 2026-06-13  
대상 단계: 5단계

## 1. 역할

기본 역할은 관리자, 교사, 학생이다. 보조교사는 설정으로 활성화하는 확장 역할이다.

| 역할 | WordPress role | 설명 |
|---|---|---|
| 관리자 | `administrator` | 전체 설정, 가져오기, 보고서, 모든 데이터 접근 |
| 교사 | `oj_ai_teacher` | 담당 범위의 활동지, 채점, 피드백, 특기사항, 보고서 운영 |
| 학생 | `oj_ai_student` | 본인 활동지, 본인 대시보드, 승인된 피드백 확인 |
| 보조교사 | `oj_ai_assistant` | 선택 역할. 채점 검토와 보고서 조회 보조 |

## 2. 핵심 capability

| Capability | 용도 |
|---|---|
| `oj_ai_dashboard_manage` | 전체 관리 |
| `oj_ai_dashboard_teach` | 교사 기능 접근 |
| `oj_ai_dashboard_view_own_dashboard` | 본인 학생 대시보드 조회 |
| `oj_ai_dashboard_view_student_dashboard` | 담당 학생 대시보드 조회 |
| `oj_ai_dashboard_manage_academic_context` | 학년도, 학년, 반, 학생 관리 |
| `oj_ai_dashboard_manage_worksheets` | 활동지 생성/수정 |
| `oj_ai_dashboard_assign_worksheets` | 활동지 배정 |
| `oj_ai_dashboard_manage_submissions` | 제출물 관리, 대리 제출, ZIP 업로드 |
| `oj_ai_dashboard_run_assessment` | 채점 실행 |
| `oj_ai_dashboard_review_assessment` | 채점 결과 검토 |
| `oj_ai_dashboard_approve_feedback` | 피드백 승인/공개 |
| `oj_ai_dashboard_generate_special_notes` | 특기사항 생성 |
| `oj_ai_dashboard_manage_curriculum` | 교육과정/성취기준 관리 |
| `oj_ai_dashboard_export_reports` | PDF/CSV 출력 |
| `oj_ai_dashboard_import_data` | 기존 데이터/명단 가져오기 |
| `oj_ai_dashboard_manage_settings` | 플러그인 설정 관리 |

## 3. 접근 원칙

- 모든 학생 기능은 로그인 후 사용한다.
- 학생은 본인 데이터만 볼 수 있다.
- 교사는 담당 학년도, 학년, 반, 그룹 범위 안의 데이터만 볼 수 있다.
- 관리자는 전체 범위에 접근할 수 있다.
- 보조교사는 기본 활성화하지 않는다.
- OJ 연결 모드에서는 OJ 그룹/학생 권한을 기준으로 담당 범위를 계산한다.
- 독립 모드에서는 로컬 학년도/학년/반/학생 매핑을 기준으로 담당 범위를 계산한다.

## 4. 구현 클래스

| 클래스 | 책임 |
|---|---|
| `Security\Capabilities` | role과 capability 정의 및 설치 |
| `Security\Nonce_Service` | nonce 생성과 검증 |
| `Security\Permission_Guard` | 로그인, capability, scope 검사 |
| `Security\Scope_Checker` | 담당 학생/학급 범위 검사 |
| `Security\Rest_Permission` | REST permission callback 생성 |
| `Contracts\Scope_Provider_Interface` | OJ/독립형 학사 범위 provider 계약 |

## 5. REST 규칙

- REST 쓰기 요청은 nonce/capability 검사를 통과해야 한다.
- REST endpoint는 `Rest_Permission::for_capability()`를 사용한다.
- 쓰기 endpoint는 scope 옵션에 `require_nonce => true`를 지정해 nonce 검사를 켠다.
- 학생 ID를 받는 endpoint는 scope 옵션으로 학생 범위 검사를 연결한다.
- scope provider가 아직 연결되지 않은 경우 학생 본인과 관리자만 통과한다.

예시:

```php
$permission = new Rest_Permission();
$callback = $permission->for_capability(
	Capabilities::MANAGE_WORKSHEETS,
	array(
		'require_nonce' => true,
		'nonce_action'  => Nonce_Service::DEFAULT_ACTION,
	)
);
```

## 6. 현재 단계의 한계

5단계에서는 권한과 보안 골격만 만든다. 실제 담당 학급 범위 계산은 8단계 OJ connector와 9단계 학년도/학년/반/학생 관리 구현 후 `Scope_Provider_Interface`로 연결한다.
