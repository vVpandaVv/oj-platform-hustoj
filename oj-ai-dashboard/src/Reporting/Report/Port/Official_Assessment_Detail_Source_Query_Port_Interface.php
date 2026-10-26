<?php
/**
 * Official assessment detail source query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Official assessment detail source query port interface.
 */
interface Official_Assessment_Detail_Source_Query_Port_Interface {
	/**
	 * By report details.
	 *
	 * @param array<int,array<string,mixed>> $detail_sources Detail sources.
	 * @return array<int,array<string,mixed>>
	 */
	public function by_report_details( array $detail_sources ): array;
}
