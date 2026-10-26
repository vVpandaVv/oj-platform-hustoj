<?php
/**
 * Worksheet report source query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet report source query port interface.
 */
interface Worksheet_Report_Source_Query_Port_Interface {
	/**
	 * By publications.
	 *
	 * @param array<int,array{id:string,hash:string}> $publications Publications.
	 * @return array<int,array<string,mixed>>
	 */
	public function by_publications( array $publications ): array;
}
