<?php
/**
 * Private binary read port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private binary read port.
 */
interface Private_File_Read_Port_Interface {
	/**
	 * Returns an internal path only after integrity verification.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 */
	public function readable_path( string $storage_key, string $sha256 ): string;
}
