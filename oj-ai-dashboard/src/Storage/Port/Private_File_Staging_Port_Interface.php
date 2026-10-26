<?php
/**
 * Private file staging port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns metadata changes for one quarantined private file.
 */
interface Private_File_Staging_Port_Interface {
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
	 * @throws \Throwable When the metadata store cannot commit the quarantined record.
	 */
	public function insert_quarantined( array $record ): void;

	/**
	 * Mark purged.
	 *
	 * @param string $file_id File id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $purged_at_utc Purged at utc.
	 * @return void
	 */
	public function mark_purged( string $file_id, int $actor_user_id, string $purged_at_utc ): void;
}
