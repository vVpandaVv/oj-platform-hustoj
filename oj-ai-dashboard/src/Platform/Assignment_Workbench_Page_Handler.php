<?php
/**
 * OJ Console assignment workbench page handler.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a short four-step assignment flow with no raw contract fields.
 */
final class Assignment_Workbench_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize assignment workbench page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @param string                       $route_key Route key.
	 */
	public function __construct( private Access_Policy_Port_Interface $policy, private string $route_key ) {
	}

	/**
	 * Handle assignment workbench page handler.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( $request->routeKey() !== $this->route_key ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}
		$decision = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				'assignment.workspace',
				array( Addon_Capabilities::ASSIGN_WORKSHEETS ),
				null,
				null,
				true,
				$request->correlationId()
			)
		);
		if ( ! $decision->is_allowed() ) {
			return $this->denied_response( $decision );
		}

		$submission_decision = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				'teacher_submission.list',
				array( Addon_Capabilities::MANAGE_SUBMISSIONS ),
				null,
				null,
				true,
				$request->correlationId()
			)
		);

		$assessment_decision = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				'assignment.evaluations.enqueue',
				array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
				null,
				null,
				true,
				$request->correlationId()
			)
		);

		return Addon_Page_Response::html(
			'배포·제출',
			Worksheet_Workflow::workspace( $request, $this->policy ) . $this->markup( $request, $submission_decision->is_allowed(), $assessment_decision->is_allowed() )
		);
	}

	/**
	 * Markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_manage_submissions Can manage submissions.
	 * @param bool               $can_assess_submissions Can assess submissions.
	 * @return string
	 */
	private function markup( AddonPageRequestV1 $request, bool $can_manage_submissions, bool $can_assess_submissions ): string {
		$assessments_url = function_exists( 'home_url' ) ? home_url( '/oj-console/addons/oj-ai-dashboard/assessments?type=submission' ) : '/oj-console/addons/oj-ai-dashboard/assessments?type=submission';
		$assessment_link = Worksheet_Workflow::stage_allowed( $request, $this->policy, 'assessment' )
			? '<a class="oj-ai-assignment__button ojp-button" data-action="open-assessments" href="' . $this->escape( (string) $assessments_url ) . '">' . Addon_Icon::markup( 'list' ) . ' 평가</a>' : '';

		return '<section id="oj-ai-assignment-app" class="oj-ai-assignment" ' . $this->attributes( $request, $can_manage_submissions, $can_assess_submissions, (string) $assessments_url ) . '>'
			. Worksheet_Workflow::header( $request, 'assignment', $this->policy )
			. '<div data-role="assignment-overview">'
			. '<header class="oj-ai-assignment__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>배포·제출</h1><p data-role="summary">그룹(반)을 선택하세요.</p></div>'
			. '<button type="button" class="oj-ai-assignment__primary ojp-button ojp-button--primary" data-action="new" disabled>새 배포</button></header>'
			. '<div class="oj-ai-workflow-scope"><span data-role="assignment-scope">조회 범위: 전체 활동지</span><a data-workflow-overview="assignments" href="' . $this->escape( function_exists( 'home_url' ) ? home_url( '/oj-console/addons/oj-ai-dashboard/assignments' ) : '/oj-console/addons/oj-ai-dashboard/assignments' ) . '" hidden>대상 전체 배포 보기</a></div>'
			. '<p data-role="publication-notice" role="status"></p>'
			. '<div class="oj-ai-assignment__filters"><label><span>그룹(반)</span><select data-field="context-filter"><option value="">그룹(반) 선택</option></select></label></div>'
			. '<div class="oj-ai-assignment__table-wrap" data-role="assignment-content" hidden>'
			. '<table class="oj-ai-assignment__table"><thead><tr><th>활동지</th><th>상태</th><th>대상</th><th>제출</th><th>마감</th><th>상세</th></tr></thead><tbody data-role="assignment-list"></tbody></table></div></div>'
			. '<p class="oj-ai-assignment__notice" data-role="notice" role="status">배포 대상과 활동지를 불러오는 중입니다.</p>'
			. '<section class="oj-ai-assignment__detail" data-role="assignment-detail" hidden aria-labelledby="oj-ai-assignment-detail-title">'
			. '<div class="oj-ai-assignment__view-nav"><button type="button" class="oj-ai-assignment__back ojp-button ojp-button--plain" data-action="close-detail">' . Addon_Icon::markup( 'arrow-left' ) . ' 배포 목록</button><button type="button" class="oj-ai-assignment__icon ojp-button ojp-button--plain" data-action="refresh-detail" aria-label="배포 새로고침" title="배포 새로고침">' . Addon_Icon::markup( 'refresh-cw' ) . '</button></div>'
			. '<header><div><p data-detail="context"></p><h2 id="oj-ai-assignment-detail-title" data-detail="title" tabindex="-1">배포 상세</h2></div><span class="oj-ai-assignment__status" data-detail="state">-</span></header>'
			. '<dl class="oj-ai-assignment__metrics"><div><dt>배포 학생</dt><dd data-detail="target-count">-</dd></div><div><dt>제출</dt><dd data-detail="progress">-</dd></div><div><dt>미제출</dt><dd data-detail="missing">-</dd></div></dl>'
			. '<div data-role="detail-actions"><div class="oj-ai-assignment__primary-actions"><div data-role="submission-entry"></div>' . $assessment_link . '</div>'
			. '<section class="oj-ai-assignment__section"><div class="oj-ai-assignment__section-heading"><h3>배포 일정</h3><div data-role="schedule-actions"></div></div><dl class="oj-ai-assignment__dates"><div><dt>공개</dt><dd data-detail="opens">-</dd></div><div><dt>제출 마감</dt><dd data-detail="due">-</dd></div><div><dt>종료</dt><dd data-detail="closes">-</dd></div></dl></section>'
			. '<section class="oj-ai-assignment__section"><div class="oj-ai-assignment__section-heading"><h3>배포 학생 <span data-detail="roster-count"></span></h3><button type="button" data-action="add-targets">' . Addon_Icon::markup( 'plus' ) . ' 학생 추가</button></div><p class="oj-ai-assignment__muted" data-role="target-availability" hidden></p><ul class="oj-ai-assignment__targets" data-role="target-list" aria-label="배포 학생 명단" tabindex="0"></ul></section>'
			. '<section class="oj-ai-assignment__section" data-role="state-section"><div class="oj-ai-assignment__section-heading"><h3>배포 관리</h3></div><div class="oj-ai-assignment__state-actions" data-role="state-actions"></div></section></div></section>'
			. '<dialog class="oj-ai-assignment__confirmation oj-ai-assignment__target-dialog" data-role="target-dialog" aria-labelledby="oj-ai-target-title">'
			. '<h2 id="oj-ai-target-title">학생 추가</h2><p data-role="target-assignment"></p><p data-role="target-notice" role="status"></p>'
			. '<label class="oj-ai-assignment__check"><input type="checkbox" data-field="target-all" disabled>전체 선택</label><div class="oj-ai-assignment__target-candidates" data-role="target-candidates"></div>'
			. '<footer><span data-role="target-selected">0명 선택</span><button type="button" data-action="refresh-targets">명단 새로고침</button><button type="button" data-action="cancel-targets">취소</button><button type="button" class="oj-ai-assignment__primary ojp-button ojp-button--primary" data-action="confirm-targets" disabled>선택 학생 추가</button></footer></dialog>'
			. '<section class="oj-ai-assignment__submissions" data-role="submission-panel" hidden aria-labelledby="oj-ai-submissions-title">'
			. '<div class="oj-ai-assignment__view-nav"><button type="button" class="oj-ai-assignment__back ojp-button ojp-button--plain" data-action="close-submissions">' . Addon_Icon::markup( 'arrow-left' ) . ' 배포 상세</button><button type="button" class="oj-ai-assignment__icon ojp-button ojp-button--plain" data-action="refresh-submissions" aria-label="제출 현황 새로고침" title="제출 현황 새로고침">' . Addon_Icon::markup( 'refresh-cw' ) . '</button></div>'
			. '<header><div><p data-role="submission-context">학생 제출</p><h2 id="oj-ai-submissions-title" data-role="submission-title" tabindex="-1">제출 현황</h2></div>'
			. '<div class="oj-ai-assignment__heading-actions">' . $assessment_link
			. '<div data-role="submission-bundle"></div></div></header>'
			. '<div class="oj-ai-assignment__student-display"><label><span>학생 표시</span><select data-learner-label-mode><option value="management_name">관리용 이름</option><option value="login">아이디</option><option value="display_name" selected>표시이름</option></select></label></div>'
			. '<p class="oj-ai-assignment__notice" data-role="submission-notice" role="status"></p>'
			. '<div class="oj-ai-assignment__submission-list" data-role="submission-list">'
			. '<div class="oj-ai-assignment__table-wrap"><table class="oj-ai-assignment__table"><thead><tr><th>학생</th><th>상태</th><th>제출</th><th>제출 시각</th><th>보기</th></tr></thead><tbody data-role="submission-rows"></tbody></table></div></div>'
			. '<section class="oj-ai-assignment__submission-detail" data-role="submission-detail" hidden aria-labelledby="oj-ai-submission-detail-title">'
			. '<button type="button" class="oj-ai-assignment__back ojp-button ojp-button--plain" data-action="back-submissions">제출 목록</button><header><div><p data-submission="state">-</p><h3 id="oj-ai-submission-detail-title" data-submission="student">학생 제출</h3></div></header>'
			. '<dl><div><dt>제출 횟수</dt><dd data-submission="attempt">-</dd></div><div><dt>제출 시각</dt><dd data-submission="submitted">-</dd></div><div><dt>평가</dt><dd data-submission="evaluation">-</dd></div><div><dt>결과 공개</dt><dd data-submission="release">-</dd></div></dl>'
			. '<div class="oj-ai-assignment__actions" data-role="submission-actions"></div><p id="oj-ai-submission-reopen-status" class="oj-ai-assignment__action-status" data-role="submission-reopen-status" role="status" hidden></p>'
			. '<h4>제출 내용</h4><dl class="oj-ai-assignment__answers" data-role="submission-answers"></dl>'
			. '<h4>변경 기록</h4><ol class="oj-ai-assignment__history" data-role="submission-history"></ol></section></section>'
			. '<section class="oj-ai-assignment__wizard" data-role="wizard" role="dialog" aria-modal="true" hidden aria-labelledby="oj-ai-assignment-wizard-title">'
			. '<header><div><p data-role="step-label">1 / 4</p><h2 id="oj-ai-assignment-wizard-title" data-role="step-title">발행본</h2></div>'
			. '<button type="button" class="oj-ai-assignment__icon ojp-button ojp-button--plain" data-action="cancel" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<ol class="oj-ai-assignment__steps" aria-label="배포 단계"><li data-step-indicator="1" aria-current="step">발행본</li><li data-step-indicator="2">대상</li><li data-step-indicator="3">일정</li><li data-step-indicator="4">확인</li></ol>'
			. '<div class="oj-ai-assignment__step" data-step="1"><label><span>활동지</span><select data-field="publication"><option value="">발행된 활동지 선택</option></select></label></div>'
			. '<div class="oj-ai-assignment__step" data-step="2" hidden><label><span>그룹(반)</span><select data-field="context"><option value="">그룹(반) 선택</option></select></label><p data-role="target-summary">현재 명단 전체에 배포됩니다.</p></div>'
			. '<div class="oj-ai-assignment__step oj-ai-assignment__schedule" data-step="3" hidden>'
			. '<label><span>공개 시각</span><input type="datetime-local" data-field="opens-at"></label>'
			. '<label><span>제출 마감</span><input type="datetime-local" data-field="due-at" required></label>'
			. '<label><span>종료 시각</span><input type="datetime-local" data-field="closes-at" required></label>'
			. '<label class="oj-ai-assignment__check"><input type="checkbox" data-field="auto-submit" checked><span>종료 시 자동 제출</span></label>'
			. '<label><span>최대 제출 횟수</span><input type="number" data-field="max-attempts" min="1" max="10" value="2"></label>'
			. '<label class="oj-ai-assignment__check"><input type="checkbox" data-field="copy-answers" checked><span>다시 열 때 이전 답안 복사</span></label></div>'
			. '<div class="oj-ai-assignment__step oj-ai-assignment__review" data-step="4" hidden><dl>'
			. '<div><dt>활동지</dt><dd data-review="publication">-</dd></div><div><dt>대상</dt><dd data-review="context">-</dd></div>'
			. '<div><dt>공개</dt><dd data-review="opens">바로 열기 전까지 대기</dd></div><div><dt>마감</dt><dd data-review="due">-</dd></div></dl></div>'
			. '<p class="oj-ai-assignment__wizard-notice" data-role="wizard-notice" role="alert" hidden></p>'
			. '<footer><button type="button" data-action="previous" hidden>이전</button><span></span>'
			. '<button type="button" class="oj-ai-assignment__primary ojp-button ojp-button--primary" data-action="next">다음</button>'
			. '<button type="button" class="oj-ai-assignment__primary ojp-button ojp-button--primary" data-action="create" hidden>배포 만들기</button></footer></section>'
			. '<dialog class="oj-ai-assignment__lifecycle" data-role="lifecycle-dialog" hidden aria-labelledby="oj-ai-assignment-lifecycle-title">'
			. '<header><div><p>일정</p><h2 id="oj-ai-assignment-lifecycle-title" data-role="lifecycle-title">일정 변경</h2></div>'
			. '<button type="button" class="oj-ai-assignment__icon ojp-button ojp-button--plain" data-action="cancel-lifecycle" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<p class="oj-ai-assignment__dialog-context" data-role="lifecycle-assignment"></p>'
			. '<div class="oj-ai-assignment__schedule"><label><span>공개 시각</span><input type="datetime-local" data-field="lifecycle-opens"></label>'
			. '<label><span>제출 마감</span><input type="datetime-local" data-field="lifecycle-due" required></label>'
			. '<label><span>종료 시각</span><input type="datetime-local" data-field="lifecycle-closes" required></label>'
			. '<label class="oj-ai-assignment__check"><input type="checkbox" data-field="lifecycle-auto-submit"><span>종료 시 자동 제출</span></label></div>'
			. '<p class="oj-ai-assignment__wizard-notice" data-role="lifecycle-notice" role="alert" hidden></p><footer><span></span>'
			. '<button type="button" data-action="cancel-lifecycle">취소</button><button type="button" class="oj-ai-assignment__primary ojp-button ojp-button--primary" data-action="save-lifecycle">저장</button></footer></dialog>'
			. '<dialog class="oj-ai-assignment__control" data-role="submission-control-dialog" hidden aria-labelledby="oj-ai-submission-control-title">'
			. '<header><div><p data-role="submission-control-label">제출</p><h2 id="oj-ai-submission-control-title" data-role="submission-control-title">제출 처리</h2></div>'
			. '<button type="button" class="oj-ai-assignment__icon ojp-button ojp-button--plain" data-action="cancel-submission-control" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<p data-role="submission-control-summary" hidden></p>'
			. '<label><span>사유</span><textarea data-field="submission-control-reason" rows="4" maxlength="500" required></textarea></label>'
			. '<p class="oj-ai-assignment__wizard-notice" data-role="submission-control-notice" role="alert" hidden></p><footer><span></span>'
			. '<button type="button" data-action="cancel-submission-control">취소</button><button type="button" class="oj-ai-assignment__primary ojp-button ojp-button--primary" data-action="confirm-submission-control">확인</button></footer></dialog>'
			. '</section>';
	}

	/**
	 * Attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_manage_submissions Can manage submissions.
	 * @param bool               $can_assess_submissions Can assess submissions.
	 * @param string             $assessments_url Assessments url.
	 * @return string
	 */
	private function attributes( AddonPageRequestV1 $request, bool $can_manage_submissions, bool $can_assess_submissions, string $assessments_url ): string {
		$rest_root  = function_exists( 'rest_url' ) ? rest_url( 'oj-ai/v1' ) : '/wp-json/oj-ai/v1';
		$nonce      = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		$can_export = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				'assignment.submission_bundle.enqueue',
				array( Addon_Capabilities::EXPORT_REPORTS ),
				null,
				null,
				true,
				$request->correlationId()
			)
		)->is_allowed();
		$values     = array(
			'data-rest-root'              => rtrim( (string) $rest_root, '/' ),
			'data-rest-nonce'             => (string) $nonce,
			'data-locale'                 => $request->locale(),
			'data-actor-id'               => (string) $request->actorUserId(),
			'data-can-export'             => $can_export ? '1' : '0',
			'data-icon-sprite'            => Addon_Icon::sprite_url(),
			'data-can-manage-submissions' => $can_manage_submissions ? 'true' : 'false',
			'data-can-assess-submissions' => $can_assess_submissions ? 'true' : 'false',
			'data-assessments-url'        => $assessments_url,
		);
		$attributes = array();
		foreach ( $values as $name => $value ) {
			$attributes[] = $name . '="' . $this->escape( $value ) . '"';
		}
		return implode( ' ', $attributes );
	}

	/**
	 * Denied response.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return AddonPageResponseV1
	 */
	private function denied_response( Access_Policy_Decision $decision ): AddonPageResponseV1 {
		if ( 503 === $decision->status() ) {
			return AddonPageResponseV1::unavailable( '배포·제출을 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		$status = 401 === $decision->status() ? 403 : $decision->status();
		if ( ! in_array( $status, array( 403, 404, 409 ), true ) ) {
			$status = 403;
		}
		$message = 409 === $status ? '내용이 바뀌었습니다. 새로고침해 주세요.' : '이 화면을 볼 수 없습니다.';
		$body    = '<section class="oj-ai-assignment" data-oj-ai-code="' . $this->escape( $decision->code() ) . '"><header class="oj-ai-assignment__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>배포·제출</h1></div></header><p class="oj-ai-assignment__notice" role="alert">' . $message . '</p></section>';
		return Addon_Page_Response::html( '배포·제출', $body, $status );
	}
	/**
	 * Escape.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
