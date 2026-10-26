<?php
/**
 * Platform Context authorization port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy\Port;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorizes one actor against the current Platform Context state.
 */
interface Context_Authorization_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param int           $actor_user_id Authenticated actor from the access context.
	 * @param Context_Scope $scope Scope.
	 * @return Context_Authorization_Result
	 */
	public function authorize( int $actor_user_id, Context_Scope $scope ): Context_Authorization_Result;
}
