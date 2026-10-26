<?php
/**
 * Generated private file metadata port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Generated private file metadata port interface.
 */
interface Generated_Private_File_Metadata_Port_Interface {
	/**
	 * Insert ready.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	public function insert_ready( array $record ): void;
	/**
	 * Find ready for owner.
	 *
	 * @param string $file_public_id File public id.
	 * @param string $owner_type Owner type.
	 * @param string $owner_public_id Owner public id.
	 * @return array<string,mixed>|null
	 */
	public function find_ready_for_owner( string $file_public_id, string $owner_type, string $owner_public_id ): ?array;
}
