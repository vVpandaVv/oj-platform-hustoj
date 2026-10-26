<?php
/**
 * Teaching Context query port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context\Port;

use OJAI\Dashboard\Context\Teaching_Context_Query;
use OJAI\Dashboard\Context\Teaching_Context_Query_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists teacher-managed Contexts without leaking provider contracts.
 */
interface Teaching_Context_Query_Port_Interface {
	/**
	 * Query.
	 *
	 * @param Teaching_Context_Query $query Query.
	 * @return Teaching_Context_Query_Result
	 */
	public function query( Teaching_Context_Query $query ): Teaching_Context_Query_Result;
}
