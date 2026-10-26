<?php
/**
 * PublicUI student assignment Page handler.
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
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the short PublicUI shell; Q30/Q31 supply all learner data.
 */
final class Student_Assignment_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Initialize student assignment page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface             $policy Policy.
	 * @param Student_Assignment_Access_Port_Interface $assignments Assignments.
	 */
	public function __construct(
		private Access_Policy_Port_Interface $policy,
		private Student_Assignment_Access_Port_Interface $assignments
	) {
	}

	/**
	 * Handle student assignment page handler.
	 *
	 * @param AddonPageRequestV1 $request Request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( ! in_array( $request->routeKey(), array( Addon_Manifest::PUBLIC_WORKSHEETS, Addon_Manifest::PUBLIC_ASSIGNMENT ), true ) ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}
		if ( array() !== $request->query() ) {
			return Addon_Page_Response::html( '활동지', $this->message( '잘못된 요청입니다.', 'invalid_request' ), 404 );
		}

		$assignment_id = null;
		$context       = null;
		$object        = null;
		if ( Addon_Manifest::PUBLIC_ASSIGNMENT === $request->routeKey() ) {
			$parameters = $request->parameters();
			if ( array( 'assignment_id' ) !== array_keys( $parameters ) ) {
				return Addon_Page_Response::html( '활동지', $this->message( '활동지를 찾을 수 없습니다.', 'not_found' ), 404 );
			}
			$assignment_id = $parameters['assignment_id'];
			try {
				new Public_Id( $assignment_id );
				$snapshot = $this->assignments->authorization_snapshot( $assignment_id, $request->actorUserId() );
			} catch ( Throwable ) {
				return AddonPageResponseV1::unavailable( '활동지를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
			}
			if ( null === $snapshot || 'active' !== $snapshot['target_state'] ) {
				return Addon_Page_Response::html( '활동지', $this->message( '활동지를 찾을 수 없습니다.', 'not_found' ), 404 );
			}
			$context = new Context_Scope(
				$snapshot['context_type'],
				$snapshot['context_id'],
				Context_Scope::OPERATION_PARTICIPATE,
				$request->actorUserId(),
				null
			);
			$object  = new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $assignment_id, 'self_view' );
		}

		$decision = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				Access_Policy_Request::SHELL_PUBLIC,
				Access_Policy_Request::SURFACE_PAGE,
				$request->routeKey(),
				array( Addon_Capabilities::PARTICIPATE ),
				$context,
				$object,
				true,
				$request->correlationId()
			)
		);
		if ( ! $decision->is_allowed() ) {
			return $this->denied_response( $decision );
		}

		$mode         = null === $assignment_id ? 'list' : 'detail';
		$id_attribute = null === $assignment_id ? '' : ' data-assignment-id="' . $this->escape( $assignment_id ) . '"';
		$body         = '<section class="oj-ai-student-page" data-oj-ai-route="' . $this->escape( $request->routeKey() ) . '">'
			. '<header class="oj-ai-student-page__header ojp-page-header"><div><h1>활동지</h1></div></header>'
			. '<div id="oj-ai-student-assignment-app" data-mode="' . $mode . '" data-timezone="' . $this->escape( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Asia/Seoul' ) . '"' . $id_attribute . '>'
			. '<p class="oj-ai-student-page__status" role="status">불러오는 중...</p>'
			. '</div></section>';

		return Addon_Page_Response::html( null === $assignment_id ? '활동지' : '활동지 작성', $body );
	}

	/**
	 * Denied response.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return AddonPageResponseV1
	 */
	private function denied_response( Access_Policy_Decision $decision ): AddonPageResponseV1 {
		if ( 503 === $decision->status() ) {
			return AddonPageResponseV1::unavailable( '활동지를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}
		$status  = in_array( $decision->status(), array( 403, 404, 409 ), true ) ? $decision->status() : 403;
		$message = 409 === $status ? '내용이 바뀌었습니다. 새로고침해 주세요.' : ( 404 === $status ? '활동지를 찾을 수 없습니다.' : '이 화면을 볼 수 없습니다.' );

		return Addon_Page_Response::html( '활동지', $this->message( $message, $decision->code() ), $status );
	}

	/**
	 * Message.
	 *
	 * @param string $message Message.
	 * @param string $code Code.
	 * @return string
	 */
	private function message( string $message, string $code ): string {
		return '<section class="oj-ai-student-page" data-oj-ai-code="' . $this->escape( $code ) . '">'
			. '<a class="oj-ai-student-back ojp-button ojp-button--plain" href="' . $this->escape( function_exists( 'home_url' ) ? home_url( '/oj-addons/oj-ai-dashboard/worksheets' ) : '/oj-addons/oj-ai-dashboard/worksheets' ) . '">활동지 목록</a>'
			. '<header class="oj-ai-student-page__header ojp-page-header"><div><h1>활동지</h1></div></header>'
			. '<p class="oj-ai-student-page__status" role="alert">' . $this->escape( $message ) . '</p></section>';
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
