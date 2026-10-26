<?php
/**
 * Legacy target batch lifecycle port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates non-transactional target resources with the caller-owned SQL batch.
 */
interface Legacy_Target_Batch_Lifecycle_Port_Interface {
	/**
	 * Begin batch.
	 *
	 * @return void
	 */
	public function begin_batch(): void;

	/**
	 * Commit batch.
	 *
	 * @return void
	 */
	public function commit_batch(): void;

	/**
	 * Rollback batch.
	 *
	 * @return void
	 */
	public function rollback_batch(): void;
}
