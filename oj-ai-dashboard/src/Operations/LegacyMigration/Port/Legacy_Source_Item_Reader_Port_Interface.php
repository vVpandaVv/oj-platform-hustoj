<?php
/**
 * Legacy source item reader port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy source item reader port interface.
 */
interface Legacy_Source_Item_Reader_Port_Interface {
	/**
	 * Read legacy source item reader port interface.
	 *
	 * @param string $source_profile Source profile.
	 * @param int    $table_index Table index.
	 * @param int    $after_source_id After source id.
	 * @param int    $next_item_no Next item no.
	 * @param int    $limit Maximum number of entries requested.
	 * @return Legacy_Source_Item_Batch
	 */
	public function read(
		string $source_profile,
		int $table_index,
		int $after_source_id,
		int $next_item_no,
		int $limit
	): Legacy_Source_Item_Batch;
}
