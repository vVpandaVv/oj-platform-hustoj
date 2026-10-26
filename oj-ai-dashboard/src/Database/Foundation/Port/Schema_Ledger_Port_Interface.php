<?php
/**
 * Schema ownership and migration ledger port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

use OJAI\Dashboard\Database\Foundation\Migration_Definition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the owner marker and immutable migration checkpoints.
 */
interface Schema_Ledger_Port_Interface extends Schema_Ledger_Reader_Port_Interface {
	/**
	 * Installation row count.
	 *
	 * @return int
	 */
	public function installation_row_count(): int;

	/**
	 * Create installation.
	 *
	 * @param string $owner_marker Owner marker.
	 * @param string $schema_version Schema version.
	 * @param string $schema_fingerprint Schema fingerprint.
	 * @param string $state State.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	public function create_installation(
		string $owner_marker,
		string $schema_version,
		string $schema_fingerprint,
		string $state,
		int $actor_user_id,
		string $occurred_at_utc
	): void;

	/**
	 * Update installation.
	 *
	 * @param string $schema_version Schema version.
	 * @param string $schema_fingerprint Schema fingerprint.
	 * @param string $state State.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @param bool   $verified Verified.
	 * @return void
	 */
	public function update_installation(
		string $schema_version,
		string $schema_fingerprint,
		string $state,
		int $actor_user_id,
		string $occurred_at_utc,
		bool $verified
	): void;

	/**
	 * Start migration.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param array<string,mixed>  $checkpoint Checkpoint.
	 * @param string               $started_at_utc Started at utc.
	 * @return void
	 */
	public function start_migration(
		Migration_Definition $migration,
		array $checkpoint,
		string $started_at_utc
	): void;

	/**
	 * Resume migration.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param int                  $attempt_count Attempt count.
	 * @param array<string,mixed>  $checkpoint Checkpoint.
	 * @param string               $started_at_utc Started at utc.
	 * @return void
	 */
	public function resume_migration(
		Migration_Definition $migration,
		int $attempt_count,
		array $checkpoint,
		string $started_at_utc
	): void;

	/**
	 * Save checkpoint.
	 *
	 * @param string              $migration_id Migration id.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return void
	 */
	public function save_checkpoint( string $migration_id, array $checkpoint ): void;

	/**
	 * Finish migration.
	 *
	 * @param string              $migration_id Migration id.
	 * @param string              $state State.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string|null         $error_code Error code.
	 * @param string              $completed_at_utc Completed at utc.
	 * @return void
	 */
	public function finish_migration(
		string $migration_id,
		string $state,
		array $checkpoint,
		?string $error_code,
		string $completed_at_utc
	): void;
}
