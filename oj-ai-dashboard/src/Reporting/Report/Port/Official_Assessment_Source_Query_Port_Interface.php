<?php
/**
 * Official assessment source query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Official assessment source query port interface.
 */
interface Official_Assessment_Source_Query_Port_Interface {
	/**
	 * By official reviews.
	 *
	 * @param array<int,array<string,mixed>> $progress_sources Progress sources.
	 * @return array<int,array<string,mixed>>
	 */
	public function by_official_reviews( array $progress_sources ): array;
}
