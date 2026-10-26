# OJ AI Dashboard 환경 감지와 실행 모드

문서 상태: 확정  
작성일: 2026-06-14  
대상 단계: 6단계

## 1. 실행 모드

`oj-ai-dashboard`는 두 가지 모드로 동작한다.

| Mode | 조건 | 로컬 그룹 | OJ 문제/코드 채점 분석 |
|---|---|---|---|
| `oj_connected` | OJ 플랫폼 감지됨 | 비활성 | 활성 |
| `standalone` | OJ 플랫폼 없음 | 활성 | 비활성 |

## 2. 감지 신호

`Environment\Platform_Detector`는 다음 신호를 확인한다.

- OJ 플랫폼 상수
- 공개 Addon Runtime 연결 신호
- 활성 플러그인 basename
- `oj_ai_dashboard_platform_detector_signals` filter로 추가된 외부 신호

감지 결과는 `Platform_Status`로 표현한다.

기본 감지기는 새 OJ 플랫폼 신호만 직접 확인한다. 추가 호환 신호가 필요하면 `oj_ai_dashboard_platform_detector_signals` filter 또는 후속 `Compatibility` adapter에서 추가한다.

## 3. Feature flag

`Environment\Feature_Flags`는 실행 모드에 따라 다음 flag를 제공한다.

| Flag | OJ 연결 | 독립 |
|---|---:|---:|
| `oj_platform_available` | true | false |
| `oj_groups_enabled` | true | false |
| `local_groups_enabled` | false | true |
| `oj_code_grading_enabled` | true | false |
| `oj_problem_analysis_enabled` | true | false |
| `worksheet_enabled` | true | true |
| `ai_assessment_enabled` | true | true |
| `teacher_dashboard_in_oj_enabled` | true | false |
| `standalone_teacher_dashboard` | false | true |
| `legacy_shortcode_compat_enabled` | true | true |

## 4. REST 확인

환경 상태는 REST endpoint로 확인한다.

```text
GET /wp-json/oj-ai/v1/environment
```

접근 권한:

```text
oj_ai_dashboard_manage_settings
```

REST 응답의 `platform` 값은 `available`, `source`, `version`만 포함한다. 내부 감지 signal 목록은 공개 snapshot에 포함하지 않는다.

<!-- HARNESS: public-environment-snapshot -->

## 5. 관리자 화면 확인

관리자는 `OJ AI Dashboard > 환경`에서 상태를 확인한다.

화면에는 상태값 중심의 표만 표시한다.

## 6. 확장 지점

다른 OJ 플랫폼 구현이 연결되어야 할 때는 아래 filter에 감지 신호를 추가한다.

```php
add_filter(
	'oj_ai_dashboard_platform_detector_signals',
	function ( array $signals ) {
		$signals[] = array(
			'id'        => 'custom_oj_platform',
			'available' => true,
			'source'    => 'custom_oj_platform',
			'version'   => '1.0.0',
		);

		return $signals;
	}
);
```

## 7. 현재 단계의 한계

6단계는 감지와 모드 전환 기반만 만든다. 실제 OJ 그룹, 문제, 제출 기록 조회는 8단계 OJ connector에서 구현한다.
