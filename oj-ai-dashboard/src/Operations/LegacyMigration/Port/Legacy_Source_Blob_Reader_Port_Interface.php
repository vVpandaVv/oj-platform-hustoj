<?php
/**
 * Legacy source blob reader port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Blob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves one legacy WordPress attachment without exposing it in a receipt.
 */
interface Legacy_Source_Blob_Reader_Port_Interface {
	/**
	 * Read legacy source blob reader port interface.
	 *
	 * @param int $file_id File id.
	 * @return Legacy_Source_Blob
	 */
	public function read( int $file_id ): Legacy_Source_Blob;
}
