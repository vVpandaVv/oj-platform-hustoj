<?php
/**
 * PublicUI student result Page handler.
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
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the result shell after the same current-release authorization used by Q33.
 */
final class Student_Result_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize student result page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface           $policy Policy.
	 * @param Submission_Result_Query_Port_Interface $results Results.
	 */
	public function __construct(
		private Access_Policy_Port_Interface $policy,
		private Submission_Result_Query_Port_Interface $results
	) {
	}

	/**
	 * Handle student result page handler.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( Addon_Manifest::PUBLIC_RESULT !== $request->routeKey() ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}
		if ( array() !== $request->query() || array( 'release_id' ) !== array_keys( $request->parameters() ) ) {
			return $this->not_found();
		}

		$release_id = $request->parameters()['release_id'];
		if ( ! Public_Id::is_valid( $release_id ) ) {
			return $this->not_found();
		}
		try {
			$snapshot = $this->results->authorization_snapshot( $release_id, $request->actorUserId() );
		} catch ( Throwable ) {
			return AddonPageResponseV1::unavailable( '결과를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		if ( null === $snapshot || 'active' !== $snapshot['target_state'] || 'withdrawn' === $snapshot['assignment_state'] ) {
			return $this->not_found();
		}

		$decision = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_PUBLIC,
				Access_Policy_Request::SURFACE_PAGE,
				Addon_Manifest::PUBLIC_RESULT,
				array( Addon_Capabilities::PARTICIPATE ),
				new Context_Scope(
					$snapshot['context_type'],
					$snapshot['context_id'],
					Context_Scope::OPERATION_PARTICIPATE,
					$request->actorUserId(),
					$snapshot['context_revision']
				),
				new Object_Scope( Object_Scope::TYPE_RELEASE, $release_id, 'self_view' ),
				true,
				$request->correlationId()
			)
		);
		if ( ! $decision->is_allowed() ) {
			return $this->denied_response( $decision );
		}

		$body = '<section class="oj-ai-student-page oj-ai-student-result-page" data-oj-ai-route="' . $this->escape( Addon_Manifest::PUBLIC_RESULT ) . '">'
			. '<a class="oj-ai-student-back ojp-button ojp-button--plain" href="/oj-addons/oj-ai-dashboard/worksheets">활동지 목록</a>'
			. '<header class="oj-ai-student-page__header ojp-page-header"><div><h1>활동지 결과</h1></div></header>'
			. '<div id="oj-ai-student-result-app" data-release-id="' . $this->escape( $release_id ) . '" data-locale="' . $this->escape( $this->locale( $request->locale() ) ) . '" data-timezone="' . $this->escape( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Asia/Seoul' ) . '" aria-busy="true">'
			. '<p class="oj-ai-student-page__status" role="status" aria-live="polite">불러오는 중...</p>'
			. '</div></section>';

		return Addon_Page_Response::html( '활동지 결과', $body );
	}

	/**
	 * Denied response.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return AddonPageResponseV1
	 */
	private function denied_response( Access_Policy_Decision $decision ): AddonPageResponseV1 {
		if ( 503 === $decision->status() ) {
			return AddonPageResponseV1::unavailable( '결과를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		$status = in_array( $decision->status(), array( 403, 404, 409 ), true ) ? $decision->status() : 403;
		return Addon_Page_Response::html( '활동지 결과', $this->message( '결과를 확인할 수 없습니다.', $decision->code() ), $status );
	}

	/**
	 * Not found.
	 *
	 * @return AddonPageResponseV1
	 */
	private function not_found(): AddonPageResponseV1 {
		return Addon_Page_Response::html( '활동지 결과', $this->message( '결과를 확인할 수 없습니다.', 'not_found' ), 404 );
	}

	/**
	 * Message.
	 *
	 * @param string $message Message.
	 * @param string $code Code.
	 * @return string
	 */
	private function message( string $message, string $code ): string {
		return '<section class="oj-ai-student-page oj-ai-student-result-page" data-oj-ai-code="' . $this->escape( $code ) . '">'
			. '<a class="oj-ai-student-back ojp-button ojp-button--plain" href="/oj-addons/oj-ai-dashboard/worksheets">활동지 목록</a>'
			. '<header class="oj-ai-student-page__header ojp-page-header"><div><h1>활동지 결과</h1></div></header>'
			. '<p class="oj-ai-student-page__status" role="alert">' . $this->escape( $message ) . '</p></section>';
	}

	/**
	 * Locale.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function locale( string $value ): string {
		$value = str_replace( '_', '-', trim( $value ) );
		return 1 === preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $value ) ? $value : 'ko-KR';
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
