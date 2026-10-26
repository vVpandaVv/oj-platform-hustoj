<?php
/**
 * Narrow purge-recovery adapter over the single common access policy.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy;

use OJAI\Dashboard\Contracts\Platform_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrow purge-recovery adapter over the single common access policy.
 */
final class Purge_Recovery_Access_Policy implements Access_Policy_Port_Interface {
	/**
	 * Initialize purge recovery access policy dependencies and state.
	 *
	 * @param Access_Policy                             $policy Policy.
	 * @param Platform_Runtime_Readiness_Port_Interface $runtime Runtime.
	 */
	public function __construct(
		private Access_Policy $policy,
		private Platform_Runtime_Readiness_Port_Interface $runtime
	) {
	}

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		return $this->policy->authorize_purge_recovery( $request, $this->runtime );
	}
}
