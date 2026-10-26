<?php
/**
 * Private binary storage port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private binary storage port.
 */
interface Private_File_Store_Port_Interface {
	/**
	 * Returns true when this call created the target.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool;

	/**
	 * Delete private file store port interface.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void;
}
