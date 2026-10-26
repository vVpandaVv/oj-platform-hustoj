<?php
/**
 * Worksheet asset metadata repository port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet asset metadata repository port.
 */
interface Worksheet_Asset_Repository_Port_Interface {
	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array<string,mixed>|null
	 */
	public function find_by_storage_key( string $storage_key ): ?array;

	/**
	 * Insert quarantined.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	public function insert_quarantined( array $record ): void;
}
