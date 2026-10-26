<?php
/**
 * OJ Console assessment workbench Page handler.
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
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Execution_Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders worksheet self-check and student submission review in one Page.
 */
final class Worksheet_Evaluation_Workbench_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize worksheet evaluation workbench page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @param string                       $route_key Route key.
	 */
	public function __construct(
		private Access_Policy_Port_Interface $policy,
		private string $route_key
	) {
	}

	/**
	 * Handle worksheet evaluation workbench page handler.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( $request->routeKey() !== $this->route_key ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}

		$page = $this->authorize(
			$request,
			$this->route_key,
			array( Addon_Capabilities::VIEW_WORKSPACE )
		);
		if ( ! $page->is_allowed() ) {
			return $this->denied_response( $page );
		}

		$worksheet         = $this->authorize(
			$request,
			'worksheet.evaluation.assess',
			array( Addon_Capabilities::ASSESS_WORKSHEETS )
		);
		$worksheet_review  = $this->authorize(
			$request,
			'worksheet.evaluation.review',
			array( Addon_Capabilities::REVIEW_ASSESSMENTS )
		);
		$submission        = $this->authorize(
			$request,
			'submission.evaluation.list',
			array( Addon_Capabilities::ASSESS_SUBMISSIONS )
		);
		$submission_review = $this->authorize(
			$request,
			'submission.evaluation.review',
			array( Addon_Capabilities::REVIEW_ASSESSMENTS )
		);
		$student_notes     = $this->authorize(
			$request,
			'submission.student_note.manage',
			array( Addon_Capabilities::MANAGE_STUDENT_NOTES )
		);

		$can_worksheet  = $worksheet->is_allowed();
		$can_submission = $submission->is_allowed() || $submission_review->is_allowed();
		if ( ! $can_worksheet && ! $can_submission ) {
			return $this->denied_response( $this->feature_denial( $worksheet, $submission, $submission_review ) );
		}

		return Addon_Page_Response::html(
			'평가',
			Worksheet_Workflow::workspace( $request, $this->policy ) . $this->markup(
				$request,
				$can_worksheet,
				$worksheet_review->is_allowed(),
				$can_submission,
				$submission->is_allowed(),
				$submission_review->is_allowed(),
				$student_notes->is_allowed()
			)
		);
	}

	/**
	 * Authorize.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param string             $action_key Action key.
	 * @param array<int,string>  $capabilities Capabilities.
	 * @return Access_Policy_Decision
	 */
	private function authorize(
		AddonPageRequestV1 $request,
		string $action_key,
		array $capabilities
	): Access_Policy_Decision {
		return $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				$action_key,
				$capabilities,
				null,
				null,
				true,
				$request->correlationId()
			)
		);
	}

	/**
	 * Feature denial.
	 *
	 * @param Access_Policy_Decision $worksheet Worksheet.
	 * @param Access_Policy_Decision $submission Submission.
	 * @param Access_Policy_Decision $review Review.
	 * @return Access_Policy_Decision
	 */
	private function feature_denial(
		Access_Policy_Decision $worksheet,
		Access_Policy_Decision $submission,
		Access_Policy_Decision $review
	): Access_Policy_Decision {
		foreach ( array( $worksheet, $submission, $review ) as $decision ) {
			if ( 503 === $decision->status() ) {
				return $decision;
			}
		}
		return $review;
	}

	/**
	 * Markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_worksheet Can worksheet.
	 * @param bool               $can_worksheet_review Can worksheet review.
	 * @param bool               $can_submission Can submission.
	 * @param bool               $can_submission_assess Can submission assess.
	 * @param bool               $can_submission_review Can submission review.
	 * @param bool               $can_manage_student_notes Can manage student notes.
	 * @return string
	 */
	private function markup(
		AddonPageRequestV1 $request,
		bool $can_worksheet,
		bool $can_worksheet_review,
		bool $can_submission,
		bool $can_submission_assess,
		bool $can_submission_review,
		bool $can_manage_student_notes
	): string {
		$query          = $request->query();
		$requested_type = isset( $query['type'] ) && is_string( $query['type'] )
			? $query['type']
			: '';
		if ( 'worksheet' === $requested_type && $can_worksheet ) {
			$default = 'worksheet';
		} elseif ( 'submission' === $requested_type && $can_submission ) {
			$default = 'submission';
		} else {
			$default = $can_submission ? 'submission' : 'worksheet';
		}
		$tabs = '<nav class="oj-ai-assessment-switch" role="tablist" aria-label="평가 종류">';
		if ( $can_submission ) {
			$tabs .= '<button type="button" role="tab" id="oj-ai-assessment-tab-submission" data-assessment-tab="submission" aria-controls="oj-ai-assessment-panel-submission" aria-selected="'
				. ( 'submission' === $default ? 'true' : 'false' ) . '">학생 제출</button>';
		}
		if ( $can_worksheet ) {
			$tabs .= '<button type="button" role="tab" id="oj-ai-assessment-tab-worksheet" data-assessment-tab="worksheet" aria-controls="oj-ai-assessment-panel-worksheet" aria-selected="'
				. ( 'worksheet' === $default ? 'true' : 'false' ) . '">활동지 자체 점검</button>';
		}
		$tabs .= '</nav>';

		$submission_panel = '';
		if ( $can_submission ) {
			$submission_panel = '<div id="oj-ai-assessment-panel-submission" role="tabpanel" data-assessment-panel="submission" aria-labelledby="oj-ai-assessment-tab-submission"'
				. ( 'submission' === $default ? '' : ' hidden' ) . '>'
				. $this->submission_markup( $request, $can_submission_assess, $can_submission_review, $can_manage_student_notes ) . '</div>';
		}
		$worksheet_panel = '';
		if ( $can_worksheet ) {
			$worksheet_panel = '<div id="oj-ai-assessment-panel-worksheet" role="tabpanel" data-assessment-panel="worksheet" aria-labelledby="oj-ai-assessment-tab-worksheet"'
				. ( 'worksheet' === $default ? '' : ' hidden' ) . '>'
				. $this->worksheet_markup( $request, $can_worksheet_review ) . '</div>';
		}

		return '<div class="oj-ai-assessment-surface" data-oj-ai-assessment-shell data-default-tab="' . $default . '">'
			. Worksheet_Workflow::header( $request, in_array( $query['stage'] ?? '', array( 'report', 'release' ), true ) ? $query['stage'] : ( 'worksheet' === $default ? 'review' : 'assessment' ), $this->policy )
			. ( isset( $query['worksheet_id'] ) ? '' : $tabs ) . $submission_panel . $worksheet_panel . '</div>';
	}

	/**
	 * Worksheet review panel.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return string
	 */
	public function worksheet_review_panel( AddonPageRequestV1 $request ): string {
		$read = $this->authorize( $request, 'worksheet.evaluation.assess', array( Addon_Capabilities::ASSESS_WORKSHEETS ) );
		if ( ! $read->is_allowed() ) {
			return '<p role="status">AI 점검 권한 없음 · 교사 검토 승인 가능</p>';
		}
		$review = $this->authorize( $request, 'worksheet.evaluation.review', array( Addon_Capabilities::REVIEW_ASSESSMENTS ) );
		return $this->worksheet_markup( $request, $review->is_allowed() );
	}

	/**
	 * Worksheet markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_review Can review.
	 * @return string
	 */
	private function worksheet_markup( AddonPageRequestV1 $request, bool $can_review ): string {
		return '<section id="oj-ai-worksheet-evaluation-app" class="oj-ai-evaluation" ' . $this->application_attributes( $request, $can_review ) . '>'
			. '<header class="oj-ai-evaluation__header ojp-page-header"><div><p class="ojp-eyebrow">선택 사항</p><h1>활동지 AI 점검</h1><p data-role="summary">불러오는 중</p></div>'
			. '<div class="oj-ai-evaluation__header-actions ojp-heading-actions">'
			. '<button type="button" class="oj-ai-evaluation__icon-button ojp-button ojp-button--plain" data-action="refresh" aria-label="새로고침" title="새로고침">' . Addon_Icon::markup( 'refresh-cw' ) . '</button>'
			. '<button type="button" class="oj-ai-evaluation__primary ojp-button ojp-button--primary" data-action="new-evaluation">활동지 점검</button></div></header>'
			. '<div class="oj-ai-evaluation__consent" data-role="consent-status" role="status"><span data-role="consent-label"></span>'
			. ( Worksheet_Workflow::allowed( $request, $this->policy, Addon_Capabilities::MANAGE_POLICIES ) ? '<a href="' . $this->escape( $this->settings_url() ) . '">설정</a>' : '' ) . '</div>'
			. '<div class="oj-ai-evaluation__filters">'
			. '<label class="oj-ai-evaluation__search"><span class="screen-reader-text">평가 검색</span>'
			. '<input type="search" data-field="search" placeholder="활동지 검색" autocomplete="off"></label>'
			. '<label><span class="screen-reader-text">상태</span><select data-field="state">'
			. '<option value="">전체</option><option value="review_required">교사 검토 대기</option>'
			. '<option value="running">진행 중</option><option value="queued">대기</option>'
			. '<option value="failed">실패</option><option value="reviewed">교사 검토 완료</option>'
			. '<option value="cancelled">취소</option><option value="stale">새 버전 있음</option>'
			. '</select></label></div>'
			. '<div class="oj-ai-evaluation__notice" data-role="notice" role="status">평가를 불러오는 중입니다.</div>'
			. '<div class="oj-ai-evaluation__layout" data-role="content" hidden>'
			. '<aside class="oj-ai-evaluation__inbox" aria-label="평가 목록"><div data-role="evaluation-list"></div>'
			. '<button type="button" class="oj-ai-evaluation__load-more ojp-button" data-action="load-more" hidden>더 보기</button></aside>'
			. '<section class="oj-ai-evaluation__detail" data-role="evaluation-detail" aria-live="polite">'
			. '<div class="oj-ai-evaluation__empty"><strong>평가를 선택하세요.</strong><span>결과와 검토 항목이 여기에 표시됩니다.</span></div>'
			. '</section></div>'
			. '<dialog class="oj-ai-evaluation__dialog" data-role="new-evaluation-dialog" aria-labelledby="oj-ai-new-evaluation-title">'
			. '<form method="dialog"><header><h2 id="oj-ai-new-evaluation-title">활동지 점검</h2>'
			. '<button type="submit" class="oj-ai-evaluation__dialog-close ojp-button ojp-button--plain" value="cancel" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<label>활동지<select data-field="worksheet"><option value="">활동지를 선택하세요</option></select></label>'
			. '<p class="oj-ai-evaluation__dialog-status" data-role="new-evaluation-status" role="status"></p>'
			. '<label class="oj-ai-evaluation__ack"><input type="checkbox" data-field="external-processing-ack">'
			. '<span>활동지 내용의 외부 AI 처리를 확인했습니다.</span></label>'
			. '<footer><button type="submit" value="cancel">취소</button>'
			. '<button type="button" class="oj-ai-evaluation__primary ojp-button ojp-button--primary" data-action="enqueue" disabled>점검 시작</button></footer>'
			. '</form></dialog>'
			. '</section>';
	}

	/**
	 * Submission markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_assess Can assess.
	 * @param bool               $can_review Can review.
	 * @param bool               $can_manage_student_notes Can manage student notes.
	 * @return string
	 */
	private function submission_markup( AddonPageRequestV1 $request, bool $can_assess, bool $can_review, bool $can_manage_student_notes ): string {
		return '<section id="oj-ai-submission-review-app" class="oj-ai-submission-review" ' . $this->submission_attributes( $request, $can_assess, $can_review, $can_manage_student_notes ) . '>'
			. '<header class="oj-ai-submission-review__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>학생 제출 평가</h1><p data-role="submission-summary">그룹(반)을 선택하세요.</p></div>'
			. '<div class="oj-ai-submission-review__header-actions ojp-heading-actions" data-review-role="workflow-actions">'
			. ( $can_assess ? '<button type="button" class="oj-ai-submission-review__primary ojp-button ojp-button--primary" data-review-action="bulk-grade" hidden disabled>' . Addon_Icon::markup( 'list' ) . ' 일괄 채점</button>' : '' )
			. ( $can_review ? '<button type="button" class="oj-ai-submission-review__secondary ojp-button" data-workflow-bulk="accept" hidden disabled>' . Addon_Icon::markup( 'list' ) . ' AI 점수 일괄 승인</button><button type="button" class="oj-ai-submission-review__secondary ojp-button" data-workflow-bulk="feedback" hidden disabled>' . Addon_Icon::markup( 'list' ) . ' 피드백 일괄 요청</button>' : '' )
			. ( $can_manage_student_notes ? '<button type="button" class="oj-ai-submission-review__secondary ojp-button" data-workflow-bulk="note" hidden disabled>' . Addon_Icon::markup( 'list' ) . ' 특기사항 일괄 요청</button>' : '' )
			. ( $can_assess || $can_review ? '<button type="button" class="oj-ai-submission-review__secondary ojp-button" data-review-action="download-scores" hidden disabled title="배포 전체 점수 CSV 다운로드">' . Addon_Icon::markup( 'download' ) . ' 점수 다운로드</button>' : '' )
			. '<button type="button" class="oj-ai-submission-review__icon-button ojp-button ojp-button--plain" data-review-action="refresh" aria-label="새로고침" title="새로고침">' . Addon_Icon::markup( 'refresh-cw' ) . '</button></div></header>'
			. '<div class="oj-ai-submission-review__filters">'
			. '<label><span>그룹(반)</span><select data-review-field="context"><option value="">그룹(반) 선택</option></select></label>'
			. '<label><span>배포</span><select data-review-field="assignment" disabled><option value="">전체 배포</option></select></label>'
			. '<label hidden><span>제출 상태</span><select data-review-field="submission-status"><option value="">전체 학생</option><option value="submitted">제출</option><option value="not_submitted">미제출</option><option value="not_assigned">배포 대상 아님</option></select></label>'
			. '<label><span>평가 상태</span><select data-review-field="state"><option value="" selected>전체</option>'
			. ( $can_assess ? '<option value="not_evaluated">평가 전</option>' : '' )
			. '<option value="requested">요청됨</option><option value="queued">대기</option><option value="running">진행 중</option><option value="review_required">검토 대기</option><option value="reviewed">검토 완료</option><option value="failed">평가 실패</option></select></label>'
			. '<label class="oj-ai-submission-review__search"><span>찾기</span><input type="search" data-review-field="search" placeholder="활동지 또는 학생" autocomplete="off"></label>'
			. '<label><span>학생 표시</span><select data-learner-label-mode><option value="management_name">관리용 이름</option><option value="login">아이디</option><option value="display_name" selected>표시이름</option></select></label>'
			. '</div>'
			. '<p class="oj-ai-submission-review__notice" data-review-role="notice" role="status">수업을 불러오는 중입니다.</p>'
			. '<section class="oj-ai-submission-review__batch" data-review-role="batch" aria-label="일괄 채점 등록 현황" hidden>'
			. '<p data-review-role="batch-status" role="status" aria-live="polite"></p>'
			. '<div class="oj-ai-submission-review__header-actions"><button type="button" data-review-action="bulk-resume" hidden>등록 이어서</button><button type="button" data-review-action="bulk-stop" hidden>등록 중지</button><button type="button" data-review-action="bulk-dismiss" hidden>닫기</button></div>'
			. '<details data-review-role="batch-failures" hidden><summary>등록 실패</summary><ul></ul></details></section>'
			. '<section class="oj-ai-submission-review__batch" data-review-role="workflow-progress" aria-label="일괄 작업 현황" hidden><p data-review-role="workflow-progress-status" role="status" aria-live="polite"></p>'
			. '<div class="oj-ai-submission-review__header-actions"><button type="button" data-workflow-control="resume" hidden>이어서 처리</button><button type="button" data-workflow-control="stop" hidden>처리 중지</button><button type="button" data-workflow-control="dismiss" hidden>닫기</button></div>'
			. '<details><summary>학생별 처리 결과</summary><ul data-review-role="workflow-results"></ul></details></section>'
			. '<div class="oj-ai-submission-review__layout" data-review-role="content" hidden>'
			. '<aside class="oj-ai-submission-review__inbox" aria-label="학생 제출 평가 목록" tabindex="0"><div data-review-role="list"></div>'
			. '<button type="button" data-review-action="load-more" class="oj-ai-submission-review__load-more ojp-button" hidden>더 보기</button></aside>'
			. '<section class="oj-ai-submission-review__detail" data-review-role="detail" aria-label="학생 평가 상세" aria-live="polite" tabindex="0">'
			. '<div class="oj-ai-submission-review__empty"><strong>제출을 선택하세요.</strong><span>학생 답변과 AI 근거가 여기에 표시됩니다.</span></div>'
			. '</section></div>'
			. $this->workflow_bulk_dialog()
			. '<dialog class="oj-ai-submission-review__dialog" data-review-role="score-download-dialog" aria-labelledby="oj-ai-score-download-title"><form method="dialog">'
			. '<header><h2 id="oj-ai-score-download-title">점수 다운로드</h2><button type="submit" value="cancel" class="oj-ai-submission-review__dialog-close ojp-button ojp-button--plain" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<p data-review-role="score-download-scope"></p><p>대상: 배포 전체 · 검색·상태 필터 미적용<br>형식: CSV · AI 점수와 교사 확정 점수 별도 열</p>'
			. '<label><span>점수 범위</span><select data-review-field="score-download-mode"><option value="all">채점 완료 전체</option><option value="official">교사 확정 점수만</option></select></label>'
			. '<p data-review-role="score-download-status" role="status" aria-live="polite"></p>'
			. '<footer><button type="submit" value="cancel">취소</button><button type="button" class="oj-ai-submission-review__primary ojp-button ojp-button--primary" data-review-action="confirm-score-download">' . Addon_Icon::markup( 'download' ) . ' CSV 다운로드</button></footer></form></dialog>'
			. '<dialog class="oj-ai-submission-review__dialog" data-review-role="batch-dialog" aria-labelledby="oj-ai-submission-batch-title">'
			. '<form method="dialog"><header><h2 id="oj-ai-submission-batch-title">일괄 채점</h2><button type="submit" value="cancel" class="oj-ai-submission-review__dialog-close ojp-button ojp-button--plain" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<p data-review-role="batch-scope"></p><p data-review-role="batch-targets"></p><p>대상: 선택한 배포 전체의 미채점 공식 제출 · 평가 실패<br>제외: 미제출 · 채점 대기/진행 중 · 검토 대기/완료 · 취소 · 재제출 작성 중<br>학생 공개: 미공개</p>'
			. '<footer><button type="submit" value="cancel">취소</button><button type="button" class="oj-ai-submission-review__primary ojp-button ojp-button--primary" data-review-action="bulk-confirm">채점 시작</button></footer></form></dialog>'
			. '<dialog class="oj-ai-submission-review__dialog" data-review-role="reject-dialog" aria-labelledby="oj-ai-submission-reject-title">'
			. '<form method="dialog"><header><h2 id="oj-ai-submission-reject-title">평가 반려</h2>'
			. '<button type="submit" value="cancel" class="oj-ai-submission-review__dialog-close ojp-button ojp-button--plain" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<label><span>반려 사유</span><textarea rows="4" maxlength="2000" data-review-field="reject-reason" placeholder="다시 평가해야 하는 이유를 입력하세요."></textarea></label>'
			. '<p data-review-role="reject-status" class="oj-ai-submission-review__dialog-status" role="alert"></p>'
			. '<footer><button type="submit" value="cancel">취소</button><button type="button" class="oj-ai-submission-review__danger ojp-button ojp-button--danger" data-review-action="confirm-reject">반려</button></footer>'
			. '</form></dialog>'
			. '<dialog class="oj-ai-submission-review__dialog" data-feedback-role="reject-dialog" aria-labelledby="oj-ai-submission-feedback-reject-title">'
			. '<form method="dialog"><header><h2 id="oj-ai-submission-feedback-reject-title">피드백 기각</h2>'
			. '<button type="submit" value="cancel" class="oj-ai-submission-review__dialog-close ojp-button ojp-button--plain" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<label><span>기각 사유</span><textarea rows="4" maxlength="2000" data-feedback-field="reject-reason" placeholder="다시 작성할 이유를 입력하세요."></textarea></label>'
			. '<p data-feedback-role="reject-status" class="oj-ai-submission-review__dialog-status" role="alert"></p>'
			. '<footer><button type="submit" value="cancel">취소</button><button type="button" class="oj-ai-submission-review__danger ojp-button ojp-button--danger" data-feedback-action="confirm-reject">기각</button></footer>'
			. '</form></dialog>'
			. '<dialog class="oj-ai-submission-review__dialog" data-student-note-role="reject-dialog" aria-labelledby="oj-ai-submission-student-note-reject-title">'
			. '<form method="dialog"><header><h2 id="oj-ai-submission-student-note-reject-title">특기사항 기각</h2>'
			. '<button type="submit" value="cancel" class="oj-ai-submission-review__dialog-close ojp-button ojp-button--plain" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<label><span>기각 사유</span><textarea rows="4" maxlength="2000" data-student-note-field="reject-reason" placeholder="다시 작성할 이유를 입력하세요."></textarea></label>'
			. '<p data-student-note-role="reject-status" class="oj-ai-submission-review__dialog-status" role="alert"></p>'
			. '<footer><button type="submit" value="cancel">취소</button><button type="button" class="oj-ai-submission-review__danger ojp-button ojp-button--danger" data-student-note-action="confirm-reject">기각</button></footer>'
			. '</form></dialog>'
			. '<dialog class="oj-ai-submission-review__dialog" data-release-role="dialog" aria-labelledby="oj-ai-submission-release-title">'
			. '<form method="dialog"><header><h2 id="oj-ai-submission-release-title" data-release-role="title">결과 공개</h2>'
			. '<button type="submit" value="cancel" class="oj-ai-submission-review__dialog-close ojp-button ojp-button--plain" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<label><span>점수 공개 범위</span><select data-release-field="score-visibility">'
			. '<option value="total">총점만</option>'
			. '<option value="total_and_criteria" selected>총점 + 기준별 점수</option>'
			. '<option value="hidden">점수 비공개</option>'
			. '</select></label>'
			. '<label><span>피드백 공개</span><select data-release-field="feedback-visibility">'
			. '<option value="shown">공개</option>'
			. '<option value="hidden">비공개</option>'
			. '</select></label>'
			. '<div data-release-role="reason-section" hidden>'
			. '<label><span>정정 사유</span><select data-release-field="reason-code">'
			. '<option value="">사유 선택</option>'
			. '<option value="data_correction">점수/내용 정정</option>'
			. '<option value="publication_error">공개 오류</option>'
			. '<option value="privacy_request">개인정보 요청</option>'
			. '<option value="other">기타</option>'
			. '</select></label>'
			. '<label><span>메모</span><textarea rows="3" maxlength="2000" data-release-field="reason-note" placeholder="정정 사유를 자세히 입력하세요."></textarea></label>'
			. '</div>'
			. '<p data-release-role="status" class="oj-ai-submission-review__dialog-status" role="alert"></p>'
			. '<footer><button type="submit" value="cancel">취소</button><button type="button" class="oj-ai-submission-review__primary ojp-button ojp-button--primary" data-release-action="confirm">공개</button></footer>'
			. '</form></dialog></section>';
	}

	/**
	 * Confirmation and target selection for existing review/report commands.
	 *
	 * @return string
	 */
	private function workflow_bulk_dialog(): string {
		return '<dialog class="oj-ai-submission-review__dialog" data-review-role="workflow-dialog" aria-labelledby="oj-ai-workflow-bulk-title"><form method="dialog">'
			. '<header><h2 id="oj-ai-workflow-bulk-title" data-review-role="workflow-title"></h2><button type="submit" value="cancel" class="oj-ai-submission-review__dialog-close ojp-button ojp-button--plain" aria-label="닫기" title="닫기">' . Addon_Icon::markup( 'x' ) . '</button></header>'
			. '<p data-review-role="workflow-scope"></p><p data-review-role="workflow-policy"></p><p data-review-role="workflow-preview-status" role="status" aria-live="polite"></p>'
			. '<label class="oj-ai-submission-review__bulk-check"><input type="checkbox" data-review-role="workflow-select-all" checked><span>대상 전체 선택</span></label>'
			. '<div class="oj-ai-submission-review__bulk-targets" data-review-role="workflow-targets"></div>'
			. '<details><summary>제외 대상</summary><ul class="oj-ai-submission-review__bulk-exclusions" data-review-role="workflow-exclusions"></ul></details>'
			. '<label class="oj-ai-submission-review__bulk-check" data-review-role="workflow-attestation"><input type="checkbox" data-review-role="workflow-reviewed"><span>선택한 학생의 AI 점수와 채점 근거를 검토했습니다.</span></label>'
			. '<footer><button type="submit" value="cancel">취소</button><button type="button" class="oj-ai-submission-review__primary ojp-button ojp-button--primary" data-workflow-control="confirm" disabled>실행</button></footer></form></dialog>';
	}

	/**
	 * Application attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_review Can review.
	 * @return string
	 */
	private function application_attributes( AddonPageRequestV1 $request, bool $can_review ): string {
		$values = $this->base_attributes( $request ) + array(
			'data-can-review'        => $can_review ? '1' : '0',
			'data-consent-recorded'  => $this->consent_recorded() ? '1' : '0',
			'data-settings-url'      => $this->settings_url(),
			'data-edit-url-template' => $this->edit_url_template(),
		);
		return $this->attributes( $values );
	}

	/**
	 * Submission attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_assess Can assess.
	 * @param bool               $can_review Can review.
	 * @param bool               $can_manage_student_notes Can manage student notes.
	 * @return string
	 */
	private function submission_attributes( AddonPageRequestV1 $request, bool $can_assess, bool $can_review, bool $can_manage_student_notes ): string {
		$can_publish = $this->authorize( $request, 'submission.result.publish', array( Addon_Capabilities::PUBLISH_FEEDBACK ) )->is_allowed();
		return $this->attributes(
			$this->base_attributes( $request ) + array(
				'data-can-assess'               => $can_assess ? '1' : '0',
				'data-actor-id'                 => (string) $request->actorUserId(),
				'data-can-review'               => $can_review ? '1' : '0',
				'data-can-manage-student-notes' => $can_manage_student_notes ? '1' : '0',
				'data-can-publish'              => $can_publish ? '1' : '0',
			)
		);
	}

	/**
	 * Base attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return array<string,string>
	 */
	private function base_attributes( AddonPageRequestV1 $request ): array {
		$rest_root = function_exists( 'rest_url' ) ? rest_url( 'oj-ai/v1' ) : '/wp-json/oj-ai/v1';
		$nonce     = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		return array(
			'data-rest-root'   => rtrim( (string) $rest_root, '/' ),
			'data-rest-nonce'  => (string) $nonce,
			'data-locale'      => $request->locale(),
			'data-timezone'    => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Asia/Seoul',
			'data-icon-sprite' => Addon_Icon::sprite_url(),
		);
	}

	/**
	 * Attributes.
	 *
	 * @param array<string,string> $values Values.
	 * @return string
	 */
	private function attributes( array $values ): string {
		$attributes = array();
		foreach ( $values as $name => $value ) {
			$attributes[] = $name . '="' . $this->escape( $value ) . '"';
		}
		return implode( ' ', $attributes );
	}

	/**
	 * This reports only that an approval record exists. The worker revalidates
	 * its hash and exact routing destination before every external call.
	 *
	 * @return bool
	 */
	private function consent_recorded(): bool {
		$value = function_exists( 'get_option' )
			? get_option( WordPress_Worksheet_Evaluation_Execution_Consent::OPTION, null )
			: null;
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		$expected = array(
			'approved_at_utc',
			'approved_by_user_id',
			'consent_hash',
			'destination',
			'input_categories',
			'schema_id',
			'schema_version',
			'state',
			'task_key',
		);
		return $expected === $keys
			&& WordPress_Worksheet_Evaluation_Execution_Consent::SCHEMA === ( $value['schema_id'] ?? null )
			&& WordPress_Worksheet_Evaluation_Execution_Consent::VERSION === ( $value['schema_version'] ?? null )
			&& 'approved' === ( $value['state'] ?? null )
			&& Addon_Manifest::TASK_WORKSHEET_QUALITY === ( $value['task_key'] ?? null )
			&& is_string( $value['consent_hash'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $value['consent_hash'] );
	}

	/**
	 * Settings url.
	 *
	 * @return string
	 */
	private function settings_url(): string {
		$path = '/oj-console/addons/oj-ai-dashboard/settings';
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * Edit url template.
	 *
	 * @return string
	 */
	private function edit_url_template(): string {
		$path = '/oj-console/addons/oj-ai-dashboard/worksheets/{worksheet_id}';
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * Denied response.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return AddonPageResponseV1
	 */
	private function denied_response( Access_Policy_Decision $decision ): AddonPageResponseV1 {
		if ( 503 === $decision->status() ) {
			return AddonPageResponseV1::unavailable( '평가를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		$status = 401 === $decision->status() ? 403 : $decision->status();
		if ( ! in_array( $status, array( 403, 404, 409 ), true ) ) {
			$status = 403;
		}
		$messages = array(
			403 => '이 화면을 볼 수 없습니다.',
			404 => '평가를 찾을 수 없습니다.',
			409 => '내용이 바뀌었습니다. 새로고침해 주세요.',
		);
		$body     = '<section class="oj-ai-evaluation" data-oj-ai-code="' . $this->escape( $decision->code() ) . '">'
			. '<header class="oj-ai-evaluation__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>평가</h1></div></header>'
			. '<p class="oj-ai-evaluation__notice" role="alert">' . $messages[ $status ] . '</p></section>';
		return Addon_Page_Response::html( '평가', $body, $status );
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
