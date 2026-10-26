<?php
/**
 * OJ Platform add-on Page handler.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns shell-safe HTML fragments and fails closed until a feature is ready.
 */
final class Addon_Page_Handler implements AddonPageHandlerV1 {
	/**
	 * Common access policy.
	 *
	 * @var Access_Policy_Port_Interface
	 */
	private $policy;

	/**
	 * Route key owned by this handler.
	 *
	 * @var string
	 */
	private $route_key;

	/**
	 * User-facing page title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Platform shell owning this route.
	 *
	 * @var string
	 */
	private $shell;

	/**
	 * Initialize addon page handler dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface $policy Common access policy.
	 * @param string                       $route_key Route key.
	 * @param string                       $title Page title.
	 * @param string                       $shell Platform shell.
	 */
	public function __construct(
		Access_Policy_Port_Interface $policy,
		$route_key,
		$title,
		$shell
	) {
		$this->policy    = $policy;
		$this->route_key = (string) $route_key;
		$this->title     = (string) $title;
		$this->shell     = (string) $shell;
	}

	/**
	 * Handle a GET-only platform Page request.
	 *
	 * @param AddonPageRequestV1 $request Page request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( $request->routeKey() !== $this->route_key ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}

		$decision = $this->policy->authorize(
			new Access_Policy_Request(
				$request->actorUserId(),
				$this->shell,
				Access_Policy_Request::SURFACE_PAGE,
				$this->route_key,
				array(),
				null,
				null,
				true,
				$request->correlationId()
			)
		);
		if ( ! $decision->is_allowed() ) {
			return $this->denied_response( $decision );
		}

		return AddonPageResponseV1::unavailable( '화면 연결을 확인할 수 없습니다. 시스템 상태를 확인해 주세요.', 'addon_runtime_unavailable' );
	}

	/**
	 * Convert a common policy denial to a shell-safe Page response.
	 *
	 * @param Access_Policy_Decision $decision Policy decision.
	 * @return AddonPageResponseV1
	 */
	private function denied_response( Access_Policy_Decision $decision ): AddonPageResponseV1 {
		if ( 503 === $decision->status() ) {
			return AddonPageResponseV1::unavailable(
				'활동지 평가를 지금 열 수 없습니다.',
				'addon_runtime_unavailable'
			);
		}

		$status = 401 === $decision->status() ? 403 : $decision->status();
		if ( ! in_array( $status, array( 403, 404, 409 ), true ) ) {
			$status = 403;
		}
		$messages = array(
			403 => '이 화면을 볼 수 없습니다.',
			404 => '대상을 찾을 수 없습니다.',
			409 => '내용이 바뀌었습니다. 새로고침해 주세요.',
		);
		$title    = htmlspecialchars( $this->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$code     = htmlspecialchars( $decision->code(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$body     = '<section class="oj-ai-addon-page" data-oj-ai-code="' . $code . '">'
			. '<header class="oj-ai-addon-page__header ojp-page-header"><div>' . $this->eyebrow() . '<h1>' . $title . '</h1></div></header>'
			. '<p class="oj-ai-addon-page__status" role="alert">' . $messages[ $status ] . '</p>'
			. '</section>';

		return Addon_Page_Response::html( $this->title, $body, $status );
	}

	/**
	 * Eyebrow naming the console menu; learner pages have none.
	 *
	 * @return string
	 */
	private function eyebrow(): string {
		return Access_Policy_Request::SHELL_CONSOLE === $this->shell ? '<p class="ojp-eyebrow">활동지 평가</p>' : '';
	}
}
