<?php
/**
 * OJ Console worksheet report dashboard Page handler.
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
 * Renders the teacher-only Q20 to Q21 report explorer.
 */
final class Report_Dashboard_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize report dashboard page handler dependencies and state.
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
	 * Handle report dashboard page handler.
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
				'report.dashboard.view',
				array( Addon_Capabilities::VIEW_REPORTS ),
				null,
				null,
				true,
				$request->correlationId()
			)
		);
		if ( ! $decision->is_allowed() ) {
			return $this->denied_response( $decision );
		}
		$export = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_CONSOLE,
				Access_Policy_Request::SURFACE_PAGE,
				'report.export.open',
				array( Addon_Capabilities::EXPORT_REPORTS ),
				null,
				null,
				true,
				$request->correlationId()
			)
		);

		return Addon_Page_Response::html(
			'보고서',
			$this->markup( $request, $export->is_allowed() )
		);
	}

	/**
	 * Markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_export Can export.
	 * @return string
	 */
	private function markup( AddonPageRequestV1 $request, bool $can_export ): string {
		$export = $can_export
			? '<section class="oj-ai-report__export" data-role="export-controls"><div><strong>파일</strong><span data-role="export-status" role="status">활동지별 보고서</span></div>'
				. '<fieldset><legend class="screen-reader-text">파일 형식</legend>'
				. '<label><input type="radio" name="oj-ai-report-format" value="pdf" checked><span>PDF</span></label>'
				. '<label><input type="radio" name="oj-ai-report-format" value="csv"><span>CSV</span></label>'
				. '<label><input type="radio" name="oj-ai-report-format" value="zip"><span>ZIP</span></label></fieldset>'
				. '<button type="button" class="oj-ai-report__primary ojp-button ojp-button--primary" data-action="export-report">파일 만들기</button></section>'
			: '';

		return '<section id="oj-ai-report-app" class="oj-ai-report" ' . $this->attributes( $request, $can_export ) . '>'
			. Worksheet_Workflow::workspace( $request, $this->policy )
			. Worksheet_Workflow::header( $request, 'report', $this->policy )
			. '<header class="oj-ai-report__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>보고서</h1><p data-role="summary">그룹(반)을 선택하세요.</p></div>'
			. '<p class="oj-ai-report__baseline" data-role="baseline" hidden></p></header>'
			. '<div class="oj-ai-report__lenses" role="tablist" aria-label="보고서 보기">'
			. '<button type="button" role="tab" data-lens="worksheet" aria-selected="true">활동지별</button>'
			. '<button type="button" role="tab" data-lens="group" aria-selected="false">배포 대상별</button>'
			. '<button type="button" role="tab" data-lens="student" aria-selected="false">학생별</button></div>'
			. '<form class="oj-ai-report__filters" data-role="filters" novalidate>'
			. '<label class="oj-ai-report__context"><span>그룹(반)</span><select data-field="context"><option value="">그룹(반) 선택</option></select></label>'
			. '<label><span>시작 날짜</span><input type="date" data-field="period-from"></label>'
			. '<label><span>마지막 날짜</span><input type="date" data-field="period-through"></label>'
			. '<details class="oj-ai-report__state-filter"><summary>상태 필터</summary><div class="oj-ai-report__state-groups">'
			. '<fieldset><legend>배포</legend><label><input type="checkbox" data-filter="assignment" value="scheduled" checked><span>예약</span></label>'
			. '<label><input type="checkbox" data-filter="assignment" value="open" checked><span>진행</span></label>'
			. '<label><input type="checkbox" data-filter="assignment" value="closed" checked><span>종료</span></label></fieldset>'
			. '<fieldset><legend>제출</legend><label><input type="checkbox" data-filter="submission" value="not_started" checked><span>미시작</span></label>'
			. '<label><input type="checkbox" data-filter="submission" value="in_progress" checked><span>작성 중</span></label>'
			. '<label><input type="checkbox" data-filter="submission" value="official" checked><span>제출 완료</span></label></fieldset>'
			. '</div></details><div class="oj-ai-report__filter-actions"><button type="button" data-action="reset-filters">초기화</button>'
			. '<button type="submit" class="oj-ai-report__primary ojp-button ojp-button--primary">조회</button></div></form>'
			. '<p class="oj-ai-report__notice" data-role="notice" role="status">배포 대상을 불러오는 중입니다.</p>'
			. '<section class="oj-ai-report__content" data-role="content" hidden aria-busy="false">'
			. '<nav class="oj-ai-report__trail" aria-label="보고서 위치"><button type="button" data-action="back" hidden>이전</button>'
			. '<ol data-role="breadcrumbs"></ol></nav>'
			. '<header class="oj-ai-report__view-header"><div><p data-role="view-eyebrow"></p><h2 data-role="view-title">활동지별</h2></div>'
			. '<p data-role="view-count"></p></header>'
			. '<dl class="oj-ai-report__metrics" data-role="metrics"></dl>'
			. '<div class="oj-ai-report__list" data-role="list"></div>'
			. '<div class="oj-ai-report__more" data-role="more" hidden><button type="button" data-action="load-more">더 보기</button></div>'
			. '</section>' . $export . '</section>';
	}

	/**
	 * Attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @param bool               $can_export Can export.
	 * @return string
	 */
	private function attributes( AddonPageRequestV1 $request, bool $can_export ): string {
		$rest_root  = function_exists( 'rest_url' ) ? rest_url( 'oj-ai/v1' ) : '/wp-json/oj-ai/v1';
		$nonce      = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		$values     = array(
			'data-rest-root'  => rtrim( (string) $rest_root, '/' ),
			'data-rest-nonce' => (string) $nonce,
			'data-locale'     => $request->locale(),
			'data-timezone'   => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Asia/Seoul',
			'data-can-export' => $can_export ? '1' : '0',
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
			return AddonPageResponseV1::unavailable( '보고서를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		$status = 401 === $decision->status() ? 403 : $decision->status();
		if ( ! in_array( $status, array( 403, 404, 409 ), true ) ) {
			$status = 403;
		}
		$message = 409 === $status ? '내용이 바뀌었습니다. 새로고침해 주세요.' : '이 보고서를 볼 수 없습니다.';
		$body    = '<section class="oj-ai-report" data-oj-ai-code="' . $this->escape( $decision->code() ) . '">'
			. '<header class="oj-ai-report__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>보고서</h1></div></header>'
			. '<p class="oj-ai-report__notice" role="alert">' . $message . '</p></section>';
		return Addon_Page_Response::html( '보고서', $body, $status );
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
