<?php
/**
 * Legacy migration rollback repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration rollback repository port interface.
 */
interface Legacy_Migration_Rollback_Repository_Port_Interface {
	/**
	 * Compensated receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Rollback_Legacy_Migration_Result|null
	 */
	public function compensated_receipt( string $storage_key, string $request_digest ): ?Rollback_Legacy_Migration_Result;

	/**
	 * Compensate.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Rollback_Legacy_Migration_Result
	 */
	public function compensate( array $record ): Rollback_Legacy_Migration_Result;
}
