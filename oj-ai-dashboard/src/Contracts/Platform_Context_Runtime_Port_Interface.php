<?php
/**
 * Platform Context Runtime contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Contracts;

use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes only the accepted add-on Context gateway.
 */
interface Platform_Context_Runtime_Port_Interface extends Platform_Runtime_Readiness_Port_Interface {
	/**
	 * Contexts.
	 *
	 * @return AddonContextGatewayV1|null
	 */
	public function contexts(): ?AddonContextGatewayV1;
}
