<?php
/**
 * OJ Console storage operations detail Page handler.
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
 * A separate, more detailed Page linked from the "파일 보관" row of the
 * system-status widget. That widget's own renderer is deliberately
 * content-free by an enforced, unrelated contract test -- this Page is a
 * distinct route with its own manage_policies gate, so it may show full
 * storage metrics and worker cron-health detail. Still content-free in the
 * one sense that matters everywhere in this add-on: never a file name,
 * storage path, or hash -- counts, worker keys, and timestamps only.
 */
final class Storage_Operations_Detail_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize storage operations detail page handler dependencies and state.
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
	 * Handle storage operations detail page handler.
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
				'operations.storage.detail.view',
				array( Addon_Capabilities::MANAGE_POLICIES ),
				null,
				null,
				true,
				$request->correlationId()
			)
		);
		if ( ! $decision->is_allowed() ) {
			return $this->denied_response( $decision );
		}

		return Addon_Page_Response::html( '파일 보관 상세', Worksheet_Workflow::workspace( $request, $this->policy ) . $this->markup( $request ) );
	}

	/**
	 * Markup.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return string
	 */
	private function markup( AddonPageRequestV1 $request ): string {
		$attributes = $this->attributes( $request );

		return '<section id="oj-ai-storage-operations-detail" class="oj-ai-diagnostics oj-ai-storage-detail" ' . $attributes . '>'
			. '<header class="oj-ai-diagnostics__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>파일 보관 상세</h1>'
			. '<p data-storage-role="checked-at">확인 중</p></div><div class="oj-ai-diagnostics__heading-actions">'
			. '<button type="button" class="oj-ai-diagnostics__refresh ojp-button" data-storage-action="refresh"'
			. ' aria-label="다시 확인" title="다시 확인">' . Addon_Icon::markup( 'refresh-cw' ) . '</button></div></header>'
			. '<p class="oj-ai-diagnostics__notice" data-storage-role="notice" role="status">상태를 확인하는 중입니다.</p>'
			. '<section class="oj-ai-storage-detail__section">'
			. '<h2>보관 지표</h2>'
			. '<div class="oj-ai-diagnostics__list" data-storage-role="metrics" role="list" aria-live="polite" aria-busy="true" hidden></div>'
			. '</section>'
			. '<section class="oj-ai-storage-detail__section">'
			. '<h2>기간 만료 파일 분류</h2>'
			. '<p class="oj-ai-storage-detail__hint">보존 지정 파일도 포함한 만료 수입니다. 보존 지정과 연결된 파일 수는 중복될 수 있으며, 만료만으로 삭제되지는 않습니다.</p>'
			. '<div class="oj-ai-diagnostics__list" data-storage-role="backlog" role="list" aria-live="polite" hidden></div>'
			. '</section>'
			. '<section class="oj-ai-storage-detail__section">'
			. '<h2>작업자 실행 확인</h2>'
			. '<p class="oj-ai-storage-detail__hint">호출 기록은 처리 성공이나 정리 완료를 뜻하지 않습니다.</p>'
			. '<div class="oj-ai-diagnostics__list" data-storage-role="workers" role="list" aria-live="polite" aria-busy="true" hidden></div>'
			. '</section>'
			. '<section class="oj-ai-storage-detail__section">'
			. '<h2>지금 정리 실행</h2>'
			. '<p class="oj-ai-storage-detail__hint">만료된 내보내기 파일과 정리 조건을 충족한 학생 첨부·ZIP 임시 파일이 대상입니다. 원본 활동지·제출 내용, 보존 지정 파일과 사용 중인 파일은 유지됩니다.</p>'
			. '<button type="button" class="oj-ai-diagnostics__action ojp-button" data-storage-action="resume">지금 정리 실행</button>'
			. '<p data-storage-role="resume-status" role="status"></p>'
			. '</section>'
			. '</section>';
	}

	/**
	 * Attributes.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return string
	 */
	private function attributes( AddonPageRequestV1 $request ): string {
		$rest_root  = function_exists( 'rest_url' ) ? rest_url( 'oj-ai/v1' ) : '/wp-json/oj-ai/v1';
		$nonce      = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		$values     = array(
			'data-rest-root'  => rtrim( (string) $rest_root, '/' ),
			'data-rest-nonce' => (string) $nonce,
			'data-locale'     => $request->locale(),
			'data-timezone'   => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Asia/Seoul',
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
			return AddonPageResponseV1::unavailable( '파일 보관 상세를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		$status = 401 === $decision->status() ? 403 : $decision->status();
		if ( ! in_array( $status, array( 403, 404, 409 ), true ) ) {
			$status = 403;
		}
		$message = 409 === $status ? '내용이 바뀌었습니다. 새로고침해 주세요.' : '이 화면을 볼 수 없습니다.';
		$body    = '<section class="oj-ai-diagnostics" data-oj-ai-code="' . $this->escape( $decision->code() ) . '">'
			. '<header class="oj-ai-diagnostics__header ojp-page-header"><div><p class="ojp-eyebrow">활동지 평가</p><h1>파일 보관 상세</h1></div></header>'
			. '<p class="oj-ai-diagnostics__notice" role="alert">' . $message . '</p></section>';
		return Addon_Page_Response::html( '파일 보관 상세', $body, $status );
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
